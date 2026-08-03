<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Exception\CountryNotFoundException;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
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
    /** @var array<string, SalesChannelContext> In-Memory-Cache für Base-Contexts pro SalesChannel */
    private array $baseContextCache = [];

    /** @var array<string, array<string, ShippingMethodEntity>> Aktive Versandmethoden (inkl. prices) pro SalesChannel — kanal-invariant, spart N+1 im Export/Warmup */
    private array $shippingMethodsCache = [];

    /** @var array<string, ?CountryEntity> Country-Entity pro ISO — export-invariant, spart N+1 */
    private array $countryCache = [];

    /**
     * @param EntityRepository<CountryCollection>        $countryRepository
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     */
    public function __construct(
        private readonly VirtualCartBuilderService $cartBuilder,
        private readonly ShippingFallbackService $fallbackService,
        private readonly ShippingPriceStore $priceStore,
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
     * Liegt ein Wert im Speicher, wird er sofort zurückgegeben. Andernfalls wird ein virtueller
     * Warenkorb berechnet, die Shopware-Regelauswertung läuft durch, und anschließend wird die
     * günstigste verfügbare Versandmethode des Kanals ermittelt.
     *
     * `$useStoredValue = false` überspringt den Speicher und rechnet in jedem Fall neu. **Das ist
     * der Weg des Warmups.** Vorher rief auch er die Methode mit Speicher-Kurzschluss auf und
     * füllte damit nur Lücken, statt zu erneuern: Ein Eintrag wurde erst neu gerechnet, nachdem
     * er verfallen war. Bei einem Takt von sechs Stunden und einer Haltbarkeit von 24 lief der
     * Feed dadurch täglich rund sechs Stunden lang komplett auf dem Ersatzwert — der Zustand,
     * gegen den es diesen Speicher gibt.
     */
    public function calculate(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso = 'EUR',
        bool $useStoredValue = true,
    ): ShippingCalculationResult {
        if ($useStoredValue) {
            $cached = $this->priceStore->get($productId, $countryIso, $salesChannelId);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $context = $this->getOrCreateBaseContext($salesChannelId);

            // Das Zielland (inkl. PLZ) kommt ausschließlich über die Pseudo-Adresse.
            // Wir übergeben kein COUNTRY_ID an die Context-Factory — das Land wird
            // nach der Context-Erstellung per Reflection in die ShippingLocation injiziert.
            // So evaluieren PLZ-basierte Regeln (customerShippingZipCode) korrekt,
            // ohne dass die Factory die Sprach-/Länderkonfiguration des Kanals validiert.
            if (!$this->injectShippingLocation($context, $countryIso)) {
                throw new \RuntimeException(sprintf('No reference address for country: %s', $countryIso));
            }

            // Warenkorb berechnen — Shopware evaluiert dabei alle Cart-Regeln
            // und schreibt die gematchten Rule-IDs in den Kontext. Der berechnete Warenkorb
            // liefert zusätzlich die Liefer-Kennwerte (Gewicht/Preis/Menge/Volumen), gegen die
            // die Preis-Tier-Bänder der Versandmethoden gematcht werden (wie im Core-DeliveryCalculator).
            $cart = $this->cartBuilder->buildCalculatedCart($productId, $context);

            // Shopware entfernt die Position, wenn sie sich nicht kaufen lässt — bei Hauptartikeln
            // mit Varianten immer (`ProductCartProcessor::validateParents`), dazu bei Closeout ohne
            // Bestand und bei Unsichtbarkeit im Berechnungs-Kanal. Ohne diese Prüfung rechnete das
            // Plugin danach mit einem **leeren** Warenkorb weiter: Gewicht, Preis und Menge sind
            // dann 0, und das billigste Preisband trifft immer. Ein 40-kg-Artikel bekam so den
            // 0-5-kg-Preis — als gerechneter Wert, ohne Ersatzwert-Kennzeichnung und ohne Meldung.
            if (!$this->cartContainsProduct($cart, $productId)) {
                return $this->storeFallback(
                    $productId,
                    $countryIso,
                    $salesChannelId,
                    $currencyIso,
                    FallbackReason::NotPurchasable,
                );
            }

            $deliveryValues = $this->extractDeliveryValues($cart);

            // Nichts zu versenden: rein digitale Ware oder ausschließlich versandkostenfreie
            // Positionen. Der Kern setzt dafür 0,00 € an (`DeliveryCalculator`, Zeile 84-97) —
            // das ist ein echtes Ergebnis und kein Ersatzwert.
            if ($deliveryValues === null) {
                $result = new ShippingCalculationResult($productId, $countryIso, 0.0, $currencyIso, false);
                $this->priceStore->set($productId, $countryIso, $salesChannelId, $result);

                return $result;
            }

            $excluded = $this->configurationService->getExcludedShippingMethods($salesChannelId);
            $shippingCost = $this->findCheapestApplicableShippingCost(
                $salesChannelId,
                $context,
                $excluded,
                $deliveryValues,
                $cart->getPrice()->getTaxStatus(),
            );

            // Kein Preis gefunden — Fallback verwenden.
            // 0,00 € ist ein gültiges Ergebnis (z.B. Gratis-Versand-Aktion) und wird NICHT durch Fallback ersetzt.
            // Selbstabholung (0,00 €) ist bereits über die Ausschlussliste abgedeckt.
            if ($shippingCost === null) {
                return $this->storeFallback(
                    $productId,
                    $countryIso,
                    $salesChannelId,
                    $currencyIso,
                    FallbackReason::NoShippingMethod,
                );
            }

            $result = new ShippingCalculationResult($productId, $countryIso, $shippingCost, $currencyIso, false);
            $this->priceStore->set($productId, $countryIso, $salesChannelId, $result);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->error('Shipping calculation failed', [
                'productId' => substr($productId, 0, 8),
                'countryIso' => $countryIso,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'metric' => 'calculation_fallback_used',
            ]);

            return $this->storeFallback(
                $productId,
                $countryIso,
                $salesChannelId,
                $currencyIso,
                FallbackReason::CalculationFailed,
            );
        }
    }

    /** Ersatzwert bilden, ablegen und zurückgeben — an drei Stellen derselbe Ablauf. */
    private function storeFallback(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso,
        FallbackReason $reason,
    ): ShippingCalculationResult {
        $fallback = $this->fallbackService->getFallbackResult(
            $productId,
            $countryIso,
            $salesChannelId,
            $currencyIso,
            $reason,
        );
        $this->priceStore->set($productId, $countryIso, $salesChannelId, $fallback);

        return $fallback;
    }

    /**
     * Ist die eingelegte Position nach der Berechnung noch da?
     *
     * Geprüft wird auf die Produkt-ID, nicht auf „irgendeine Position": Ein Warenkorb kann durch
     * Aktionen oder Zugaben auch dann gefüllt sein, wenn genau dieser Artikel entfernt wurde.
     */
    private function cartContainsProduct(Cart $cart, string $productId): bool
    {
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if ($lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE
                && $lineItem->getReferencedId() === $productId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liest ein bereits berechnetes Ergebnis aus dem Speicher — **ohne** zu rechnen.
     *
     * Der Weg für das Feed-Rendering. Er muss sich vom rechnenden `calculate()` unterscheiden, weil
     * die Berechnung innerhalb einer Twig-Vorlage nicht laufen kann: Shopware sperrt während des
     * Renderns interne Entity-Felder, und die Warenkorb-Regelauswertung braucht mit
     * `RuleEntity::payload` genau so eines. Der Aufruf endete deshalb ausnahmslos in einer Ausnahme
     * und still im Ersatzwert.
     *
     * Gibt `null` zurück, wenn nichts vorliegt. Was dann passiert, entscheidet der Aufrufer — er
     * muss es report, nicht verschlucken.
     */
    public function lookupCached(string $productId, string $countryIso, string $salesChannelId): ?ShippingCalculationResult
    {
        return $this->priceStore->get($productId, $countryIso, $salesChannelId);
    }

    /**
     * Gibt einen geklonten Base-Context für den SalesChannel zurück.
     *
     * Der Base-Context wird pro SalesChannel einmalig erstellt und dann für jede
     * Berechnung geklont. Das vermeidet teure Context-Factory-Aufrufe pro Produkt/Land.
     */
    private function getOrCreateBaseContext(string $salesChannelId): SalesChannelContext
    {
        if (!isset($this->baseContextCache[$salesChannelId])) {
            $this->baseContextCache[$salesChannelId] = $this->contextFactory->create(
                Uuid::randomHex(),
                $salesChannelId,
                [],
            );
        }

        return clone $this->baseContextCache[$salesChannelId];
    }

    /**
     * Lädt alle aktiven Versandmethoden des Kanals und gibt die günstigste zurück,
     * die für den aktuellen Kontext verfügbar ist.
     *
     * Methoden werden übersprungen wenn sie auf der Ausschlussliste stehen (z.B. Selbstabholung)
     * oder wenn ihre Availabilityregel nicht zu den gematchten Rule-IDs des Kontexts passt.
     * Unter den verbleibenden Methoden gewinnt der niedrigste anwendbare Preis-Tier.
     *
     * @param array<int, string> $excludedKeywords
     * @param array<int, float>  $deliveryValues Liefer-Kennwerte je Calculation-Typ (Gewicht/Preis/Menge/Volumen)
     * @param string             $taxStatus      Steuerzustand des berechneten Warenkorbs
     */
    private function findCheapestApplicableShippingCost(
        string $salesChannelId,
        SalesChannelContext $context,
        array $excludedKeywords,
        array $deliveryValues,
        string $taxStatus,
    ): ?float {
        $matchedRuleIds = $context->getRuleIds();

        // Die aktiven Versandmethoden (inkl. prices) sind pro SalesChannel invariant — beim Export/
        // Warmup über N Produkte x Länder wäre ein Neuladen je Produkt ein N+1. Einmal pro Kanal memoizen.
        if (!isset($this->shippingMethodsCache[$salesChannelId])) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
            $criteria->addAssociation('prices');

            $this->shippingMethodsCache[$salesChannelId] = $this->shippingMethodRepository
                ->search($criteria, $context->getContext())
                ->getElements();
        }

        $methods = $this->shippingMethodsCache[$salesChannelId];

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

            $cost = $this->resolveApplicablePriceTier($method, $matchedRuleIds, $context, $deliveryValues, $taxStatus);
            if ($cost !== null && ($lowestCost === null || $cost < $lowestCost)) {
                $lowestCost = $cost;
            }
        }

        return $lowestCost;
    }

    /**
     * Gibt den anwendbaren Preis-Tier einer Versandmethode zurück.
     *
     * Ein Tier ist nur anwendbar, wenn (a) seine Verfügbarkeits-Regel (`ruleId`) zum Kontext passt UND
     * (b) sein Mengen-/Gewichts-/Preis-Band (`quantityStart`..`quantityEnd`, je `calculation`-Typ) den
     * tatsächlichen Warenkorb-Kennwert enthält bzw. seine `calculationRuleId` matcht — analog zum
     * Core-`DeliveryCalculator::matches()`. Vorher wurde fälschlich das Minimum über ALLE Tiers genommen,
     * wodurch z. B. ein 15-kg-Produkt den 0–5-kg-Preis (statt des 5–30-kg-Preises) im Feed erhielt.
     *
     * **Die Reihenfolge der Regeln entscheidet, nicht ihr Preis.** Der Kern geht die Regeln des
     * Kontexts der Reihe nach durch — und die sind nach Priorität absteigend sortiert — und bricht
     * bei der ersten ab, die einen passenden Preis hat (`DeliveryCalculator`, Zeile 107-116;
     * `RuleLoader`, Zeile 38-40). Das Plugin nahm dagegen den billigsten Preis über alle passenden
     * Regeln hinweg. Bei „Sperrgut" (hohe Priorität, 49,90 €) neben „Standardkunde" (niedrige,
     * 5,90 €) verlangte die Kasse 49,90 € und der Feed nannte 5,90 €.
     *
     * Innerhalb **einer** Regel gewinnt weiterhin der günstigste passende Preis — auch das ist der
     * Kern (`getMatchingPriceOfRule` sortiert aufsteigend und nimmt den ersten Treffer).
     *
     * @param array<int, string> $matchedRuleIds nach Regel-Priorität absteigend sortiert
     * @param array<int, float>  $deliveryValues
     */
    private function resolveApplicablePriceTier(
        ShippingMethodEntity $method,
        array $matchedRuleIds,
        SalesChannelContext $context,
        array $deliveryValues,
        string $taxStatus,
    ): ?float {
        $prices = $method->getPrices();
        if ($prices->count() === 0) {
            return null;
        }

        foreach ($matchedRuleIds as $ruleId) {
            $cost = $this->cheapestMatchingPrice($prices, $ruleId, $context, $matchedRuleIds, $deliveryValues, $taxStatus);
            if ($cost !== null) {
                return $cost;
            }
        }

        // Keine Regel hat gegriffen — jetzt erst der Standardpreis.
        return $this->cheapestMatchingPrice($prices, null, $context, $matchedRuleIds, $deliveryValues, $taxStatus);
    }

    /**
     * Günstigster passender Preis unter denen, die genau zu `$ruleId` gehören.
     *
     * @param iterable<ShippingMethodPriceEntity> $prices
     * @param array<int, string>                  $matchedRuleIds
     * @param array<int, float>                   $deliveryValues
     */
    private function cheapestMatchingPrice(
        iterable $prices,
        ?string $ruleId,
        SalesChannelContext $context,
        array $matchedRuleIds,
        array $deliveryValues,
        string $taxStatus,
    ): ?float {
        $cheapest = null;

        foreach ($prices as $price) {
            if ($price->getRuleId() !== $ruleId) {
                continue;
            }

            if (!$this->priceTierMatches($price, $deliveryValues, $matchedRuleIds)) {
                continue;
            }

            $value = $this->extractPriceForTaxState($price->getCurrencyPrice(), $context, $taxStatus);
            if ($value === null) {
                continue;
            }

            if ($cheapest === null || $value < $cheapest) {
                $cheapest = $value;
            }
        }

        return $cheapest;
    }

    /**
     * Liest die für das Preis-Tier-Matching relevanten Kennwerte aus dem berechneten Warenkorb —
     * dieselben Werte, die der Core-DeliveryCalculator gegen die Bänder prüft.
     *
     * `null` heißt: Es ist nichts zu versenden. Das ist etwas anderes als „alle Kennwerte sind 0".
     * Früher lieferte die Methode in diesem Fall lauter Nullen zurück, und weil jedes Preisband bei
     * 0 beginnt, traf danach das billigste — ein erfundener Preis. Der Aufrufer setzt für `null`
     * jetzt 0,00 € an, wie es der Kern auch tut.
     *
     * @return array<int, float>|null
     */
    private function extractDeliveryValues(Cart $cart): ?array
    {
        $delivery = $cart->getDeliveries()->first();
        $positions = $delivery?->getPositions()->getWithoutDeliveryFree();

        if ($positions === null || $positions->count() === 0) {
            return null;
        }

        return [
            DeliveryCalculator::CALCULATION_BY_LINE_ITEM_COUNT => (float) $positions->getQuantity(),
            DeliveryCalculator::CALCULATION_BY_PRICE => $positions->getPrices()->getTotalPriceAmount(),
            DeliveryCalculator::CALCULATION_BY_WEIGHT => $positions->getWeight(),
            DeliveryCalculator::CALCULATION_BY_VOLUME => $positions->getVolume(),
        ];
    }

    /**
     * Prüft, ob ein Preis-Tier auf den Warenkorb anwendbar ist — Nachbildung von
     * Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator::matches(): entweder matcht die
     * calculationRuleId, oder der Kennwert des jeweiligen Calculation-Typs liegt im Band
     * [quantityStart, quantityEnd] (Start inklusiv, Ende inklusiv).
     *
     * @param array<int, float>  $deliveryValues
     * @param array<int, string> $matchedRuleIds
     */
    private function priceTierMatches(
        ShippingMethodPriceEntity $price,
        array $deliveryValues,
        array $matchedRuleIds,
    ): bool {
        $calculationRuleId = $price->getCalculationRuleId();
        if ($calculationRuleId !== null) {
            return in_array($calculationRuleId, $matchedRuleIds, true);
        }

        $calculation = $price->getCalculation();
        $value = $deliveryValues[$calculation] ?? ($deliveryValues[DeliveryCalculator::CALCULATION_BY_PRICE] ?? 0.0);

        $start = $price->getQuantityStart();
        $end = $price->getQuantityEnd();

        $aboveStart = $start === null || FloatComparator::greaterThanOrEquals($value, $start);
        $belowEnd = $end === null || FloatComparator::lessThanOrEquals($value, $end);

        return $aboveStart && $belowEnd;
    }

    /**
     * Der Preis, den die Kasse für diesen Kontext ansetzen würde.
     *
     * **Brutto ist nicht immer richtig.** Der Kern nimmt nur bei `TAX_STATE_GROSS` den
     * Bruttopreis, sonst den Nettopreis (`DeliveryCalculator::getPriceForTaxState`, Zeile
     * 222-229). Für die Schweiz — eines der voreingestellten Länder — setzt der Kern über
     * `country.customerTax` regulär `TAX_STATE_FREE`; das Plugin nannte dort bisher den
     * Bruttopreis, während die Kasse netto abrechnete.
     *
     * **Er steht im Warenkorb, nicht im Kontext.** `CartRuleLoader::validateTaxFree()` setzt den
     * steuerfreien Zustand nur für die Dauer der Neuberechnung und **stellt ihn danach zurück**
     * (Kern 6.7.12.1, Zeile 325-345). Wer den Kontext hinterher fragt, bekommt wieder
     * `TAX_STATE_GROSS` — gemessen auf live-clone bei `customerTax.enabled = true` für die
     * Schweiz. Der berechnete Warenkorb trägt den tatsächlich verwendeten Zustand.
     *
     * Der Währungsfaktor gilt nur für den Preis in der Standardwährung: Er ist der Umrechnungs-
     * kurs, mit dem ein nicht gepflegter Fremdwährungspreis abgeleitet wird. Ein eigens
     * gepflegter Preis in der Zielwährung wird nicht noch einmal umgerechnet.
     */
    private function extractPriceForTaxState(
        ?PriceCollection $currencyPrices,
        SalesChannelContext $context,
        string $taxStatus,
    ): ?float {
        if ($currencyPrices === null || $currencyPrices->count() === 0) {
            return null;
        }

        $price = $currencyPrices->getCurrencyPrice($context->getCurrency()->getId()) ?? $currencyPrices->first();
        if ($price === null) {
            return null;
        }

        $value = $taxStatus === CartPrice::TAX_STATE_GROSS
            ? $price->getGross()
            : $price->getNet();

        if ($price->getCurrencyId() === Defaults::CURRENCY) {
            $value *= $context->getContext()->getCurrencyFactor();
        }

        return $value;
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
        } catch (CountryNotFoundException $e) {
            // Erwarteter Fall: ISO-Code ohne hinterlegte Referenzadresse — Fallback ist regulärer Pfad.
            $this->logger->info('No reference address configured for country, using fallback', [
                'countryIso' => $countryIso,
                'metric' => 'reference_address_missing',
            ]);

            return false;
        } catch (\Throwable $e) {
            // Unerwarteter Fehler im AddressProvider (DB, IO) — separat loggen, damit Ops die Ursache sieht.
            $this->logger->error('Unexpected error in address provider', [
                'countryIso' => $countryIso,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'metric' => 'address_provider_error',
            ]);

            return false;
        }

        // Das Country-Entity je ISO ist während eines Exports konstant — einmal pro ISO memoizen (N+1).
        $isoKey = strtoupper($countryIso);
        if (!\array_key_exists($isoKey, $this->countryCache)) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $isoKey));
            $criteria->setLimit(1);
            $found = $this->countryRepository->search($criteria, Context::createDefaultContext())->first();
            // first() liefert ?Entity; nur ein echtes CountryEntity ist an setCountry() übergebbar.
            $this->countryCache[$isoKey] = $found instanceof CountryEntity ? $found : null;
        }

        $country = $this->countryCache[$isoKey];

        if (!$country instanceof CountryEntity) {
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

        // ShippingLocation und Customer per Reflection injizieren.
        // Shopware bietet keine öffentliche API um die ShippingLocation nachträglich zu setzen,
        // und COUNTRY_ID über die Factory validiert das Land gegen die Kanalkonfiguration,
        // was bei Feeds für nicht-konfigurierte Länder fehlschlägt.
        // Guard: Bei Shopware-Updates, die diese Properties umbenennen, wird ein klarer Fehler geloggt.
        try {
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
        } catch (\ReflectionException $e) {
            $this->logger->error('Reflection-Zugriff auf SalesChannelContext fehlgeschlagen — Shopware-API-Änderung?', [
                'property' => $e->getMessage(),
                'shopwareVersion' => \Shopware\Core\Kernel::SHOPWARE_FALLBACK_VERSION,
            ]);

            return false;
        }

        return true;
    }

    /** @param array<int, string> $excludedKeywords */
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
