<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Configuration;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die Plugin-Konfiguration aus dem Shopware SystemConfig-Service.
 *
 * Alle Werte sind pro Verkaufskanal überschreibbar. Ist kein verkanalspezifischer
 * Wert gesetzt, greift Shopware automatisch auf die globale Konfiguration zurück.
 */
final class ConfigurationService
{
    private const CONFIG_PREFIX = 'RcProductFeedShippingExtension.config.';
    private const DEFAULT_EXCLUDED_METHODS = ['Selbstabholung', 'Abholung', 'Pickup'];

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    /**
     * Gibt zurück ob das Plugin für den Verkaufskanal aktiv ist.
     *
     * Ist kein Wert konfiguriert, gilt das Plugin als aktiviert (true als Standard).
     */
    public function isEnabled(string $salesChannelId): bool
    {
        return (bool) ($this->systemConfigService->get(
            self::CONFIG_PREFIX . 'enabled',
            $salesChannelId
        ) ?? true);
    }

    /**
     * Gibt den konfigurierten Berechnungs-Verkaufskanal zurück.
     *
     * Produktvergleichs-Kanäle (z.B. Google Shopping) besitzen keine Versandmethoden.
     * In diesem Fall muss der Storefront-Kanal angegeben werden, dessen Versandregeln
     * für die Berechnung verwendet werden sollen.
     *
     * Gibt null zurück wenn kein Kanal konfiguriert ist — der Aufrufer fällt dann
     * auf den Feed-Kanal zurück.
     */
    public function getCalculationSalesChannelId(string $salesChannelId): ?string
    {
        $value = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'calculationSalesChannelId',
            $salesChannelId
        );

        return $value !== '' ? trim($value) : null;
    }

    /** Gibt die konfigurierten Länder als Array von ISO-Codes in Großbuchstaben zurück. */
    public function getCountries(string $salesChannelId): array
    {
        $value = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'countries',
            $salesChannelId
        );

        return $this->parseCommaSeparated($value, true);
    }

    /** Gibt den globalen Fallback-Versandpreis zurück. Negativwerte werden auf 0,00 normiert. */
    public function getFallbackShippingCost(string $salesChannelId): float
    {
        $value = (float) ($this->systemConfigService->get(
            self::CONFIG_PREFIX . 'fallbackShippingCost',
            $salesChannelId
        ) ?? 0.0);

        return max(0.0, $value);
    }

    /**
     * Gibt den Fallback-Versandpreis für ein bestimmtes Land zurück.
     *
     * Sucht zuerst in der länderspezifischen Konfiguration (Format: `DE:4.95,AT:9.90`).
     * Ist kein Eintrag für das Land vorhanden, wird der globale Fallback zurückgegeben.
     */
    public function getFallbackShippingCostForCountry(string $countryIso, string $salesChannelId): float
    {
        $value = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'fallbackShippingCostsPerCountry',
            $salesChannelId
        );

        $iso = strtoupper(trim($countryIso));

        foreach (explode(',', $value) as $entry) {
            $parts = explode(':', trim($entry), 2);
            if (count($parts) === 2 && strtoupper(trim($parts[0])) === $iso) {
                return max(0.0, (float) trim($parts[1]));
            }
        }

        return $this->getFallbackShippingCost($salesChannelId);
    }

    /**
     * Gibt die Keywords zurück, nach denen Versandarten ausgeschlossen werden.
     *
     * Ist das Konfigurationsfeld leer, werden die Standardwerte zurückgegeben
     * (`Selbstabholung`, `Abholung`, `Pickup`). Das verhindert, dass Selbstabholung
     * mit 0,00 € als günstigste Versandart in den Feed gelangt.
     */
    public function getExcludedShippingMethods(string $salesChannelId): array
    {
        $value = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'excludedShippingMethods',
            $salesChannelId
        );

        if ($value === '') {
            return self::DEFAULT_EXCLUDED_METHODS;
        }

        return $this->parseCommaSeparated($value, false);
    }

    private function parseCommaSeparated(string $value, bool $uppercase): array
    {
        if ($value === '') {
            return [];
        }

        $items = explode(',', $value);
        $result = [];

        foreach ($items as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $result[] = $uppercase ? strtoupper($item) : $item;
        }

        return $result;
    }
}
