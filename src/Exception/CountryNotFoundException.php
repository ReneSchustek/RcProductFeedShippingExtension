<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Exception;

/**
 * Wird geworfen wenn für einen ISO-Code keine Referenzadresse hinterlegt ist.
 */
class CountryNotFoundException extends \RuntimeException
{
    public function __construct(string $countryIso)
    {
        parent::__construct(sprintf('No reference address found for country ISO: %s', $countryIso));
    }
}
