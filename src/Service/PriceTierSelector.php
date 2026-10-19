<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\Util\FloatComparator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Wählt den Preis einer Versandart für einen berechneten Warenkorb, so wie der `DeliveryCalculator` des Kerns: das
 * Preisband, in das Gewicht, Preis, Menge oder Volumen fallen, mit Regelpreisen vor dem Standardpreis und dem Netto- oder
 * Bruttowert je nach Steuerzustand.
 *
 * Reine Rechnung ohne Zustand und ohne Abhängigkeiten; der Rechner kümmert sich um Warenkorb, Versandarten und Speicher.
 */
final class PriceTierSelector
{
    /**
     * Gibt den anwendbaren Preis-Tier einer Versandmethode zurück.
     *
     * Ein Tier ist nur anwendbar, wenn (a) seine Verfügbarkeits-Regel (`ruleId`) zum Kontext passt UND
     * (b) sein Mengen-/Gewichts-/Preis-Band (`quantityStart`..`quantityEnd`, je `calculation`-Typ) den
     * tatsächlichen Warenkorb-Kennwert enthält bzw. seine `calculationRuleId` matcht, analog zum
     * Core-`DeliveryCalculator::matches()`. Das Minimum über alle Tiers gäbe z. B. einem 15-kg-Produkt
     * den 0–5-kg-Preis statt des 5–30-kg-Preises.
     *
     * Die Reihenfolge der Regeln entscheidet, nicht ihr Preis. Der Kern geht die Regeln des
     * Kontexts der Reihe nach durch (und die sind nach Priorität absteigend sortiert) und bricht
     * bei der ersten ab, die einen passenden Preis hat (`DeliveryCalculator`, Zeile 107-116;
     * `RuleLoader`, Zeile 38-40). Wer stattdessen den billigsten Preis über alle passenden Regeln
     * nimmt, weicht von der Kasse ab: Bei „Sperrgut" (hohe Priorität, 49,90 €) neben „Standardkunde"
     * (niedrige, 5,90 €) verlangt die Kasse 49,90 €, und der Feed nennte 5,90 €.
     *
     * Innerhalb einer Regel gewinnt weiterhin der günstigste passende Preis; auch das ist der
     * Kern (`getMatchingPriceOfRule` sortiert aufsteigend und nimmt den ersten Treffer).
     *
     * @param array<int, string> $matchedRuleIds nach Regel-Priorität absteigend sortiert
     * @param array<int, float>  $deliveryValues
     */
    public function resolveApplicablePriceTier(
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

        // Keine Regel hat gegriffen. Jetzt erst der Standardpreis.
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
     * Liest die für das Preis-Tier-Matching relevanten Kennwerte aus dem berechneten Warenkorb,
     * dieselben Werte, die der Core-DeliveryCalculator gegen die Bänder prüft.
     *
     * `null` heißt: Es ist nichts zu versenden. Das ist etwas anderes als „alle Kennwerte sind 0".
     * Lauter Nullen träfen das billigste Preisband, weil jedes bei 0 beginnt, und ergäben einen
     * erfundenen Preis. Der Aufrufer setzt für `null` 0,00 € an, wie es der Kern auch tut.
     *
     * @return array<int, float>|null
     */
    public function extractDeliveryValues(Cart $cart): ?array
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
     * Prüft, ob ein Preis-Tier auf den Warenkorb anwendbar ist. Nachbildung von
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
     * Brutto ist nicht immer richtig. Der Kern nimmt nur bei `TAX_STATE_GROSS` den
     * Bruttopreis, sonst den Nettopreis (`DeliveryCalculator::getPriceForTaxState`, Zeile
     * 222-229). Für die Schweiz (eines der voreingestellten Länder) setzt der Kern über
     * `country.customerTax` regulär `TAX_STATE_FREE`. Ein fester Bruttopreis stünde dort im Feed,
     * während die Kasse netto abrechnet.
     *
     * Er steht im Warenkorb, nicht im Kontext. `CartRuleLoader::validateTaxFree()` setzt den
     * steuerfreien Zustand nur für die Dauer der Neuberechnung und stellt ihn danach zurück
     * (Kern 6.7.12.1, Zeile 325-345). Wer den Kontext hinterher fragt, bekommt wieder
     * `TAX_STATE_GROSS`, auch bei `customerTax.enabled = true` für die Schweiz. Der berechnete
     * Warenkorb trägt den tatsächlich verwendeten Zustand.
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
}
