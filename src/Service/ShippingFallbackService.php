<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;

/**
 * Liefert einen konfigurierten Fallback-Preis wenn die Versandkostenberechnung fehlschlägt.
 */
final class ShippingFallbackService
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
