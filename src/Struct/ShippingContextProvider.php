<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;

/**
 * Wird einmalig in den Feed-Template-Kontext injiziert und berechnet Versandkosten
 * pro Produkt on-demand aus dem Twig-Template heraus.
 *
 * Das Template ruft `rcShipping.get(product.id, 'DE')` auf.
 * Der Cache des Calculators stellt sicher, dass jede Kombination nur einmal
 * berechnet wird, auch wenn das Template den Wert mehrfach abfragt.
 */
final class ShippingContextProvider
{
    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ShippingFallbackService $fallbackService,
        private readonly array $countries,
        private readonly string $salesChannelId,
        private readonly string $currencyIso,
    ) {
    }

    /**
     * Gibt die Versandkosten für ein Produkt in das angegebene Land zurück.
     *
     * Gibt null zurück wenn das Land nicht konfiguriert ist.
     * Für konfigurierte Länder wird immer ein Preis zurückgegeben —
     * entweder berechnet oder der konfigurierte Fallback-Preis.
     */
    public function get(string $productId, string $countryIso): ?float
    {
        if (!in_array(strtoupper($countryIso), array_map('strtoupper', $this->countries), true)) {
            return null;
        }

        try {
            return $this->calculator->calculate($productId, $countryIso, $this->salesChannelId, $this->currencyIso)->shippingCost;
        } catch (\Throwable) {
            // Calculator hat intern selbst eine Fehlerbehandlung — dieser Pfad ist ein letzter Schutz.
            return $this->fallbackService->getFallbackResult($productId, $countryIso, $this->salesChannelId, $this->currencyIso)->shippingCost;
        }
    }

    /** Gibt die konfigurierten Länder zurück (für Template-Iteration). */
    public function getCountries(): array
    {
        return $this->countries;
    }
}
