<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Exception;

/**
 * Wird geworfen wenn für einen ISO-Code keine Referenzadresse hinterlegt ist.
 */
final class CountryNotFoundException extends \RuntimeException
{
    public function __construct(string $countryIso)
    {
        parent::__construct(sprintf('No reference address found for country ISO: %s', $countryIso));
    }
}
