<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use RuhrCoder\RcProductFeedShippingExtension\Cache\ShippingCacheService;
use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Berechnet Versandkosten für ein Produkt in ein bestimmtes Land.
 *
 * Erstellt einen temporären SalesChannelContext mit der Zieladresse des Landes,
 * berechnet einen virtuellen Warenkorb (um Shopware-Regeln zu evaluieren) und
 * sucht dann die günstigste anwendbare Versandmethode des Kanals.
 *
 * Die Verfügbarkeitsregeln der Versandmethoden werden nach der Warenkorb-Berechnung
 * gegen die gematchten Rule-IDs des Kontexts geprüft — so werden Methoden, die für
 * dieses Produkt nicht gelten (z.B. Paketdienst wenn nur Spedition erlaubt ist),
 * korrekt herausgefiltert.
 */
class ShippingCostCalculatorService
{
    public function __construct(
        private readonly VirtualCartBuilderService $cartBuilder,
        private readonly ShippingFallbackService $fallbackService,
        private readonly ShippingCacheService $cacheService,
        private readonly ConfigurationService $configurationService,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ShippingAddressProviderService $addressProvider,
        private readonly EntityRepository $countryRepository,
        private readonly EntityRepository $shippingMethodRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Gibt die Versandkosten für ein Produkt in das angegebene Land zurück.
     *
     * Trifft der Cache, wird das gecachte Ergebnis sofort zurückgegeben. Andernfalls
     * wird ein virtueller Warenkorb berechnet, die Shopware-Regelauswertung läuft durch,
     * und anschließend wird die günstigste verfügbare Versandmethode des Kanals ermittelt.
     */
    public function calculate(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso = 'EUR',
    ): ShippingCalculationResult {
        $cached = $this->cacheService->get($productId, $countryIso, $salesChannelId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $context = $this->contextFactory->create(
                Uuid::randomHex(),
                $salesChannelId,
                [],
            );

            // Das Zielland (inkl. PLZ) kommt ausschließlich über die Pseudo-Adresse.
            // Wir übergeben kein COUNTRY_ID an die Context-Factory — das Land wird
            // nach der Context-Erstellung per Reflection in die ShippingLocation injiziert.
            // So evaluieren PLZ-basierte Regeln (customerShippingZipCode) korrekt,
            // ohne dass die Factory die Sprach-/Länderkonfiguration des Kanals validiert.
            if (!$this->injectShippingLocation($context, $countryIso)) {
                throw new \RuntimeException(sprintf('No reference address for country: %s', $countryIso));
            }

            // Warenkorb berechnen — Shopware evaluiert dabei alle Cart-Regeln
            // und schreibt die gematchten Rule-IDs in den Kontext.
            $this->cartBuilder->buildCalculatedCart($productId, $context);

            $excluded = $this->configurationService->getExcludedShippingMethods($salesChannelId);
            $shippingCost = $this->findCheapestApplicableShippingCost($salesChannelId, $context, $excluded);

            // Keine Versandmethode verfügbar — Fallback verwenden statt 0,00 € in den Feed zu schreiben.
            if ($shippingCost === null) {
                $fallback = $this->fallbackService->getFallbackResult($productId, $countryIso, $salesChannelId, $currencyIso);
                $this->cacheService->set($productId, $countryIso, $salesChannelId, $fallback);

                return $fallback;
            }

            $result = new ShippingCalculationResult($productId, $countryIso, $shippingCost, $currencyIso, false);
            $this->cacheService->set($productId, $countryIso, $salesChannelId, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Shipping calculation failed', [
                'productId' => substr($productId, 0, 8),
                'countryIso' => $countryIso,
                'error' => $e->getMessage(),
            ]);

            $fallback = $this->fallbackService->getFallbackResult($productId, $countryIso, $salesChannelId, $currencyIso);
            $this->cacheService->set($productId, $countryIso, $salesChannelId, $fallback);

            return $fallback;
        }
    }

    /**
     * Lädt alle aktiven Versandmethoden des Kanals und gibt die günstigste zurück,
     * die für den aktuellen Kontext verfügbar ist.
     *
     * Methoden werden übersprungen wenn sie auf der Ausschlussliste stehen (z.B. Selbstabholung)
     * oder wenn ihre Availabilityregel nicht zu den gematchten Rule-IDs des Kontexts passt.
     * Unter den verbleibenden Methoden gewinnt der niedrigste anwendbare Preis-Tier.
     */
    private function findCheapestApplicableShippingCost(
        string $salesChannelId,
        SalesChannelContext $context,
        array $excludedKeywords,
    ): ?float {
        $matchedRuleIds = $context->getRuleIds();
        $currencyId = $context->getCurrency()->getId();

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
        $criteria->addAssociation('prices');

        $methods = $this->shippingMethodRepository
            ->search($criteria, $context->getContext())
            ->getElements();

        $lowestCost = null;

        foreach ($methods as $method) {
            /** @var ShippingMethodEntity $method */
            $methodName = $method->getTranslated()['name'] ?? $method->getName() ?? '';

            if ($this->isExcluded($methodName, $excludedKeywords)) {
                continue;
            }

            $availabilityRuleId = $method->getAvailabilityRuleId();
            if ($availabilityRuleId !== null && !in_array($availabilityRuleId, $matchedRuleIds, true)) {
                continue;
            }

            $cost = $this->resolveApplicablePriceTier($method, $matchedRuleIds, $currencyId);
            if ($cost !== null && ($lowestCost === null || $cost < $lowestCost)) {
                $lowestCost = $cost;
            }
        }

        return $lowestCost;
    }

    /**
     * Gibt den günstigsten anwendbaren Preis-Tier einer Versandmethode zurück.
     *
     * Preis-Tiers mit einer matchenden Regel haben Vorrang vor Tier ohne Regel.
     * Unter mehreren matchenden Tiers wird der günstigste gewählt.
     */
    private function resolveApplicablePriceTier(
        ShippingMethodEntity $method,
        array $matchedRuleIds,
        string $currencyId,
    ): ?float {
        $prices = $method->getPrices();
        if ($prices === null || $prices->count() === 0) {
            return null;
        }

        $ruleBasedCost = null;
        $defaultCost = null;

        foreach ($prices as $price) {
            /** @var ShippingMethodPriceEntity $price */
            $priceRuleId = $price->getRuleId();

            if ($priceRuleId !== null && !in_array($priceRuleId, $matchedRuleIds, true)) {
                continue;
            }

            $gross = $this->extractGrossPrice($price->getCurrencyPrice(), $currencyId);
            if ($gross === null) {
                continue;
            }

            if ($priceRuleId !== null) {
                if ($ruleBasedCost === null || $gross < $ruleBasedCost) {
                    $ruleBasedCost = $gross;
                }
            } elseif ($defaultCost === null || $gross < $defaultCost) {
                $defaultCost = $gross;
            }
        }

        // Regelbasierter Preis hat Vorrang vor Standardpreis
        return $ruleBasedCost ?? $defaultCost;
    }

    /**
     * Extrahiert den Brutto-Preis aus einer PriceCollection für die angegebene Währung.
     *
     * Fällt automatisch auf den ersten verfügbaren Preis zurück wenn die Währung
     * nicht direkt gefunden wird.
     */
    private function extractGrossPrice(?PriceCollection $currencyPrices, string $currencyId): ?float
    {
        if ($currencyPrices === null || $currencyPrices->count() === 0) {
            return null;
        }

        $price = $currencyPrices->getCurrencyPrice($currencyId) ?? $currencyPrices->first();

        return $price?->getGross();
    }

    /**
     * Injiziert eine Pseudo-Adresse (mit PLZ und Land) in den SalesChannelContext.
     *
     * Setzt sowohl die ShippingLocation als auch einen minimalen Customer mit
     * aktiver Versandadresse. Beides ist nötig: ShippingLocation für allgemeine
     * Adress-Auswertung, Customer für customerShippingZipCode-Regelkondition,
     * die intern $context->getCustomer()->getActiveShippingAddress() aufruft.
     *
     * Gibt false zurück wenn kein Referenzeintrag für das Land vorhanden ist.
     */
    private function injectShippingLocation(SalesChannelContext $context, string $countryIso): bool
    {
        try {
            $referenceAddress = $this->addressProvider->getReferenceAddress($countryIso);
        } catch (\Throwable) {
            return false;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', strtoupper($countryIso)));
        $criteria->setLimit(1);
        $country = $this->countryRepository->search($criteria, Context::createDefaultContext())->first();

        if ($country === null) {
            return false;
        }

        $addressId = Uuid::randomHex();

        $address = new CustomerAddressEntity();
        $address->setId($addressId);
        $address->setFirstName('-');
        $address->setLastName('-');
        $address->setZipcode($referenceAddress->zipCode);
        $address->setCity($referenceAddress->city);
        $address->setStreet($referenceAddress->street);
        $address->setCountryId($country->getId());
        $address->setCountry($country);

        // ShippingLocation injizieren
        $shippingLocation = ShippingLocation::createFromAddress($address);
        $refLocation = new \ReflectionProperty(SalesChannelContext::class, 'shippingLocation');
        $refLocation->setValue($context, $shippingLocation);

        // Minimalen Customer mit aktiver Versandadresse injizieren, damit
        // customerShippingZipCode-Regeln die PLZ auswerten können.
        $customer = new CustomerEntity();
        $customer->setId(Uuid::randomHex());
        $customer->setAccountType(CustomerEntity::ACCOUNT_TYPE_PRIVATE);
        $customer->setActiveShippingAddress($address);
        $customer->setActiveBillingAddress($address);

        $refCustomer = new \ReflectionProperty(SalesChannelContext::class, 'customer');
        $refCustomer->setValue($context, $customer);

        return true;
    }

    private function isExcluded(string $methodName, array $excludedKeywords): bool
    {
        if (empty($excludedKeywords)) {
            return false;
        }

        $methodNameLower = strtolower($methodName);

        foreach ($excludedKeywords as $keyword) {
            if (str_contains($methodNameLower, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }
}
