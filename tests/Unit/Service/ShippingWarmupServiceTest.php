<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\CalculationChannelProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedChannelProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFingerprintService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Der Warmup, der einzige Ort, an dem tatsächlich gerechnet wird.
 *
 * Der Feed rechnet nicht selbst, er liest nur vorberechnete Werte. Füllt der Warmup den Speicher nicht oder füllt
 * er ihn mit Ersatzwerten, liefert der Feed für jeden Artikel denselben Betrag, und niemand
 * merkt es, weil der Feed dabei vollständig aussieht.
 */
class ShippingWarmupServiceTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private EntityRepository&MockObject $salesChannelRepository;
    private ActiveProductProviderService&MockObject $activeProductProvider;
    private FeedChannelProviderService&MockObject $feedChannelProvider;
    private VirtualCartBuilderService&MockObject $cartBuilder;
    private ShippingFingerprintService&MockObject $fingerprintService;
    private ShippingPriceStore&MockObject $priceStore;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->activeProductProvider = $this->createMock(ActiveProductProviderService::class);
        $this->feedChannelProvider = $this->createMock(FeedChannelProviderService::class);
        $this->cartBuilder = $this->createMock(VirtualCartBuilderService::class);
        $this->fingerprintService = $this->createMock(ShippingFingerprintService::class);
        $this->priceStore = $this->createMock(ShippingPriceStore::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Was: Zwei Produkte, drei Länder, ein Kanal.
     * Warum: Der Grundfall. Jede Kombination muss genau einmal gerechnet werden, nicht weniger
     *        (Lücken im Feed) und nicht mehr (bei mehreren tausend Kombinationen kostet jede Dopplung Minuten).
     * Erwartet: sechs Berechnungen, sechs gezählte Treffer.
     */
    public function testEveryProductCountryCombinationIsCalculatedExactlyOnce(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT', 'CH']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1', 'produkt-2']);

        $this->calculator->expects(self::exactly(6))
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 17.26, 'EUR', false));

        $result = $this->createService()->warmup();

        self::assertSame(6, $result->calculated);
        self::assertSame(0, $result->fallback);
        self::assertFalse($result->fallbacksOnly());
    }

    /**
     * Was: Zwei Feed-Kanäle, die sich denselben Berechnungs-Kanal teilen.
     * Warum: Google Shopping und Headless rechnen etwa beide über den Storefront-Kanal. Ohne
     *        Merkliste liefe der Warmup zweimal über denselben Bestand, bei einigen tausend
     *        Produkten minutenlang für nichts.
     * Erwartet: nur der erste Kanal rechnet, der zweite wird als übersprungen gemeldet.
     */
    public function testASharedCalculationChannelIsProcessedOnlyOnce(): void
    {
        $this->givenSalesChannels(['feed-a' => 'Google Shopping', 'feed-b' => 'Headless']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn('gemeinsamer-kanal');
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);

        $this->calculator->expects(self::once())
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 8.93, 'EUR', false));

        $messages = [];
        $result = $this->createService()->warmup(null, static function (string $text) use (&$messages): void {
            $messages[] = $text;
        });

        self::assertSame(1, $result->total());
        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'bereits verarbeitet')));
    }

    /**
     * Was: Ein Kanal, für den sich kein Context bauen lässt.
     * Warum: Produktvergleichs-Kanäle haben oft keine vollständige Sprachkonfiguration. Ohne die
     *        Vorabprüfung erzeugte jedes einzelne Produkt denselben Fehler, eine Protokollzeile je
     *        Produkt für einen einzigen Konfigurationsmangel.
     * Erwartet: kein einziger Rechenversuch, der Kanal steht mit Grund in der Auswertung.
     */
    public function testAChannelWithoutAUsableContextIsSkippedWholesale(): void
    {
        $this->givenSalesChannels(['kaputt' => 'Google Shopping']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn('kaputt');
        $this->contextFactory->method('create')->willThrowException(new \RuntimeException('keine Sprache'));

        $this->calculator->expects(self::never())->method('calculate');
        $this->logger->expects(self::once())->method('warning');

        $result = $this->createService()->warmup();

        self::assertSame(0, $result->total());
        self::assertCount(1, $result->skippedChannels);
        self::assertStringContainsString('Google Shopping', $result->skippedChannels[0]);
    }

    /**
     * Was: Ein Kanal, in dem das Plugin abgeschaltet ist.
     * Warum: Abschalten muss auch den Warmup stilllegen. Täte es das nicht, liefe die geplante
     *        Aufgabe alle sechs Stunden über einen Kanal, der die Werte nie benutzt.
     * Erwartet: nichts passiert.
     */
    public function testADisabledChannelIsNotWarmedAtAll(): void
    {
        $this->givenSalesChannels(['aus' => 'Abgeschaltet']);
        $this->configurationService->method('isEnabled')->willReturn(false);

        $this->calculator->expects(self::never())->method('calculate');

        self::assertSame(0, $this->createService()->warmup()->total());
    }

    /**
     * Was: Ein Kanal ohne konfigurierte Länder.
     * Warum: Ohne Land gibt es keine Kombination. Ein Lauf, der hier trotzdem etwas versuchte,
     *        rechnete gegen einen leeren Ländersatz.
     * Erwartet: nichts passiert.
     */
    public function testAChannelWithoutCountriesIsNotWarmed(): void
    {
        $this->givenSalesChannels(['ohne-land' => 'Ohne Land']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn([]);

        $this->calculator->expects(self::never())->method('calculate');

        self::assertSame(0, $this->createService()->warmup()->total());
    }

    /**
     * Was: Ein Lauf, der ausschließlich Ersatzwerte einträgt.
     * Warum: Das ist der Alarmfall, an dem der Exitcode des Kommandos hängt. Er muss durch den
     *        ganzen Dienst hindurch bis zur Auswertung durchschlagen.
     * Erwartet: auffällig, obwohl der Lauf technisch durchlief.
     */
    public function testARunProducingOnlyFallbacksIsReportedAsSuspicious(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);

        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('p1', 'DE', 4.95, 'EUR', true));

        $result = $this->createService()->warmup();

        self::assertSame(0, $result->calculated);
        self::assertSame(2, $result->fallback);
        self::assertTrue($result->fallbacksOnly());
    }

    /**
     * Was: Die Fortschrittsmeldung.
     * Warum: Die Konsole zeigt einen Balken über mehrere tausend Kombinationen. Meldet der Dienst
     *        nicht je Kombination, steht der Balken minutenlang still und der Lauf sieht aus, als
     *        hinge er.
     * Erwartet: ein Ruf je Kombination.
     */
    public function testProgressIsReportedOncePerCombination(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('p1', 'DE', 8.93, 'EUR', false));

        $steps = 0;
        $this->createService()->warmup(static function () use (&$steps): void {
            ++$steps;
        });

        self::assertSame(4, $steps);
    }

    /**
     * Was: Die Vorabzählung für den Fortschrittsbalken.
     * Warum: Sie muss dieselben Kanäle aussieben wie der Lauf selbst. Zählt sie einen Kanal mit,
     *        den der Lauf überspringt, bleibt der Balken vor dem Ende stehen.
     * Erwartet: zwei Produkte mal drei Länder, der geteilte Kanal nur einmal.
     */
    public function testTheCombinationCountMatchesWhatTheRunWillDo(): void
    {
        $this->givenSalesChannels(['feed-a' => 'Google Shopping', 'feed-b' => 'Headless']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT', 'CH']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn('gemeinsamer-kanal');
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);

        self::assertSame(6, $this->createService()->combinationCount());
    }

    /**
     * Was: Ein eingeschalteter Kanal, den kein Produktexport ausliest.
     * Warum: Ein Kanal wie „Headless" trägt oft nur einen Bruchteil der Versandarten, und für fast
     *        alles greift dort zu Recht keine. Rechnete der Warmup ihn durch, legte er tausende
     *        Einträge „keine Versandart" ab, die niemand liest. Sie stellten den Großteil aller
     *        Meldungen und schickten die Fehlersuche in die falsche Richtung.
     * Erwartet: kein Rechenversuch, dafür eine Meldung mit Grund (in Konsole und Protokoll).
     */
    public function testAChannelNoFeedReadsIsSkippedWithAReason(): void
    {
        $this->givenSalesChannels(['ohne-feed' => 'Headless'], []);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT', 'CH']);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);

        $this->calculator->expects(self::never())->method('calculate');

        $logLines = [];
        $this->logger->method('info')->willReturnCallback(
            static function (string|\Stringable $message) use (&$logLines): void {
                $logLines[] = (string) $message;
            }
        );

        $messages = [];
        $result = $this->createService()->warmup(null, static function (string $text) use (&$messages): void {
            $messages[] = $text;
        });

        $hasReason = static fn (string $text): bool => str_contains($text, 'kein Produktexport liest diesen Kanal aus');

        self::assertSame(0, $result->total());
        self::assertNotEmpty(array_filter($messages, $hasReason), 'Die Konsole nennt den Grund nicht.');
        self::assertNotEmpty(array_filter($logLines, $hasReason), 'Das Protokoll nennt den Grund nicht.');
    }

    /**
     * Was: Die Vorabzählung für denselben Kanal.
     * Warum: Zählt sie einen Kanal mit, den der Lauf überspringt, bleibt der Fortschrittsbalken vor
     *        dem Ende stehen, und das Kommando meldet einen Lauf, den es nie gab.
     * Erwartet: null Kombinationen.
     */
    public function testTheCombinationCountIgnoresChannelsWithoutAFeed(): void
    {
        $this->givenSalesChannels(['ohne-feed' => 'Headless'], []);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT', 'CH']);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);

        self::assertSame(0, $this->createService()->combinationCount());
    }

    /**
     * Was: Die Vorabzählung für einen Kanal ohne konfigurierte Länder.
     * Warum: Der Lauf überspringt ihn; die Zählung muss dasselbe tun. Zählte sie ihn mit, wäre
     *        der Fortschrittsbalken von Anfang an falsch geeicht und stünde am Ende still.
     * Erwartet: null Kombinationen.
     */
    public function testTheCombinationCountIgnoresChannelsWithoutCountries(): void
    {
        $this->givenSalesChannels(['ohne-land' => 'Ohne Land']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn([]);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1']);

        self::assertSame(0, $this->createService()->combinationCount());
    }

    /**
     * @param array<string, string>   $salesChannels Kennung => Name
     * @param array<int, string>|null $readByAFeed   Kennungen, die ein Produktexport ausliest.
     *                                               `null` heißt: alle (der Regelfall der Tests).
     */
    /**
     * Was: Ein Produkt, dessen Prüfwert dem gespeicherten entspricht.
     * Warum: Dafür gibt es den Prüfwert. Ohne ihn rechnet der Warmup viermal täglich dasselbe
     *        Ergebnis, bei einem großen Sortiment über 21 000 Berechnungen am Tag, auch an einem
     *        Tag mit einer einzigen Bestellung.
     * Erwartet: keine Berechnung, stattdessen ein Auffrischen des Zeitstempels.
     */
    public function testUnchangedCombinationIsNotRecalculated(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);

        $this->fingerprintService->method('loadFingerprints')->willReturn(['produkt-1' => 'abc']);
        $this->priceStore->method('loadState')->willReturn([
            'produkt-1|DE' => ['hash' => 'abc', 'age' => 3600],
        ]);

        $this->calculator->expects(self::never())->method('calculate');
        $this->priceStore->expects(self::once())
            ->method('touch')
            ->with([['productId' => 'produkt-1', 'countryIso' => 'DE']], 'kanal-a');

        $result = $this->createService()->warmup();

        self::assertSame(1, $result->unchanged);
        self::assertSame(0, $result->total());
        self::assertSame(1, $result->inspected());
    }

    /**
     * Was: Derselbe Fall, aber der gespeicherte Prüfwert weicht ab.
     * Warum: Gewicht, Maße, Preis oder die Versandkonfiguration haben sich geändert. Dann muss
     *        gerechnet werden, sonst steht im Feed ein falscher Betrag.
     * Erwartet: eine Berechnung, und der neue Prüfwert wird mitgegeben.
     */
    public function testChangedFingerprintTriggersRecalculation(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);

        $this->fingerprintService->method('loadFingerprints')->willReturn(['produkt-1' => 'neu']);
        $this->priceStore->method('loadState')->willReturn([
            'produkt-1|DE' => ['hash' => 'alt', 'age' => 60],
        ]);

        $this->calculator->expects(self::once())
            ->method('calculate')
            ->with('produkt-1', 'DE', 'kanal-a', 'EUR', false, 'neu')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 8.93, 'EUR', false));

        $result = $this->createService()->warmup();

        self::assertSame(1, $result->calculated);
        self::assertSame(0, $result->unchanged);
    }

    /**
     * Was: Gleicher Prüfwert, aber der Eintrag ist älter als die Vollauf-Frist.
     * Warum: Das Sicherheitsnetz. Eine Änderung, die der Prüfwert nicht erfasst, darf höchstens
     *        sieben Tage unbemerkt bleiben, sonst steht ein falscher Betrag beliebig lange im Feed.
     * Erwartet: gerechnet, obwohl sich nichts geändert zu haben scheint.
     */
    public function testAnEntryOlderThanTheFullRecalculationIntervalIsRecalculated(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);

        $this->fingerprintService->method('loadFingerprints')->willReturn(['produkt-1' => 'abc']);
        $this->priceStore->method('loadState')->willReturn([
            'produkt-1|DE' => ['hash' => 'abc', 'age' => ShippingWarmupService::FULL_RECALCULATION_SECONDS],
        ]);

        $this->calculator->expects(self::once())
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 8.93, 'EUR', false));

        self::assertSame(1, $this->createService()->warmup()->calculated);
    }

    /**
     * Was: Der Schalter für den Lauf von Hand bei unverändertem Prüfwert.
     * Warum: Der Notausgang. Wer ihn zieht, will alles neu gerechnet haben und nicht hören, dass
     *        sich nichts geändert hat.
     * Erwartet: der Stand wird gar nicht erst gelesen, es wird gerechnet.
     */
    public function testForceRecalculatesEverything(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);

        $this->fingerprintService->method('loadFingerprints')->willReturn(['produkt-1' => 'abc']);
        $this->priceStore->expects(self::never())->method('loadState');

        $this->calculator->expects(self::once())
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 8.93, 'EUR', false));

        self::assertSame(1, $this->createService()->warmup(null, null, true)->calculated);
    }

    /**
     * Was: Ein abgeschlossener Lauf.
     * Warum: Shopware schreibt den Warenkorb beim Berechnen weg. Bleiben die Zeilen liegen, wächst
     *        die Datenbank um ein Gigabyte am Tag und die Warenkorb-Kennzahlen des Shops sind
     *        unbrauchbar. Der Lauf räumt seine eigene Spur weg; von außen ginge das nicht sicher.
     * Erwartet: genau ein Aufräumen am Ende.
     */
    public function testTheRunRemovesItsOwnCartTrace(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Beispielshop']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['produkt-1']);
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('produkt-1', 'DE', 8.93, 'EUR', false));

        $this->cartBuilder->expects(self::once())->method('removeTrace');

        $this->createService()->warmup();
    }

    private function givenSalesChannels(array $salesChannels, ?array $readByAFeed = null): void
    {
        $entities = [];
        foreach ($salesChannels as $id => $name) {
            $entity = new SalesChannelEntity();
            $entity->setUniqueIdentifier($id);
            $entity->setId($id);
            $entity->setName($name);
            $entities[$id] = $entity;
        }

        $searchResult = $this->createMock(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn(new SalesChannelCollection($entities));
        $this->salesChannelRepository->method('search')->willReturn($searchResult);

        $this->feedChannelProvider->method('loadReadingChannelIds')
            ->willReturn(array_fill_keys($readByAFeed ?? array_keys($salesChannels), true));
    }

    private function createService(): ShippingWarmupService
    {
        return new ShippingWarmupService(
            $this->calculator,
            new CalculationChannelProvider($this->configurationService, $this->contextFactory, $this->salesChannelRepository, $this->feedChannelProvider),
            $this->activeProductProvider,
            $this->cartBuilder,
            $this->fingerprintService,
            $this->priceStore,
            $this->logger,
        );
    }
}
