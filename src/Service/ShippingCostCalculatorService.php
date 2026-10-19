<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Berechnet Versandkosten für ein Produkt in ein bestimmtes Land.
 *
 * Erstellt einen temporären SalesChannelContext mit der Zieladresse des Landes,
 * berechnet einen virtuellen Warenkorb (um Shopware-Regeln zu evaluieren) und
 * sucht dann die günstigste anwendbare Versandmethode des Kanals.
 *
 * Die Verfügbarkeitsregeln der Versandmethoden werden nach der Warenkorb-Berechnung
 * gegen die gematchten Rule-IDs des Kontexts geprüft. So werden Methoden, die für
 * dieses Produkt nicht gelten (z.B. Paketdienst wenn nur Spedition erlaubt ist),
 * korrekt herausgefiltert.
 *
 * Bewusst nicht `final`: Tests ersetzen diese Klasse durch ein Test-Double, und PHPUnit kann finale
 * Klassen nicht nachbilden.
 */
class ShippingCostCalculatorService implements ResetInterface
{
    /** @var array<string, array<string, ShippingMethodEntity>> Aktive Versandmethoden (inkl. prices) pro SalesChannel (kanal-invariant, spart N+1 im Export/Warmup) */
    private array $shippingMethodsCache = [];


    /**
     * @param EntityRepository<ShippingMethodCollection> $shippingMethodRepository
     */
    public function __construct(
        private readonly VirtualCartBuilderService $cartBuilder,
        private readonly ShippingFallbackService $fallbackService,
        private readonly ShippingPriceStore $priceStore,
        private readonly ConfigurationService $configurationService,
        private readonly CalculationContextBuilder $contextBuilder,
        private readonly PriceTierSelector $priceTierSelector,
        private readonly EntityRepository $shippingMethodRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Warum die letzte Auswahl leer ausging, nur für das Protokoll.
     *
     * @var array{excluded: int, ruleMissed: int, noPriceTier: int, total: int}
     */
    private array $lastSelectionTrace = ['excluded' => 0, 'ruleMissed' => 0, 'noPriceTier' => 0, 'total' => 0];

    /**
     * Gibt die Versandkosten für ein Produkt in das angegebene Land zurück.
     *
     * Liegt ein Wert im Speicher, wird er sofort zurückgegeben. Andernfalls wird ein virtueller
     * Warenkorb berechnet, die Shopware-Regelauswertung läuft durch, und anschließend wird die
     * günstigste verfügbare Versandmethode des Kanals ermittelt.
     *
     * `$useStoredValue = false` überspringt den Speicher und rechnet in jedem Fall neu. Das ist
     * der Weg des Warmups. Mit Speicher-Kurzschluss füllte er nur Lücken, statt zu erneuern,
     * und ein Eintrag würde erst neu gerechnet, nachdem er verfallen ist. Bei einem Takt von sechs
     * Stunden und einer Haltbarkeit von 24 liefe der Feed dann täglich rund sechs Stunden lang
     * komplett auf dem Ersatzwert, also genau in dem Zustand, gegen den es diesen Speicher gibt.
     */
    public function calculate(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso = 'EUR',
        bool $useStoredValue = true,
        ?string $sourceHash = null,
    ): ShippingCalculationResult {
        if ($useStoredValue) {
            $cached = $this->priceStore->get($productId, $countryIso, $salesChannelId);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $context = $this->contextBuilder->contextFor($salesChannelId, $countryIso);

            // Das Zielland (inkl. PLZ) kommt ausschließlich über die Pseudo-Adresse.
            // Wir übergeben kein COUNTRY_ID an die Context-Factory; das Land wird
            // nach der Context-Erstellung per Reflection in die ShippingLocation injiziert.
            // So evaluieren PLZ-basierte Regeln (customerShippingZipCode) korrekt,
            // ohne dass die Factory die Sprach-/Länderkonfiguration des Kanals validiert.
            if ($context === null) {
                throw new \RuntimeException(sprintf('No reference address for country: %s', $countryIso));
            }

            // Warenkorb berechnen: Shopware evaluiert dabei alle Cart-Regeln
            // und schreibt die gematchten Rule-IDs in den Kontext. Der berechnete Warenkorb
            // liefert zusätzlich die Liefer-Kennwerte (Gewicht/Preis/Menge/Volumen), gegen die
            // die Preis-Tier-Bänder der Versandmethoden gematcht werden (wie im Core-DeliveryCalculator).
            $cart = $this->cartBuilder->buildCalculatedCart($productId, $context);

            // Shopware entfernt die Position, wenn sie sich nicht kaufen lässt: bei Hauptartikeln
            // mit Varianten immer (`ProductCartProcessor::validateParents`), dazu bei Closeout ohne
            // Bestand und bei Unsichtbarkeit im Berechnungs-Kanal. Ohne diese Prüfung rechnete das
            // Plugin danach mit einem leeren Warenkorb weiter: Gewicht, Preis und Menge sind
            // dann 0, und das billigste Preisband trifft immer. Ein 40-kg-Artikel bekäme so den
            // 0-5-kg-Preis, als gerechneter Wert, ohne Ersatzwert-Kennzeichnung und ohne Meldung.
            if (!$this->cartContainsProduct($cart, $productId)) {
                return $this->storeFallback(
                    $productId,
                    $countryIso,
                    $salesChannelId,
                    $currencyIso,
                    FallbackReason::NotPurchasable,
                    $sourceHash,
                );
            }

            $deliveryValues = $this->priceTierSelector->extractDeliveryValues($cart);

            // Nichts zu versenden: rein digitale Ware oder ausschließlich versandkostenfreie
            // Positionen. Der Kern setzt dafür 0,00 € an (`DeliveryCalculator`, Zeile 84-97);
            // das ist ein echtes Ergebnis und kein Ersatzwert.
            if ($deliveryValues === null) {
                $result = new ShippingCalculationResult($productId, $countryIso, 0.0, $currencyIso, false);
                $this->priceStore->set($productId, $countryIso, $salesChannelId, $result, $sourceHash);

                return $result;
            }

            $excluded = $this->configurationService->getExcludedShippingMethods($salesChannelId);
            $excludedIds = $this->configurationService->getExcludedShippingMethodIds($salesChannelId);
            $shippingCost = $this->findCheapestApplicableShippingCost(
                $salesChannelId,
                $context,
                $excluded,
                $excludedIds,
                $deliveryValues,
                $cart->getPrice()->getTaxStatus(),
            );

            // Kein Preis gefunden: Fallback verwenden.
            // 0,00 € ist ein gültiges Ergebnis (z.B. Gratis-Versand-Aktion) und wird NICHT durch Fallback ersetzt.
            // Selbstabholung (0,00 €) ist bereits über die Ausschlussliste abgedeckt.
            if ($shippingCost === null) {
                // Mit den Kennwerten, an denen es gescheitert ist. Ohne sie steht im Protokoll nur
                // „keine Versandart", und die Suche beginnt bei null: Die Verfügbarkeitsregeln
                // hängen meist an Gewicht, Länge und Warenwert der Position. Wer
                // die Zahlen nicht sieht, kann nicht unterscheiden, ob die Regel zu eng ist oder
                // die Position ihre Lieferangaben gar nicht mitbringt.
                $this->logger->info('RcProductFeedShipping: keine Versandart für diese Kombination.', [
                    'productId' => substr($productId, 0, 8),
                    'countryIso' => $countryIso,
                    'quantity' => $deliveryValues[DeliveryCalculator::CALCULATION_BY_LINE_ITEM_COUNT] ?? null,
                    'goodsPrice' => $deliveryValues[DeliveryCalculator::CALCULATION_BY_PRICE] ?? null,
                    'weight' => $deliveryValues[DeliveryCalculator::CALCULATION_BY_WEIGHT] ?? null,
                    'volume' => $deliveryValues[DeliveryCalculator::CALCULATION_BY_VOLUME] ?? null,
                    // Die Maße der Position getrennt: Die Regeln der Versandarten prüfen die
                    // Länge (`cartLineItemDimensionLength`), nicht das Volumen. Ein Volumen von
                    // 0 sagt nur, dass Breite oder Höhe fehlen; über die Länge sagt es nichts.
                    'dimensions' => $this->describeDimensions($cart),
                    'matchedRules' => \count($context->getRuleIds()),
                    'shippingMethods' => $this->lastSelectionTrace,
                    'metric' => 'no_shipping_method_detail',
                ]);

                return $this->storeFallback(
                    $productId,
                    $countryIso,
                    $salesChannelId,
                    $currencyIso,
                    FallbackReason::NoShippingMethod,
                    $sourceHash,
                );
            }

            $result = new ShippingCalculationResult($productId, $countryIso, $shippingCost, $currencyIso, false);
            $this->priceStore->set($productId, $countryIso, $salesChannelId, $result, $sourceHash);

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
                $sourceHash,
            );
        }
    }

    /** Ersatzwert bilden, ablegen und zurückgeben (an drei Stellen derselbe Ablauf). */
    private function storeFallback(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso,
        FallbackReason $reason,
        ?string $sourceHash = null,
    ): ShippingCalculationResult {
        $fallback = $this->fallbackService->getFallbackResult(
            $productId,
            $countryIso,
            $salesChannelId,
            $currencyIso,
            $reason,
        );
        $this->priceStore->set($productId, $countryIso, $salesChannelId, $fallback, $sourceHash);

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
     * Liest ein bereits berechnetes Ergebnis aus dem Speicher, ohne zu rechnen.
     *
     * Der Weg für das Feed-Rendering. Er muss sich vom rechnenden `calculate()` unterscheiden, weil
     * die Berechnung innerhalb einer Twig-Vorlage nicht laufen kann: Shopware sperrt während des
     * Renderns interne Entity-Felder, und die Warenkorb-Regelauswertung braucht mit
     * `RuleEntity::payload` genau so eines. Der Aufruf endete deshalb ausnahmslos in einer Ausnahme
     * und still im Ersatzwert.
     *
     * Gibt `null` zurück, wenn nichts vorliegt. Was dann passiert, entscheidet der Aufrufer. Er
     * muss es melden, nicht verschlucken.
     */
    public function lookupCached(string $productId, string $countryIso, string $salesChannelId): ?ShippingCalculationResult
    {
        return $this->priceStore->get($productId, $countryIso, $salesChannelId);
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
     * @param array<int, string> $excludedIds    Kennungen aus der Auswahl, kleingeschrieben
     * @param array<int, float>  $deliveryValues Liefer-Kennwerte je Calculation-Typ (Gewicht/Preis/Menge/Volumen)
     * @param string             $taxStatus      Steuerzustand des berechneten Warenkorbs
     */
    private function findCheapestApplicableShippingCost(
        string $salesChannelId,
        SalesChannelContext $context,
        array $excludedKeywords,
        array $excludedIds,
        array $deliveryValues,
        string $taxStatus,
    ): ?float {
        $matchedRuleIds = $context->getRuleIds();

        // Die aktiven Versandmethoden (inkl. prices) sind pro SalesChannel invariant. Beim Export/
        // Warmup über N Produkte x Länder wäre ein Neuladen je Produkt ein N+1. Einmal pro Kanal memoizen.
        if (!isset($this->shippingMethodsCache[$salesChannelId])) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('active', true));
            $criteria->addFilter(new EqualsFilter('salesChannels.id', $salesChannelId));
            $criteria->addAssociation('prices');

            $this->shippingMethodsCache[$salesChannelId] = $this->shippingMethodRepository
                ->search($criteria, $context->getContext())
                ->getEntities()
                ->getElements();

            $this->warnAboutVanishedExclusions($salesChannelId, $excludedIds, $context->getContext());
        }

        $methods = $this->shippingMethodsCache[$salesChannelId];

        $lowestCost = null;

        // Warum keine Versandart übrig blieb, lässt sich hinterher nicht mehr rekonstruieren:
        // Der Aufrufer sieht nur `null`. Die Zählung hält fest, an welcher der drei Hürden die
        // Versandarten hängengeblieben sind: Ausschlussliste, Verfügbarkeitsregel oder Preisstaffel.
        $this->lastSelectionTrace = ['excluded' => 0, 'ruleMissed' => 0, 'noPriceTier' => 0, 'total' => \count($methods)];

        foreach ($methods as $method) {
            /** @var ShippingMethodEntity $method */
            $methodName = $method->getTranslated()['name'] ?? $method->getName() ?? '';

            if (\in_array(strtolower($method->getId()), $excludedIds, true)
                || $this->isExcluded($methodName, $excludedKeywords)) {
                ++$this->lastSelectionTrace['excluded'];
                continue;
            }

            $availabilityRuleId = $method->getAvailabilityRuleId();
            if ($availabilityRuleId !== null && !in_array($availabilityRuleId, $matchedRuleIds, true)) {
                ++$this->lastSelectionTrace['ruleMissed'];
                continue;
            }

            $cost = $this->priceTierSelector->resolveApplicablePriceTier($method, $matchedRuleIds, $context, $deliveryValues, $taxStatus);
            if ($cost === null) {
                ++$this->lastSelectionTrace['noPriceTier'];
                continue;
            }

            if ($lowestCost === null || $cost < $lowestCost) {
                $lowestCost = $cost;
            }
        }

        return $lowestCost;
    }



    /**
     * Die Maße der ersten Produktposition, wie sie im Warenkorb ankommen.
     *
     * Nur für das Protokoll: Wer wissen will, warum eine Regel auf `cartLineItemDimensionLength`
     * nicht zutrifft, braucht die Länge und die Auskunft, ob überhaupt Lieferangaben an der
     * Position hängen.
     */
    private function describeDimensions(Cart $cart): string
    {
        foreach ($cart->getLineItems() as $lineItem) {
            $information = $lineItem->getDeliveryInformation();
            if ($information === null) {
                continue;
            }

            return sprintf(
                'L=%s B=%s H=%s G=%s',
                $information->getLength() ?? 'null',
                $information->getWidth() ?? 'null',
                $information->getHeight() ?? 'null',
                $information->getWeight() ?? 'null',
            );
        }

        return 'keine Lieferangaben an der Position';
    }





    /**
     * Meldet ausgewählte Versandarten, die es nicht mehr gibt, einmal je Kanal und Lauf.
     *
     * Abbrechen wäre falsch: Eine gelöschte Versandart kann ohnehin nicht mehr gewinnen, der
     * Ausschluss hat sein Ziel also erreicht. Schweigen wäre ebenso falsch, denn wer die Auswahl
     * pflegt, soll die Leiche darin finden. Gefragt wird nur nach den Kennungen, die unter den
     * aktiven Versandarten des Kanals fehlen; eine inaktive oder dem Kanal nicht zugeordnete
     * Versandart gibt es noch und ist kein Fall für das Protokoll.
     *
     * @param array<int, string> $excludedIds
     */
    private function warnAboutVanishedExclusions(string $salesChannelId, array $excludedIds, Context $context): void
    {
        $known = [];
        foreach ($this->shippingMethodsCache[$salesChannelId] ?? [] as $method) {
            $known[strtolower($method->getId())] = true;
        }

        $unknown = array_values(array_filter($excludedIds, static fn (string $id): bool => !isset($known[$id])));
        if ($unknown === []) {
            return;
        }

        $existing = array_map('strtolower', $this->shippingMethodRepository->searchIds(new Criteria($unknown), $context)->getIds());
        $vanished = array_values(array_diff($unknown, $existing));

        if ($vanished !== []) {
            $this->logger->warning('Excluded shipping methods no longer exist and are skipped', [
                'salesChannelId' => $salesChannelId,
                'shippingMethodIds' => $vanished,
            ]);
        }
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

    /**
     * Leert die Zwischenspeicher zwischen zwei Nachrichten eines langlebigen Workers (`kernel.reset`). Sonst rechnete der
     * nächste Warmup mit Versandarten, Ländern und Einstellungen aus dem Lauf davor.
     */
    public function reset(): void
    {
        $this->shippingMethodsCache = [];
    }
}
