<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use RuhrCoder\RcProductFeedShippingExtension\Exception\CountryNotFoundException;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingAddressProviderService;

class ShippingAddressProviderServiceTest extends TestCase
{
    private ShippingAddressProviderService $service;

    protected function setUp(): void
    {
        $this->service = new ShippingAddressProviderService();
    }

    public function testGetReferenceAddressReturnsKasselForGermany(): void
    {
        $address = $this->service->getReferenceAddress('DE');

        self::assertSame('DE', $address->countryIso);
        self::assertSame('Kassel', $address->city);
        self::assertSame('34117', $address->zipCode);
        self::assertSame('Königsplatz 1', $address->street);
    }

    public function testGetReferenceAddressReturnsViennaForAustria(): void
    {
        $address = $this->service->getReferenceAddress('AT');

        self::assertSame('AT', $address->countryIso);
        self::assertSame('Wien', $address->city);
    }

    public function testGetReferenceAddressThrowsForUnknownCountry(): void
    {
        $this->expectException(CountryNotFoundException::class);
        $this->expectExceptionMessage('XX');

        $this->service->getReferenceAddress('XX');
    }

    public function testGetReferenceAddressNormalizesLowercase(): void
    {
        $address = $this->service->getReferenceAddress('de');

        self::assertSame('DE', $address->countryIso);
        self::assertSame('Kassel', $address->city);
    }

    public function testHasCountryReturnsTrueForKnownCountry(): void
    {
        self::assertTrue($this->service->hasCountry('DE'));
    }

    public function testHasCountryReturnsFalseForUnknownCountry(): void
    {
        self::assertFalse($this->service->hasCountry('XX'));
    }

    public function testGetSupportedCountryCodesContainsCoreMarkets(): void
    {
        $codes = $this->service->getSupportedCountryCodes();

        self::assertContains('DE', $codes);
        self::assertContains('AT', $codes);
        self::assertContains('CH', $codes);
    }
}
