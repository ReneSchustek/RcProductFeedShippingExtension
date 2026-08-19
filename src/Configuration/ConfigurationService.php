<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Configuration;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Liest die Plugin-Konfiguration aus dem Shopware SystemConfig-Service.
 *
 * Alle Werte sind pro Verkaufskanal überschreibbar. Ist kein verkanalspezifischer
 * Wert gesetzt, greift Shopware automatisch auf die globale Konfiguration zurück.
 */
class ConfigurationService
{
    private const CONFIG_PREFIX = 'RcProductFeedShippingExtension.config.';
    private const DEFAULT_EXCLUDED_METHODS = ['Selbstabholung', 'Abholung', 'Pickup'];

    /**
     * Trennzeichen zwischen den Einträgen der länderspezifischen Ersatzwerte.
     *
     * Die Hilfe der Einstellung nennt das Komma. Auf live-clone stand dort trotzdem
     * `DE:7.95;AT:14.95;CH:21.95` — und weil nur am Komma getrennt wurde, war die ganze Zeichenkette
     * ein einziger Eintrag: `DE` traf zufällig zu, AT und CH fielen still auf den globalen Wert
     * zurück. 1040 Kombinationen Speditionsware standen dadurch mit 7,95 € statt 14,95 € bzw.
     * 21,95 € im Warenstrom. Beide Zeichen zu akzeptieren kostet nichts und nimmt der Einstellung
     * ihre schärfste Falle.
     */
    private const ENTRY_SEPARATORS = '/[,;]/';

    /** Wert der Einstellung `missingShippingMethodBehaviour`, bei dem der Feed schweigt. */
    public const BEHAVIOUR_OMIT = 'omit';

    /**
     * Zuordnung Land → Ersatzwert, je Verkaufskanal einmal aufgebaut.
     *
     * Nicht nur wegen der Arbeit: Ein unlesbarer Eintrag wird beim Aufbau **einmal** gemeldet.
     * Bei jedem Aufruf zu prüfen hieße, dieselbe Meldung tausendfach zu schreiben.
     *
     * @var array<string, array<string, float>>
     */
    private array $fallbackPerCountry = [];

    public function __construct(
        private readonly SystemConfigService $systemConfigService,
        private readonly LoggerInterface $logger,
    ) {
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

    /** @return array<int, string> ISO-Codes in Großbuchstaben */
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
     * Sucht zuerst in der länderspezifischen Konfiguration (Format: `DE:4.95,AT:9.90`; Komma
     * und Semikolon trennen gleichermaßen).
     * Ist kein Eintrag für das Land vorhanden, wird der globale Fallback zurückgegeben.
     */
    public function getFallbackShippingCostForCountry(string $countryIso, string $salesChannelId): float
    {
        $perCountry = $this->loadFallbackPerCountry($salesChannelId);
        $iso = strtoupper(trim($countryIso));

        return $perCountry[$iso] ?? $this->getFallbackShippingCost($salesChannelId);
    }

    /**
     * Liest die länderspezifischen Ersatzwerte und meldet, was sich nicht lesen lässt.
     *
     * Ein Eintrag ohne Doppelpunkt oder ohne Zahl dahinter wird **verworfen und gemeldet**, nicht
     * als 0,00 € übernommen: `(float) 'abc'` wäre kostenloser Versand im Warenstrom, und das ist
     * die teuerste aller stillen Auslegungen.
     *
     * @return array<string, float>
     */
    private function loadFallbackPerCountry(string $salesChannelId): array
    {
        if (isset($this->fallbackPerCountry[$salesChannelId])) {
            return $this->fallbackPerCountry[$salesChannelId];
        }

        $value = $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'fallbackShippingCostsPerCountry',
            $salesChannelId
        );

        $perCountry = [];
        $unreadable = [];

        foreach (preg_split(self::ENTRY_SEPARATORS, $value) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $parts = explode(':', $entry, 2);
            $iso = strtoupper(trim($parts[0]));
            $amount = trim($parts[1] ?? '');

            if (count($parts) !== 2 || $iso === '' || !is_numeric($amount)) {
                $unreadable[] = $entry;
                continue;
            }

            $perCountry[$iso] = max(0.0, (float) $amount);
        }

        if ($unreadable !== []) {
            $this->logger->warning('RcProductFeedShipping: unlesbare Einträge bei den länderspezifischen Ersatzwerten.', [
                'salesChannelId' => substr($salesChannelId, 0, 8),
                'entries' => $unreadable,
                'expectedFormat' => 'DE:4.95,AT:9.90',
                'metric' => 'fallback_per_country_unreadable',
            ]);
        }

        return $this->fallbackPerCountry[$salesChannelId] = $perCountry;
    }

    /**
     * Gibt die Keywords zurück, nach denen Versandarten ausgeschlossen werden.
     *
     * Ist das Konfigurationsfeld leer, werden die Standardwerte zurückgegeben
     * (`Selbstabholung`, `Abholung`, `Pickup`). Das verhindert, dass Selbstabholung
     * mit 0,00 € als günstigste Versandart in den Feed gelangt.
     *
     * @return array<int, string>
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

    /**
     * Soll der Feed shouldOmit, wenn der Shop eine Ware nicht in ein Land versendet?
     *
     * `true` heißt: kein `g:shipping`-Block für dieses Land — Google zieht dann die Einstellung des
     * Händlerkontos. Für Ware, die nur auf Anfrage geht, ist das die einzige wahre Aussage; ein
     * Ersatzwert sagt einen Versand zu, den es nicht gibt.
     *
     * Standard ist `false` — eine bestehende Installation ändert ihr Verhalten nicht von selbst.
     */
    public function shouldOmitCountryWithoutShippingMethod(string $salesChannelId): bool
    {
        return $this->systemConfigService->getString(
            self::CONFIG_PREFIX . 'missingShippingMethodBehaviour',
            $salesChannelId
        ) === self::BEHAVIOUR_OMIT;
    }

    /** @return array<int, string> */
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
