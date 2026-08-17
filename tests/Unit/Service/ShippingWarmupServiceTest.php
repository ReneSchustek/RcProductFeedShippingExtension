<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Der Warmup — der einzige Ort, an dem tatsächlich gerechnet wird.
 *
 * Warum das der wichtigste ungetestete Fleck war: Der Feed rechnet seit Fassung 1.1.0 nicht mehr
 * selbst, er liest nur noch vorberechnete Werte. Füllt der Warmup den Speicher nicht oder füllt
 * er ihn mit Ersatzwerten, liefert der Feed für jeden Artikel denselben Betrag — und niemand
 * merkt es, weil der Feed dabei vollständig aussieht.
 */
class ShippingWarmupServiceTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private EntityRepository&MockObject $salesChannelRepository;
    private ActiveProductProviderService&MockObject $activeProductProvider;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->activeProductProvider = $this->createMock(ActiveProductProviderService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Was: Zwei Produkte, drei Länder, ein Kanal.
     * Warum: Der Grundfall. Jede Kombination muss genau einmal gerechnet werden — nicht weniger
     *        (Lücken im Feed) und nicht mehr (bei 8217 Kombinationen kostet jede Dopplung Minuten).
     * Erwartet: sechs Berechnungen, sechs gezählte Treffer.
     */
    public function testEveryProductCountryCombinationIsCalculatedExactlyOnce(): void
    {
        $this->givenSalesChannels(['kanal-a' => 'Trummer Edelstahl']);
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
     * Warum: Genau das ist die Lage auf live-clone — Google Shopping und Headless rechnen beide
     *        über den Storefront-Kanal. Ohne Merkliste liefe der Warmup zweimal über denselben
     *        Bestand; bei 2739 Produkten sind das sechs Minuten für nichts.
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

        $meldungen = [];
        $result = $this->createService()->warmup(null, static function (string $text) use (&$meldungen): void {
            $meldungen[] = $text;
        });

        self::assertSame(1, $result->total());
        self::assertNotEmpty(array_filter($meldungen, static fn (string $m): bool => str_contains($m, 'bereits verarbeitet')));
    }

    /**
     * Was: Ein Kanal, für den sich kein Context bauen lässt.
     * Warum: Produktvergleichs-Kanäle haben oft keine vollständige Sprachkonfiguration — auf
     *        live-clone ist genau das der Fall. Ohne die Vorabprüfung erzeugte jedes einzelne
     *        Produkt denselben Fehler; bei 2112 Produkten sind das 2112 Protokollzeilen für
     *        einen einzigen Konfigurationsmangel.
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
        $this->givenSalesChannels(['kanal-a' => 'Trummer Edelstahl']);
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
        $this->givenSalesChannels(['kanal-a' => 'Trummer Edelstahl']);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('p1', 'DE', 8.93, 'EUR', false));

        $schritte = 0;
        $this->createService()->warmup(static function () use (&$schritte): void {
            ++$schritte;
        });

        self::assertSame(4, $schritte);
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

    /** @param array<string, string> $salesChannels Kennung => Name */
    private function givenSalesChannels(array $salesChannels): void
    {
        $entities = [];
        foreach ($salesChannels as $id => $name) {
            $entity = new SalesChannelEntity();
            $entity->setUniqueIdentifier($id);
            $entity->setId($id);
            $entity->setName($name);
            $entities[$id] = $entity;
        }

        $suchergebnis = $this->createMock(EntitySearchResult::class);
        $suchergebnis->method('getEntities')->willReturn(new SalesChannelCollection($entities));
        $this->salesChannelRepository->method('search')->willReturn($suchergebnis);
    }

    private function createService(): ShippingWarmupService
    {
        return new ShippingWarmupService(
            $this->calculator,
            $this->configurationService,
            $this->contextFactory,
            $this->salesChannelRepository,
            $this->activeProductProvider,
            $this->logger,
        );
    }
}
