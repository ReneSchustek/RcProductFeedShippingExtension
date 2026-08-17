<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Command\ShippingCheckCommand;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Das Diagnose-Kommando: Wo greift kein regulärer Versandpreis, und warum?
 *
 * Es ist der Weg, den man geht, wenn im Feed Ersatzwerte auftauchen — die Warnung des
 * Warmup-Kommandos verweist ausdrücklich darauf. Ein Diagnosewerkzeug, das selbst falsch
 * zählt oder den falschen Exitcode liefert, schickt die Suche in die falsche Richtung.
 */
class ShippingCheckCommandTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private EntityRepository&MockObject $salesChannelRepository;
    private ActiveProductProviderService&MockObject $activeProductProvider;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->salesChannelRepository = $this->createMock(EntityRepository::class);
        $this->activeProductProvider = $this->createMock(ActiveProductProviderService::class);
    }

    /**
     * Was: Alle Kombinationen haben einen gerechneten Preis.
     * Warum: Der gute Fall muss mit 0 enden, sonst schlägt jede Überwachung ohne Anlass an.
     * Erwartet: Exitcode 0 und eine klare Aussage.
     */
    public function testAllRegularPricesResultInSuccess(): void
    {
        $this->givenOneEnabledChannel(['DE']);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1', 'p2']);
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('p1', 'DE', 17.26, 'EUR', false));

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('reguläre Versandkosten', $tester->getDisplay());
    }

    /**
     * Was: Einzelne Kombinationen fallen auf den Ersatzwert.
     * Warum: Das ist der Anlass, aus dem man das Kommando überhaupt aufruft. Es muss die Zahl
     *        nennen und mit 1 enden, damit eine Überwachung anschlägt.
     * Erwartet: Exitcode 1, die Zahl im Klartext.
     */
    public function testFallbacksAreCountedAndFailTheCommand(): void
    {
        $this->givenOneEnabledChannel(['DE', 'AT']);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1']);
        $this->calculator->method('calculate')->willReturn(
            new ShippingCalculationResult('p1', 'DE', 4.95, 'EUR', true, FallbackReason::NoShippingMethod)
        );

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('2 Kombination', $tester->getDisplay());
    }

    /**
     * Was: Die Aufschlüsselung nach Grund.
     * Warum: „100 Ersatzwerte" allein sagt nichts. „Davon 100-mal keine Versandart" heißt: Der
     *        Shop versendet dorthin nicht — kein Fehler. „Davon 100-mal nicht vorberechnet"
     *        heißt: Der Warmup läuft nicht. Zwei völlig verschiedene Suchen.
     * Erwartet: Jeder Grund erscheint mit seiner Anzahl.
     */
    public function testReasonsAreBrokenDownByCount(): void
    {
        $this->givenOneEnabledChannel(['DE', 'AT']);
        $this->activeProductProvider->method('loadActiveProductIds')->willReturn(['p1']);

        $this->calculator->method('calculate')->willReturnOnConsecutiveCalls(
            new ShippingCalculationResult('p1', 'DE', 4.95, 'EUR', true, FallbackReason::NoShippingMethod),
            new ShippingCalculationResult('p1', 'AT', 4.95, 'EUR', true, FallbackReason::NotPrecomputed),
        );

        $anzeige = $this->execute()->getDisplay();

        self::assertStringContainsString(FallbackReason::NoShippingMethod->label(), $anzeige);
        self::assertStringContainsString(FallbackReason::NotPrecomputed->label(), $anzeige);
    }

    /**
     * Was: Ein Kanal, dessen Berechnungs-Kanal keinen Context liefert.
     * Warum: Genau die Lage auf live-clone — der Google-Shopping-Kanal hat eine Sprache
     *        zugewiesen, die es nicht gibt. Ohne deutlichen Hinweis sucht man den Fehler bei
     *        den Versandarten statt bei der Kanalkonfiguration.
     * Erwartet: Warnung mit Kanalnamen, keine Berechnung.
     */
    public function testAChannelWithoutAUsableContextIsReportedNotCalculated(): void
    {
        $this->givenOneEnabledChannel(['DE'], 'Google Shopping');
        $this->contextFactory->method('create')->willThrowException(new \RuntimeException('keine Sprache'));
        $this->calculator->expects(self::never())->method('calculate');

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Google Shopping', $tester->getDisplay());
    }

    /**
     * Was: Ein Kanal, in dem das Plugin abgeschaltet ist.
     * Warum: Abschalten muss auch die Diagnose stilllegen — sonst meldet sie Ersatzwerte für
     *        einen Kanal, der die Werte gar nicht benutzt.
     * Erwartet: keine Berechnung, Exitcode 0.
     */
    public function testADisabledChannelIsNotChecked(): void
    {
        $this->givenSalesChannels(['kanal' => 'Abgeschaltet']);
        $this->configurationService->method('isEnabled')->willReturn(false);
        $this->calculator->expects(self::never())->method('calculate');

        self::assertSame(Command::SUCCESS, $this->execute()->getStatusCode());
    }

    /** @param array<int, string> $countries */
    private function givenOneEnabledChannel(array $countries, string $name = 'Trummer Edelstahl'): void
    {
        $this->givenSalesChannels(['kanal' => $name]);
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn($countries);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);
    }

    /** @param array<string, string> $channels Kennung => Name */
    private function givenSalesChannels(array $channels): void
    {
        $entities = [];
        foreach ($channels as $id => $name) {
            $entity = new SalesChannelEntity();
            $entity->setUniqueIdentifier($id);
            $entity->setId($id);
            $entity->setName($name);
            $entities[$id] = $entity;
        }

        $ergebnis = $this->createMock(EntitySearchResult::class);
        $ergebnis->method('getEntities')->willReturn(new SalesChannelCollection($entities));
        $this->salesChannelRepository->method('search')->willReturn($ergebnis);
    }

    private function execute(): CommandTester
    {
        $command = new ShippingCheckCommand(
            $this->calculator,
            $this->configurationService,
            $this->contextFactory,
            $this->salesChannelRepository,
            $this->activeProductProvider,
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
