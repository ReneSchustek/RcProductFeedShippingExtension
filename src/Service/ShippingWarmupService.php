<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\CalculationChannelSelection;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\WarmupResult;
use Shopware\Core\Framework\Context;

/**
 * Füllt den Versandkosten-Speicher vorab: der einzige Ort, an dem tatsächlich gerechnet wird.
 *
 * Das ist kein Beiwerk, denn der Feed selbst rechnet nicht. Aus der Twig-Vorlage heraus scheitert
 * die Berechnung immer, weil Shopware während des Renderns interne Entity-Felder sperrt und die
 * Warenkorb-Regelauswertung genau so ein Feld braucht (`RuleEntity::payload`). Rechnen darf deshalb
 * nur, wer außerhalb von Twig läuft: dieses Kommando und die geplante Aufgabe.
 *
 * Die Logik sitzt hier und nicht im Kommando, weil beide Aufrufer (Konsole und geplante Aufgabe)
 * dasselbe tun müssen. Ein zweiter Abzug derselben Logik ginge beim ersten Umbau auseinander.
 *
 * Bewusst nicht `final`: Tests ersetzen diese Klasse durch ein Test-Double, und PHPUnit kann finale
 * Klassen nicht nachbilden.
 */
class ShippingWarmupService
{
    /**
     * Höchstalter, nach dem in jedem Fall neu gerechnet wird, auch bei gleichem Prüfwert.
     *
     * Sieben Tage. Ein wöchentlicher Vollauf an einem festen Tag erfüllte denselben Zweck, bündelte
     * die Last aber auf diesen Tag; die Frist je Eintrag verteilt sie. Sie ist das Sicherheitsnetz
     * für jede Änderung, die der Prüfwert nicht erfasst.
     */
    public const FULL_RECALCULATION_SECONDS = 604800;

    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly CalculationChannelProvider $channelProvider,
        private readonly ActiveProductProviderService $activeProductProvider,
        private readonly VirtualCartBuilderService $cartBuilder,
        private readonly ShippingFingerprintService $fingerprintService,
        private readonly ShippingPriceStore $priceStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Rechnet alle Kombinationen aus aktivem Produkt und konfiguriertem Land vor.
     *
     * @param callable(int):void|null $onProgress Wird nach jeder Kombination mit der Gesamtzahl
     *                                                aufgerufen, für die Fortschrittsanzeige der Konsole.
     *                                                Die geplante Aufgabe übergibt nichts.
     * @param callable(string):void|null $onMessage   Erklärende Zeilen für die Konsole. Was hier
     *                                                 durchläuft, steht für die geplante Aufgabe
     *                                                 zusätzlich im Protokoll.
     */
    public function warmup(?callable $onProgress = null, ?callable $onMessage = null, bool $force = false): WarmupResult
    {
        $context = Context::createDefaultContext();
        $result = new WarmupResult();

        $selection = $this->channelProvider->select($context);
        $this->reportSkipped($selection, $result, $onMessage);

        foreach ($selection->channels() as $calcChannelId => $channel) {
            $productIds = $this->activeProductProvider->loadActiveProductIds($context);

            $this->report($onMessage, sprintf(
                '%s → Berechnungs-Kanal %s, %d Länder, %d Produkte',
                $channel['name'],
                substr($calcChannelId, 0, 8),
                count($channel['countries']),
                count($productIds),
            ));

            $this->warmupChannel($productIds, $channel['countries'], $calcChannelId, $result, $onProgress, $force);
        }

        // Die Warenkorbzeile dieses Prozesses wieder abräumen. Sie entsteht zwangsläufig, weil
        // Shopware den Warenkorb beim Berechnen wegschreibt. Liegen bleiben muss sie deshalb nicht.
        $this->cartBuilder->removeTrace();

        $this->logger->info('RcProductFeedShipping: Warmup abgeschlossen.', [
            'context' => 'ruhrcoder_product_feed_shipping.warmup',
            'calculated' => $result->calculated,
            'fallback' => $result->fallback,
            'unchanged' => $result->unchanged,
            'metric' => 'warmup_completed',
        ]);

        return $result;
    }

    /**
     * Zählt vorab, wie viele Kombinationen anstehen (nur für die Fortschrittsanzeige).
     */
    public function combinationCount(): int
    {
        $context = Context::createDefaultContext();
        $products = count($this->activeProductProvider->loadActiveProductIds($context));
        $total = 0;

        foreach ($this->channelProvider->select($context)->channels() as $channel) {
            $total += $products * count($channel['countries']);
        }

        return $total;
    }

    /**
     * Meldet die weggefallenen Kanäle. Ein Kanal ohne baubaren Kontext ist ein Fehler der Einstellungen und kommt als
     * Warnung ins Protokoll und ins Ergebnis; ein Kanal ohne Export oder ein doppelter ist erwartbar und nur Auskunft.
     *
     * @param callable(string):void|null $onMessage
     */
    private function reportSkipped(CalculationChannelSelection $selection, WarmupResult $result, ?callable $onMessage): void
    {
        foreach ($selection->skipped() as $skipped) {
            match ($skipped['reason']) {
                CalculationChannelSelection::SKIP_UNREAD => $this->reportUnreadChannel($skipped['name'], $onMessage),
                CalculationChannelSelection::SKIP_DUPLICATE => $this->report($onMessage, sprintf(
                    'Übersprungen: %s (Berechnungs-Kanal %s bereits verarbeitet)',
                    $skipped['name'],
                    substr($skipped['calcChannelId'], 0, 8),
                )),
                default => $this->reportMissingContext($skipped, $result, $onMessage),
            };
        }
    }

    /**
     * @param array{name: string, reason: string, calcChannelId: string, detail: string} $skipped
     * @param callable(string):void|null                                                 $onMessage
     */
    private function reportMissingContext(array $skipped, WarmupResult $result, ?callable $onMessage): void
    {
        $message = sprintf(
            'Berechnungs-Kanal %s kann keinen Context erstellen (%s) — Kanal "%s" übersprungen. '
            . 'In den Plugin-Einstellungen einen gültigen Storefront-Kanal als Berechnungs-Kanal hinterlegen.',
            substr($skipped['calcChannelId'], 0, 8),
            $skipped['detail'],
            $skipped['name'],
        );

        $result->channelSkipped($skipped['name'], $message);
        $this->logger->warning('RcProductFeedShipping: ' . $message, [
            'context' => 'ruhrcoder_product_feed_shipping.warmup',
            'metric' => 'warmup_channel_skipped',
        ]);
        $this->report($onMessage, $message);
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
        bool $force = false,
    ): void {
        // Beides in je einer Abfrage für den ganzen Kanal, bevor die erste Kombination drankommt.
        // Der Vergleich selbst kostet danach nichts mehr.
        $fingerprints = $this->fingerprintService->loadFingerprints($productIds);
        $state = $force ? [] : $this->priceStore->loadState($calcChannelId);
        $toRefresh = [];

        foreach ($productIds as $productId) {
            $fingerprint = $fingerprints[$productId] ?? null;

            foreach ($countries as $countryIso) {
                $key = $productId . '|' . strtoupper($countryIso);
                $known = $state[$key] ?? null;

                // Übersprungen wird nur, wenn drei Dinge zusammenkommen: ein gespeicherter Prüfwert,
                // ein gleicher aktueller Prüfwert und ein Eintrag, der die Vollauf-Frist noch nicht
                // gerissen hat. Fehlt eines, wird gerechnet.
                if (
                    $known !== null
                    && $fingerprint !== null
                    && $known['hash'] === $fingerprint
                    && $known['age'] < self::FULL_RECALCULATION_SECONDS
                ) {
                    $toRefresh[] = ['productId' => $productId, 'countryIso' => $countryIso];
                    $result->recordUnchanged();

                    if ($onProgress !== null) {
                        $onProgress(1);
                    }

                    continue;
                }

                // `useStoredValue: false`: Der Warmup rechnet, immer. Mit Speicher-Kurzschluss
                // füllte er nur Lücken: Ein gültiger Eintrag würde übersprungen und erst neu
                // gerechnet, nachdem er verfallen ist. Zwischen dem Verfall und dem übernächsten
                // Lauf lieferte der Feed für jedes Produkt den Ersatzwert.
                $match = $this->calculator->calculate(
                    $productId,
                    $countryIso,
                    $calcChannelId,
                    useStoredValue: false,
                    sourceHash: $fingerprint,
                );

                $result->record($match->isFallback);

                if ($onProgress !== null) {
                    $onProgress(1);
                }
            }
        }

        // Die übersprungenen Einträge sind gültig, nur alt aussehend. Ohne dieses Auffrischen
        // verfielen sie nach `ShippingPriceStore::MAX_AGE_SECONDS`, und der Feed nennte für jedes
        // unveränderte Produkt den Ersatzwert. Die Ersparnis wäre teuer erkauft.
        $this->priceStore->touch($toRefresh, $calcChannelId);
    }

    /**
     * Meldet einen Kanal, den kein Produktexport ausliest, in der Konsole und im Protokoll.
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

    /** @param callable(string):void|null $onMessage */
    private function report(?callable $onMessage, string $text): void
    {
        if ($onMessage !== null) {
            $onMessage($text);
        }
    }
}
