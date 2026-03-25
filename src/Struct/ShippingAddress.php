<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

/**
 * Referenzadresse eines Landes für die Versandkostenberechnung.
 *
 * Kein echter Empfänger — die Adresse dient ausschließlich dazu, Shopware eine
 * gültige Lieferadresse zu geben, damit die Versandzonenregeln greifen.
 */
final class ShippingAddress
{
    public function __construct(
        public readonly string $countryIso,
        public readonly string $city,
        public readonly string $zipCode,
        public readonly string $street,
    ) {
    }
}
