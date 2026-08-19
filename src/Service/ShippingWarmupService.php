<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\WarmupResult;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

/**
 * Füllt den Versandkosten-Speicher vorab — der einzige Ort, an dem tatsächlich gerechnet wird.
 *
 * Warum das kein Beiwerk ist: Der Feed selbst rechnet nicht mehr. Die Berechnung lief früher aus der
 * Twig-Vorlage heraus und scheiterte dort **immer**, weil Shopware während des Renderns interne
 * Entity-Felder sperrt und die Warenkorb-Regelauswertung genau so ein Feld braucht
 * (`RuleEntity::payload`). Rechnen darf deshalb nur noch, wer außerhalb von Twig
 * läuft: dieses Kommando und die geplante Aufgabe.
 *
 * Die Logik sitzt hier und nicht im Kommando, weil beide Aufrufer — Konsole und geplante Aufgabe —
 * dasselbe tun müssen. Ein zweiter Abzug derselben Logik ginge beim ersten Umbau auseinander.
 */
class ShippingWarmupService
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ConfigurationService $configurationService,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly EntityRepository $salesChannelRepository,
        private readonly ActiveProductProviderService $activeProductProvider,
        private readonly FeedChannelProviderService $feedChannelProvider,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rechnet alle Kombinationen aus aktivem Produkt und konfiguriertem Land vor.
     *
     * @param callable(int):void|null $onProgress Wird nach jeder Kombination mit der Gesamtzahl
     *                                                aufgerufen — für die Fortschrittsanzeige der Konsole.
     *                                                Die geplante Aufgabe übergibt nichts.
     * @param callable(string):void|null $onMessage   Erklärende Zeilen für die Konsole. Was hier
     *                                                 durchläuft, steht für die geplante Aufgabe
     *                                                 zusätzlich im Protokoll.
     */
    public function warmup(?callable $onProgress = null, ?callable $onMessage = null): WarmupResult
    {
        $context = Context::createDefaultContext();
        $result = new WarmupResult();

        // Mehrere Feed-Kanäle können denselben Berechnungs-Kanal teilen. Ohne Merkliste liefe der
        // Warmup für diesen Kanal mehrfach — bei mehreren tausend Produkten kein Detail.
        $processedChannels = [];
        $readingChannels = $this->feedChannelProvider->loadReadingChannelIds($context);

        foreach ($this->loadActiveSalesChannels($context) as $salesChannel) {
            $feedChannelId = $salesChannel->getId();
            $channelName = $salesChannel->getName() ?? $feedChannelId;

            if (!$this->configurationService->isEnabled($feedChannelId)) {
                continue;
            }

            if (!isset($readingChannels[$feedChannelId])) {
                $this->reportUnreadChannel($channelName, $onMessage);
                continue;
            }

            $countries = $this->configurationService->getCountries($feedChannelId);
            if ($countries === []) {
                continue;
            }

            $calcChannelId = $this->configurationService->getCalculationSalesChannelId($feedChannelId) ?? $feedChannelId;

            if (isset($processedChannels[$calcChannelId])) {
                $this->report($onMessage, sprintf(
                    'Übersprungen: %s (Berechnungs-Kanal %s bereits verarbeitet)',
                    $channelName,
                    substr($calcChannelId, 0, 8),
                ));
                continue;
            }

            // Den Berechnungs-Kanal vorab prüfen: Schlägt die Context-Erstellung fehl (etwa wegen
            // fehlender Sprachkonfiguration), überspringen wir den ganzen Kanal, statt für jedes
            // Produkt einzeln denselben Fehler zu erzeugen.
            if (!$this->canCreateContext($calcChannelId)) {
                $message = sprintf(
                    'Berechnungs-Kanal %s kann keinen Context erstellen — Kanal "%s" übersprungen. '
                    . 'In den Plugin-Einstellungen einen gültigen Storefront-Kanal als Berechnungs-Kanal hinterlegen.',
                    substr($calcChannelId, 0, 8),
                    $channelName,
                );

                $result->channelSkipped($channelName, $message);
                $this->logger->warning('RcProductFeedShipping: ' . $message, [
                    'context' => 'ruhrcoder_product_feed_shipping.warmup',
                    'metric' => 'warmup_channel_skipped',
                ]);
                $this->report($onMessage, $message);
                continue;
            }

            $processedChannels[$calcChannelId] = true;

            $productIds = $this->activeProductProvider->loadActiveProductIds($context);

            $this->report($onMessage, sprintf(
                '%s → Berechnungs-Kanal %s, %d Länder, %d Produkte',
                $channelName,
                substr($calcChannelId, 0, 8),
                count($countries),
                count($productIds),
            ));

            $this->warmupChannel($productIds, $countries, $calcChannelId, $result, $onProgress);
        }

        $this->logger->info('RcProductFeedShipping: Warmup abgeschlossen.', [
            'context' => 'ruhrcoder_product_feed_shipping.warmup',
            'calculated' => $result->calculated,
            'fallback' => $result->fallback,
            'metric' => 'warmup_completed',
        ]);

        return $result;
    }

    /**
     * Zählt vorab, wie viele Kombinationen anstehen — nur für die Fortschrittsanzeige.
     */
    public function combinationCount(): int
    {
        $context = Context::createDefaultContext();
        $products = count($this->activeProductProvider->loadActiveProductIds($context));
        $total = 0;
        $processedChannels = [];
        $readingChannels = $this->feedChannelProvider->loadReadingChannelIds($context);

        foreach ($this->loadActiveSalesChannels($context) as $salesChannel) {
            $feedChannelId = $salesChannel->getId();

            if (!$this->configurationService->isEnabled($feedChannelId) || !isset($readingChannels[$feedChannelId])) {
                continue;
            }

            $countries = $this->configurationService->getCountries($feedChannelId);
            if ($countries === []) {
                continue;
            }

            $calcChannelId = $this->configurationService->getCalculationSalesChannelId($feedChannelId) ?? $feedChannelId;

            if (isset($processedChannels[$calcChannelId]) || !$this->canCreateContext($calcChannelId)) {
                continue;
            }

            $processedChannels[$calcChannelId] = true;
            $total += $products * count($countries);
        }

        return $total;
    }

    /**
     * @param array<int, string>      $productIds
     * @param array<int, string>      $countries
     * @param callable(int):void|null $onProgress
     */
    private function warmupChannel(
        array $productIds,
        array $countries,
        string $calcChannelId,
        WarmupResult $result,
        ?callable $onProgress,
    ): void {
        foreach ($productIds as $productId) {
            foreach ($countries as $countryIso) {
                // `useStoredValue: false` — der Warmup rechnet, immer. Rief er die Methode mit
                // Speicher-Kurzschluss auf, füllte er nur Lücken: Ein gültiger Eintrag wurde
                // übersprungen und erst neu gerechnet, nachdem er verfallen war. Zwischen dem
                // Verfall und dem übernächsten Lauf lieferte der Feed für jedes Produkt den
                // Ersatzwert.
                $match = $this->calculator->calculate(
                    $productId,
                    $countryIso,
                    $calcChannelId,
                    useStoredValue: false,
                );

                $result->record($match->isFallback);

                if ($onProgress !== null) {
                    $onProgress(1);
                }
            }
        }
    }

    /**
     * Meldet einen Kanal, den kein Produktexport ausliest — in der Konsole und im Protokoll.
     *
     * Beides, weil die geplante Aufgabe keine Konsole hat: Ohne Protokolleintrag bliebe unerklärt,
     * warum ein eingeschalteter Kanal keine Werte bekommt.
     *
     * @param callable(string):void|null $onMessage
     */
    private function reportUnreadChannel(string $channelName, ?callable $onMessage): void
    {
        $message = sprintf(
            'Übersprungen: %s — kein Produktexport liest diesen Kanal aus.',
            $channelName,
        );

        $this->report($onMessage, $message);
        $this->logger->info('RcProductFeedShipping: ' . $message, [
            'context' => 'ruhrcoder_product_feed_shipping.warmup',
            'metric' => 'warmup_channel_without_feed',
        ]);
    }

    private function canCreateContext(string $salesChannelId): bool
    {
        try {
            $this->contextFactory->create(Uuid::randomHex(), $salesChannelId, []);

            return true;
        } catch (\Throwable) {
            // Erwartet: Produktvergleichs-Kanäle haben oft keine vollständige Sprachkonfiguration.
            return false;
        }
    }

    /** @return array<string, \Shopware\Core\System\SalesChannel\SalesChannelEntity> */
    private function loadActiveSalesChannels(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->salesChannelRepository->search($criteria, $context)->getEntities()->getElements();
    }

    /** @param callable(string):void|null $onMessage */
    private function report(?callable $onMessage, string $text): void
    {
        if ($onMessage !== null) {
            $onMessage($text);
        }
    }
}
