<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Struct;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;

/**
 * Die drei Gründe, aus denen ein Ergebnis der Ersatzwert ist.
 *
 * Sie sahen im Code lange gleich aus und bedeuten Gegensätzliches: „keine Versandart" ist ein
 * **Ergebnis** (der Shop versendet diese Ware nicht dorthin), „nicht vorberechnet" ein
 * **Betriebszustand** (der Speicher ist kalt, wir wissen nichts). Nur der erste rechtfertigt es,
 * im Feed zu schweigen.
 *
 * Die Werte werden seit Fassung 1.3.0 in einer Tabellenspalte abgelegt. Ändert jemand einen
 * Wert, sind alle vorhandenen Zeilen unlesbar — deshalb sind sie hier festgeschrieben.
 */
class FallbackReasonTest extends TestCase
{
    /**
     * Was: Die abgelegten Werte der drei Fälle.
     * Warum: Sie stehen in der Datenbank. Eine Umbenennung im Code ohne Migration macht jeden
     *        vorhandenen Eintrag zum unbekannten Grund.
     * Erwartet: genau diese drei Zeichenketten.
     */
    public function testTheStoredValuesAreFixed(): void
    {
        self::assertSame('no_shipping_method', FallbackReason::NoShippingMethod->value);
        self::assertSame('not_precomputed', FallbackReason::NotPrecomputed->value);
        self::assertSame('calculation_failed', FallbackReason::CalculationFailed->value);
    }

    /**
     * Was: Der Weg vom abgelegten Wert zurück zum Fall.
     * Warum: Genau diesen Weg geht der Speicher beim Lesen jeder Zeile.
     * Erwartet: jeder Wert findet seinen Fall zurück.
     */
    public function testEveryStoredValueMapsBackToItsCase(): void
    {
        foreach (FallbackReason::cases() as $case) {
            self::assertSame($case, FallbackReason::tryFrom($case->value));
        }
    }

    /**
     * Was: Ein Wert, den es nicht gibt.
     * Warum: Er kann aus einer älteren oder neueren Fassung stammen. Der Preis daneben bleibt
     *        gültig — nur die Begründung geht verloren. Eine Ausnahme hier würde den ganzen Feed
     *        reißen, weil eine einzelne unbekannte Zeile ihn abbräche.
     * Erwartet: null, keine Ausnahme.
     */
    public function testAnUnknownValueYieldsNullInsteadOfThrowing(): void
    {
        self::assertNull(FallbackReason::tryFrom('gibt-es-nicht'));
        self::assertNull(FallbackReason::tryFrom(''));
    }

    /**
     * Was: Die Beschriftung für die Konsole.
     * Warum: Sie ist nutzersichtbarer Text und bleibt deutsch — im Gegensatz zu den abgelegten
     *        Werten, die Code sind.
     * Erwartet: jeder Fall hat eine nichtleere deutsche Beschriftung.
     */
    public function testEveryCaseHasAGermanLabel(): void
    {
        self::assertSame('keine Versandart', FallbackReason::NoShippingMethod->label());
        self::assertSame('nicht vorberechnet', FallbackReason::NotPrecomputed->label());
        self::assertSame('Berechnung fehlgeschlagen', FallbackReason::CalculationFailed->label());
    }
}
