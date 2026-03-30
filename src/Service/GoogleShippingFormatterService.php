<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

/**
 * Formatiert Versandkosten als Google Shopping Feed-String.
 *
 * Die Feed-Integration nutzt derzeit das Twig-Template zur Ausgabe.
 * Diese Klasse deckt den Fall ab, wenn Versandkosten programmatisch
 * als fertiger String benötigt werden — z.B. für alternative Export-Formate.
 */
final class GoogleShippingFormatterService
{
    /**
     * Gibt die Versandkosten als kommaseparierten Google-Shopping-String zurück.
     *
     * Format je Land: `DE:::4.95 EUR` — die drei Doppelpunkte stehen für
     * Service-Name und Region, die Google optional akzeptiert aber nicht erfordert.
     *
     * @param array<string, float> $shippingCosts ISO-Code als Schlüssel, Preis als Wert
     */
    public function format(array $shippingCosts, string $currencyIso = 'EUR'): string
    {
        $parts = [];

        foreach ($shippingCosts as $countryIso => $cost) {
            $parts[] = sprintf('%s:::%s %s', $countryIso, number_format((float) $cost, 2, '.', ''), $currencyIso);
        }

        return implode(',', $parts);
    }
}
