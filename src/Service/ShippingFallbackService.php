<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Service;

use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;

/**
 * Liefert einen konfigurierten Fallback-Preis wenn die Versandkostenberechnung fehlschlägt.
 */
class ShippingFallbackService
{
    public function __construct(private readonly ConfigurationService $configurationService)
    {
    }

    /**
     * Gibt ein Berechnungsergebnis mit Fallback-Preis zurück, markiert als `isFallback = true`.
     *
     * Es wird zunächst nach einem länderspezifischen Fallback gesucht, dann auf den
     * globalen Fallback zurückgefallen. Das isFallback-Flag erlaubt dem Aufrufer,
     * Fallback-Ergebnisse von echten Berechnungen zu unterscheiden.
     */
    public function getFallbackResult(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        string $currencyIso,
    ): ShippingCalculationResult {
        return new ShippingCalculationResult(
            productId: $productId,
            countryIso: $countryIso,
            shippingCost: $this->configurationService->getFallbackShippingCostForCountry($countryIso, $salesChannelId),
            currencyIso: $currencyIso,
            isFallback: true,
        );
    }
}
