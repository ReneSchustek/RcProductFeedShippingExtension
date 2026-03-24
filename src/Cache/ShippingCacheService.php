<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Cache;

use Psr\Log\LoggerInterface;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Zwischenspeicher für berechnete Versandkosten, gültig für 24 Stunden.
 *
 * Alle Einträge werden mit dem Tag `rc_shipping` versehen, was eine gezielte
 * Invalidierung ohne vollständigen Cache-Clear ermöglicht.
 */
class ShippingCacheService
{
    public const CACHE_TTL = 86400;
    public const CACHE_TAG = 'rc_shipping';

    public function __construct(
        private readonly TagAwareCacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Gibt ein gecachtes Berechnungsergebnis zurück oder null bei Cache-Miss.
     *
     * Die Symfony-Cache-API kennt keinen expliziten Cache-Miss-Rückgabewert — null
     * ist ein gültiger gecachter Wert. Deshalb wird über ein $isMiss-Flag unterschieden
     * ob null gecacht ist oder ob der Eintrag schlicht nicht existiert.
     */
    public function get(string $productId, string $countryIso, string $salesChannelId): ?ShippingCalculationResult
    {
        try {
            $key = $this->buildCacheKey($productId, $countryIso, $salesChannelId);
            $isMiss = false;

            /** @var ShippingCalculationResult|null $result */
            $result = $this->cache->get($key, static function () use (&$isMiss) {
                $isMiss = true;

                return null;
            });

            return $isMiss ? null : $result;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to read shipping cache', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Speichert ein Berechnungsergebnis für 24 Stunden im Cache.
     *
     * Der bestehende Eintrag wird vor dem Schreiben gelöscht, weil die Symfony-Cache-API
     * einen vorhandenen Eintrag nicht überschreibt — sie gibt ihn stattdessen zurück.
     * Cache-Fehler werden geloggt und nicht weiter propagiert.
     */
    public function set(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        ShippingCalculationResult $result,
    ): void {
        try {
            $key = $this->buildCacheKey($productId, $countryIso, $salesChannelId);

            $this->cache->delete($key);
            $this->cache->get($key, function (ItemInterface $item) use ($result) {
                $item->expiresAfter(self::CACHE_TTL);
                $item->tag([self::CACHE_TAG]);

                return $result;
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to write shipping cache', ['error' => $e->getMessage()]);
        }
    }

    /** Invalidiert alle Versandkosten-Einträge anhand des gemeinsamen Cache-Tags. */
    public function invalidateAll(): void
    {
        $this->cache->invalidateTags([self::CACHE_TAG]);
    }

    private function buildCacheKey(string $productId, string $countryIso, string $salesChannelId): string
    {
        return sprintf('rc_shipping_%s_%s_%s', $productId, strtoupper($countryIso), $salesChannelId);
    }
}
