<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

/**
 * Warum ein Ergebnis der Ersatzwert ist und nicht ein gerechneter Preis.
 *
 * Die Fälle sahen im Code lange gleich aus — mit gegensätzlicher Bedeutung. „Keine Versandart"
 * ist ein **Ergebnis**: Der Shop versendet diese Ware nicht in dieses Land, sie geht nur auf
 * Anfrage. „Nicht vorberechnet" ist ein **Betriebszustand**: Der Speicher ist kalt, wir wissen
 * nichts. „Berechnung fehlgeschlagen" ist ein **Fehler**, der ins Protokoll gehört. „Nicht
 * bestellbar" ist eine **Eigenschaft des Artikels**: Er lässt sich nicht in einen Warenkorb
 * legen, also gibt es für ihn keinen Versandpreis.
 *
 * Nur der erste Fall rechtfertigt es, den Block im Feed wegzulassen. Die anderen als
 * Geschäftsregel auszugeben, hieße einen Betriebszustand zur Aussage über das Sortiment zu machen.
 */
enum FallbackReason: string
{
    case NoShippingMethod = 'no_shipping_method';
    case NotPrecomputed = 'not_precomputed';
    case CalculationFailed = 'calculation_failed';
    case NotPurchasable = 'not_purchasable';

    /** Für die Konsole — kurz genug für eine Tabellenspalte. */
    public function label(): string
    {
        return match ($this) {
            self::NoShippingMethod => 'keine Versandart',
            self::NotPrecomputed => 'nicht vorberechnet',
            self::CalculationFailed => 'Berechnung fehlgeschlagen',
            self::NotPurchasable => 'nicht bestellbar',
        };
    }
}
