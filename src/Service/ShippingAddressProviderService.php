<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Ruhrcoder\RcProductFeedShippingExtension\Exception\CountryNotFoundException;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingAddress;

/**
 * Liefert feste Referenzadressen pro Land für die Versandkostenberechnung.
 *
 * Die Adressen sind bewusst statisch gehalten — es geht nicht um reale Empfänger,
 * sondern um reproduzierbare Versandzonenzuordnungen. Deutschland verwendet Kassel
 * statt Berlin, weil Berlin in Versandzone 1 liegt und damit günstigere Preise
 * liefern würde als die meisten anderen deutschen Postleitzahlen.
 */
final class ShippingAddressProviderService
{
    private const ADDRESSES = [
        // Kernmärkte
        'DE' => ['city' => 'Kassel',         'zip' => '34117',     'street' => 'Königsplatz 1'],
        'AT' => ['city' => 'Wien',            'zip' => '1010',      'street' => 'Stephansplatz 1'],
        'CH' => ['city' => 'Bern',            'zip' => '3011',      'street' => 'Bundesplatz 1'],
        // Europa (EU)
        'BE' => ['city' => 'Brüssel',         'zip' => '1000',      'street' => 'Grand-Place 1'],
        'BG' => ['city' => 'Sofia',           'zip' => '1000',      'street' => 'Ploshtad Nezavisimost 1'],
        'CY' => ['city' => 'Nikosia',         'zip' => '1010',      'street' => 'Eleftheria Square 1'],
        'CZ' => ['city' => 'Prag',            'zip' => '11000',     'street' => 'Staroměstské náměstí 1'],
        'DK' => ['city' => 'Kopenhagen',      'zip' => '1050',      'street' => 'Rådhuspladsen 1'],
        'EE' => ['city' => 'Tallinn',         'zip' => '10111',     'street' => 'Vabaduse väljak 1'],
        'ES' => ['city' => 'Madrid',          'zip' => '28001',     'street' => 'Puerta del Sol 1'],
        'FI' => ['city' => 'Helsinki',        'zip' => '00100',     'street' => 'Senaatintori 1'],
        'FR' => ['city' => 'Paris',           'zip' => '75001',     'street' => 'Place du Louvre 1'],
        'GR' => ['city' => 'Athen',           'zip' => '10551',     'street' => 'Syntagma Square 1'],
        'HR' => ['city' => 'Zagreb',          'zip' => '10000',     'street' => 'Trg bana Jelačića 1'],
        'HU' => ['city' => 'Budapest',        'zip' => '1011',      'street' => 'Dísz tér 1'],
        'IE' => ['city' => 'Dublin',          'zip' => 'D01 F5P2',  'street' => 'College Green 1'],
        'IT' => ['city' => 'Rom',             'zip' => '00118',     'street' => 'Piazza Venezia 1'],
        'LT' => ['city' => 'Vilnius',         'zip' => 'LT-01100',  'street' => 'Katedros aikštė 1'],
        'LU' => ['city' => 'Luxemburg',       'zip' => '1009',      'street' => 'Place Guillaume II 1'],
        'LV' => ['city' => 'Riga',            'zip' => 'LV-1050',   'street' => 'Doma laukums 1'],
        'MT' => ['city' => 'Valletta',        'zip' => 'VLT 1110',  'street' => 'Republic Street 1'],
        'NL' => ['city' => 'Amsterdam',       'zip' => '1011',      'street' => 'Dam 1'],
        'PL' => ['city' => 'Warschau',        'zip' => '00-001',    'street' => 'Plac Zamkowy 1'],
        'PT' => ['city' => 'Lissabon',        'zip' => '1000-001',  'street' => 'Praça do Comércio 1'],
        'RO' => ['city' => 'Bukarest',        'zip' => '010011',    'street' => 'Piața Revoluției 1'],
        'SE' => ['city' => 'Stockholm',       'zip' => '11120',     'street' => 'Stortorget 1'],
        'SI' => ['city' => 'Ljubljana',       'zip' => '1000',      'street' => 'Prešernov trg 1'],
        'SK' => ['city' => 'Bratislava',      'zip' => '81101',     'street' => 'Hlavné námestie 1'],
        // Europa (Nicht-EU)
        'GB' => ['city' => 'London',          'zip' => 'SW1A 1AA',  'street' => 'Whitehall 1'],
        'NO' => ['city' => 'Oslo',            'zip' => '0026',      'street' => 'Slottsplassen 1'],
        'IS' => ['city' => 'Reykjavik',       'zip' => '101',       'street' => 'Austurvöllur 1'],
        'LI' => ['city' => 'Vaduz',           'zip' => '9490',      'street' => 'Städtle 49'],
        // International
        'US' => ['city' => 'Washington D.C.', 'zip' => '20001',     'street' => 'Pennsylvania Avenue 1'],
        'CA' => ['city' => 'Ottawa',          'zip' => 'K1P 5J2',   'street' => 'Wellington Street 1'],
        'AU' => ['city' => 'Canberra',        'zip' => '2600',      'street' => 'Parliament Drive 1'],
    ];

    /**
     * Gibt die Referenzadresse für ein Land zurück.
     *
     * @throws CountryNotFoundException wenn für den ISO-Code keine Adresse hinterlegt ist
     */
    public function getReferenceAddress(string $countryIso): ShippingAddress
    {
        $iso = strtoupper($countryIso);

        if (!isset(self::ADDRESSES[$iso])) {
            throw new CountryNotFoundException($iso);
        }

        $data = self::ADDRESSES[$iso];

        return new ShippingAddress(
            countryIso: $iso,
            city: $data['city'],
            zipCode: $data['zip'],
            street: $data['street'],
        );
    }

    /** Prüft ob für den angegebenen ISO-Code eine Referenzadresse vorhanden ist. */
    public function hasCountry(string $countryIso): bool
    {
        return isset(self::ADDRESSES[strtoupper($countryIso)]);
    }

    /** Gibt alle unterstützten ISO-Codes zurück. */
    public function getSupportedCountryCodes(): array
    {
        return array_keys(self::ADDRESSES);
    }
}
