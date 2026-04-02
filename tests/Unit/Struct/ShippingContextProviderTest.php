<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Struct;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;

class ShippingContextProviderTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ShippingFallbackService&MockObject $fallbackService;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
    }

    public function testGetReturnsNullForUnconfiguredCountry(): void
    {
        $provider = $this->buildProvider(['DE', 'AT']);

        self::assertNull($provider->get('product-id', 'CH'));
    }

    public function testGetReturnsPriceForConfiguredCountry(): void
    {
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(4.95, $provider->get('product-id', 'DE'));
    }

    public function testGetIsCaseInsensitive(): void
    {
        $this->calculator->method('calculate')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(4.95, $provider->get('product-id', 'de'));
    }

    public function testGetReturnsFallbackOnCalculatorException(): void
    {
        $this->calculator->method('calculate')
            ->willThrowException(new \RuntimeException('Error'));

        $this->fallbackService->method('getFallbackResult')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 9.99, 'EUR', true));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(9.99, $provider->get('product-id', 'DE'));
    }

    public function testGetCountriesReturnsConfiguredCountries(): void
    {
        $provider = $this->buildProvider(['DE', 'AT', 'CH']);

        self::assertSame(['DE', 'AT', 'CH'], $provider->getCountries());
    }

    private function buildProvider(array $countries): ShippingContextProvider
    {
        return new ShippingContextProvider(
            $this->calculator,
            $this->fallbackService,
            $countries,
            'sales-channel-id',
            'EUR',
        );
    }
}
