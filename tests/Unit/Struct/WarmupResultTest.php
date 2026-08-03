<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\WarmupResult;

/**
 * Das Zählwerk eines Warmup-Laufs.
 *
 * Klein, aber nicht nebensächlich: An `fallbacksOnly()` hängt der Exitcode des Kommandos. Meldet
 * es fälschlich Erfolg, läuft die geplante Aufgabe still weiter, während der Feed für jeden
 * Artikel den Ersatzwert nennt — genau der Zustand, der monatelang unbemerkt blieb.
 */
class WarmupResultTest extends TestCase
{
    /**
     * Was: Ein frisches Zählwerk.
     * Warum: Der Ausgangszustand darf keinen Lauf als auffällig melden, sonst schlägt ein
     *        Kommando fehl, das noch gar nichts getan hat.
     * Erwartet: Alles null, nicht auffällig.
     */
    public function testFreshResultIsEmptyAndNotSuspicious(): void
    {
        $result = new WarmupResult();

        self::assertSame(0, $result->calculated);
        self::assertSame(0, $result->fallback);
        self::assertSame(0, $result->total());
        self::assertSame([], $result->skippedChannels);
        self::assertFalse($result->fallbacksOnly());
    }

    /**
     * Was: Gerechnete und ersetzte Werte werden getrennt gezählt.
     * Warum: Die Trennung ist die eigentliche Aussage des Laufs. Eine Gesamtzahl allein sagt
     *        nicht, ob gerechnet oder geraten wurde.
     * Erwartet: Zwei Zähler, die Summe stimmt.
     */
    public function testCalculatedAndFallbackAreCountedSeparately(): void
    {
        $result = new WarmupResult();
        $result->record(false);
        $result->record(false);
        $result->record(true);

        self::assertSame(2, $result->calculated);
        self::assertSame(1, $result->fallback);
        self::assertSame(3, $result->total());
    }

    /**
     * Was: Ein Lauf, der ausschließlich Ersatzwerte einträgt.
     * Warum: Das ist der Alarmfall — es gab etwas zu rechnen, gerechnet wurde nichts. Dann stimmt
     *        etwas Grundsätzliches: Berechnungs-Kanal, Versandarten oder Regeln.
     * Erwartet: auffällig.
     */
    public function testARunWithNothingCalculatedIsSuspicious(): void
    {
        $result = new WarmupResult();
        $result->record(true);
        $result->record(true);

        self::assertTrue($result->fallbacksOnly());
    }

    /**
     * Was: Ein einziger gerechneter Wert unter lauter Ersatzwerten.
     * Warum: „Auffällig" heißt „gar nichts gerechnet", nicht „überwiegend Ersatzwerte". Auf
     *        live-clone sind 1943 von 8217 Kombinationen Ersatzwerte, und das ist ein gesunder
     *        Lauf — die Ware geht dorthin schlicht nicht.
     * Erwartet: nicht auffällig.
     */
    public function testASingleCalculatedValueClearsTheAlarm(): void
    {
        $result = new WarmupResult();
        $result->record(true);
        $result->record(true);
        $result->record(false);

        self::assertFalse($result->fallbacksOnly());
    }

    /**
     * Was: Ein leerer Lauf.
     * Warum: Gab es nichts zu rechnen, ist das kein Fehler — etwa wenn kein Verkaufskanal das
     *        Plugin aktiviert hat. Ein Alarm hier wäre ein Fehlalarm mit Exitcode 1.
     * Erwartet: nicht auffällig.
     */
    public function testAnEmptyRunIsNotSuspicious(): void
    {
        self::assertFalse((new WarmupResult())->fallbacksOnly());
    }

    /**
     * Was: Übersprungene Kanäle werden mit Grund festgehalten.
     * Warum: Ein übersprungener Kanal ist die häufigste Ursache dafür, dass der Feed nichts
     *        bekommt. Ohne den Grund sucht man ihn im Code statt in der Konfiguration.
     * Erwartet: Name und Grund stehen zusammen in der Liste.
     */
    public function testSkippedChannelsAreRecordedWithTheirReason(): void
    {
        $result = new WarmupResult();
        $result->channelSkipped('Google Shopping', 'kein gültiger Berechnungs-Kanal');
        $result->channelSkipped('Headless', 'bereits verarbeitet');

        self::assertCount(2, $result->skippedChannels);
        self::assertSame('Google Shopping: kein gültiger Berechnungs-Kanal', $result->skippedChannels[0]);
        self::assertSame('Headless: bereits verarbeitet', $result->skippedChannels[1]);
    }
}
