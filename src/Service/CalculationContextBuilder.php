<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Exception\CountryNotFoundException;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Baut den Kontext, in dem der Rechner einen Warenkorb für ein Zielland bewertet.
 *
 * Je Verkaufskanal entsteht ein Basiskontext einmal und wird je Berechnung geklont; die Kontextfabrik je Produkt und
 * Land zu rufen wäre teuer. Das Zielland samt Postleitzahl kommt über eine Referenzadresse hinein, nicht über die
 * Länderkennung der Fabrik: Die prüfte das Land gegen die Kanalkonfiguration und scheiterte bei Feed-Ländern, die der
 * Kanal selbst nicht führt.
 */
final class CalculationContextBuilder implements ResetInterface
{
    /** @var array<string, SalesChannelContext> In-Memory-Cache für Base-Contexts pro SalesChannel */
    private array $baseContextCache = [];

    /** @var array<string, ?CountryEntity> Country-Entity pro ISO (export-invariant, spart N+1) */
    private array $countryCache = [];

    /**
     * @param EntityRepository<CountryCollection> $countryRepository
     */
    public function __construct(
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly ShippingAddressProviderService $addressProvider,
        private readonly EntityRepository $countryRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ein Kontext des Kanals mit der Referenzadresse des Landes, oder `null`, wenn für das Land keine Adresse oder kein
     * Land hinterlegt ist oder sich die Adresse nicht einspielen lässt.
     */
    public function contextFor(string $salesChannelId, string $countryIso): ?SalesChannelContext
    {
        $context = $this->getOrCreateBaseContext($salesChannelId);

        return $this->injectShippingLocation($context, $countryIso) ? $context : null;
    }

    public function reset(): void
    {
        $this->baseContextCache = [];
        $this->countryCache = [];
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
            // Erwarteter Fall: ISO-Code ohne hinterlegte Referenzadresse; Fallback ist regulärer Pfad.
            $this->logger->info('No reference address configured for country, using fallback', [
                'countryIso' => $countryIso,
                'metric' => 'reference_address_missing',
            ]);

            return false;
        } catch (\Throwable $e) {
            // Unerwarteter Fehler im AddressProvider (DB, IO): separat loggen, damit Ops die Ursache sieht.
            $this->logger->error('Unexpected error in address provider', [
                'countryIso' => $countryIso,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'metric' => 'address_provider_error',
            ]);

            return false;
        }

        // Das Country-Entity je ISO ist während eines Exports konstant. Einmal pro ISO memoizen (N+1).
        $isoKey = strtoupper($countryIso);
        if (!\array_key_exists($isoKey, $this->countryCache)) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('iso', $isoKey));
            $criteria->setLimit(1);
            $found = $this->countryRepository->search($criteria, $context->getContext())->getEntities()->first();
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
}
