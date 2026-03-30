<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

/**
 * Ergebnis einer Versandkostenberechnung für ein Produkt in ein bestimmtes Land.
 *
 * Das Flag `isFallback` zeigt an ob der Preis tatsächlich berechnet wurde oder
 * aus dem konfigurierten Fallback stammt. Fallback-Ergebnisse werden ebenfalls
 * gecacht, damit fehlerhafte Berechnungen nicht bei jedem Export wiederholt werden.
 */
final class ShippingCalculationResult
{
    public function __construct(
        public readonly string $productId,
        public readonly string $countryIso,
        public readonly float $shippingCost,
        public readonly string $currencyIso,
        public readonly bool $isFallback,
    ) {
    }
}
