<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Configuration;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationServiceTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfigService;
    private ConfigurationService $service;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->service = new ConfigurationService($this->systemConfigService);
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->service->isEnabled('channel-id'));
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $this->systemConfigService->method('get')->willReturn(false);

        self::assertFalse($this->service->isEnabled('channel-id'));
    }

    public function testGetCountriesReturnsUppercaseIsoCodes(): void
    {
        $this->systemConfigService->method('getString')->willReturn('de,at,ch');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesTrimsWhitespace(): void
    {
        $this->systemConfigService->method('getString')->willReturn(' DE , AT , CH ');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesIgnoresEmptyEntries(): void
    {
        $this->systemConfigService->method('getString')->willReturn('DE,,AT,,,CH');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesReturnsEmptyArrayForEmptyString(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getCountries('channel-id');

        self::assertSame([], $result);
    }

    public function testGetFallbackShippingCostNormalizesNegativeToZero(): void
    {
        $this->systemConfigService->method('get')->willReturn(-5.0);

        $result = $this->service->getFallbackShippingCost('channel-id');

        self::assertSame(0.0, $result);
    }

    public function testGetFallbackShippingCostReturnsZeroForNull(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        $result = $this->service->getFallbackShippingCost('channel-id');

        self::assertSame(0.0, $result);
    }

    public function testGetFallbackShippingCostForCountryReturnsCountrySpecificValue(): void
    {
        $this->systemConfigService->method('getString')
            ->willReturn('DE:4.95,AT:9.90,CH:14.90');

        $result = $this->service->getFallbackShippingCostForCountry('AT', 'channel-id');

        self::assertSame(9.90, $result);
    }

    public function testGetFallbackShippingCostForCountryIsCaseInsensitive(): void
    {
        $this->systemConfigService->method('getString')
            ->willReturn('de:4.95');

        $result = $this->service->getFallbackShippingCostForCountry('DE', 'channel-id');

        self::assertSame(4.95, $result);
    }

    public function testGetExcludedShippingMethodsReturnsDefaultsForEmptyConfig(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getExcludedShippingMethods('channel-id');

        self::assertSame(['Selbstabholung', 'Abholung', 'Pickup'], $result);
    }

    public function testGetExcludedShippingMethodsReturnsConfiguredValues(): void
    {
        $this->systemConfigService->method('getString')->willReturn('Express,Spedition');

        $result = $this->service->getExcludedShippingMethods('channel-id');

        self::assertSame(['Express', 'Spedition'], $result);
    }

    public function testGetCalculationSalesChannelIdReturnsNullForEmptyValue(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getCalculationSalesChannelId('channel-id');

        self::assertNull($result);
    }

    public function testGetCalculationSalesChannelIdReturnsTrimmedValue(): void
    {
        $this->systemConfigService->method('getString')->willReturn('  abc-123  ');

        $result = $this->service->getCalculationSalesChannelId('channel-id');

        self::assertSame('abc-123', $result);
    }
}
