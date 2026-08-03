<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Command\ShippingWarmupCommand;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\WarmupResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Das Kommando, das den Speicher von Hand füllt.
 *
 * Der Exitcode ist hier kein Beiwerk: Er entscheidet, ob ein Aufspiel-Skript oder ein Cronjob
 * merkt, dass der Warmup zwar durchlief, aber nichts gerechnet hat. Genau dieser Zustand bestand
 * monatelang, ohne dass ihn etwas gemeldet hätte.
 */
class ShippingWarmupCommandTest extends TestCase
{
    private ShippingWarmupService&MockObject $warmupService;

    protected function setUp(): void
    {
        $this->warmupService = $this->createMock(ShippingWarmupService::class);
    }

    /**
     * Was: Ein Lauf, der Preise berechnet hat.
     * Warum: Der Normalfall muss mit 0 enden, sonst bricht jedes Aufspiel-Skript ab.
     * Erwartet: Exitcode 0 und die Zählung im Klartext.
     */
    public function testASuccessfulRunReportsTheCountsAndSucceeds(): void
    {
        $result = new WarmupResult();
        $result->record(false);
        $result->record(false);
        $result->record(true);

        $this->warmupService->method('combinationCount')->willReturn(3);
        $this->warmupService->method('warmup')->willReturn($result);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Berechnet: 2', $tester->getDisplay());
        self::assertStringContainsString('Ersatzwert: 1', $tester->getDisplay());
        self::assertStringContainsString('Gesamt: 3', $tester->getDisplay());
    }

    /**
     * Was: Ein Lauf, der ausschließlich Ersatzwerte eingetragen hat.
     * Warum: **Der wichtigste Test dieser Klasse.** Technisch lief alles durch — fachlich ist das
     *        Ergebnis wertlos, weil der Feed danach für jeden Artikel denselben Betrag nennt. Ohne
     *        Exitcode 1 sieht das für jedes Skript wie Erfolg aus.
     * Erwartet: Exitcode 1 und ein Hinweis, wo zu suchen ist.
     */
    public function testARunWithOnlyFallbacksFailsWithAPointer(): void
    {
        $result = new WarmupResult();
        $result->record(true);
        $result->record(true);

        $this->warmupService->method('combinationCount')->willReturn(2);
        $this->warmupService->method('warmup')->willReturn($result);

        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('rc:shipping:check', $tester->getDisplay());
    }

    /**
     * Was: Es gibt nichts zu rechnen.
     * Warum: Kein aktiver Kanal mit eingeschaltetem Plugin ist ein Konfigurationszustand, kein
     *        Fehler. Ein Exitcode 1 hier ließe eine Aufspiel-Kette scheitern, obwohl nichts
     *        kaputt ist — und der Warmup darf gar nicht erst anlaufen.
     * Erwartet: Exitcode 0, deutliche Warnung, kein Lauf.
     */
    public function testNothingToDoWarnsButSucceeds(): void
    {
        $this->warmupService->method('combinationCount')->willReturn(0);
        $this->warmupService->expects(self::never())->method('warmup');

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Nichts zu tun', $tester->getDisplay());
    }

    /**
     * Was: Übersprungene Kanäle.
     * Warum: Sie sind die häufigste Ursache dafür, dass der Feed nichts bekommt. Stehen sie nicht
     *        in der Ausgabe, sucht man den Fehler im Code statt in der Konfiguration.
     * Erwartet: jeder übersprungene Kanal erscheint mit seinem Grund.
     */
    public function testSkippedChannelsAreShownWithTheirReason(): void
    {
        $result = new WarmupResult();
        $result->record(false);
        $result->channelSkipped('Google Shopping', 'kein gültiger Berechnungs-Kanal');

        $this->warmupService->method('combinationCount')->willReturn(1);
        $this->warmupService->method('warmup')->willReturn($result);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Google Shopping', $tester->getDisplay());
        self::assertStringContainsString('kein gültiger Berechnungs-Kanal', $tester->getDisplay());
    }

    private function execute(): CommandTester
    {
        $tester = new CommandTester(new ShippingWarmupCommand($this->warmupService));
        $tester->execute([]);

        return $tester;
    }
}
