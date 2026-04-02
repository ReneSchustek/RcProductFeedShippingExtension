<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;

class ShippingFallbackServiceTest extends TestCase
{
    private ConfigurationService&MockObject $configurationService;
    private ShippingFallbackService $service;

    protected function setUp(): void
    {
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->service = new ShippingFallbackService($this->configurationService);
    }

    public function testGetFallbackResultReturnsConfiguredPrice(): void
    {
        $this->configurationService
            ->method('getFallbackShippingCostForCountry')
            ->with('DE', 'sales-channel-id')
            ->willReturn(4.95);

        $result = $this->service->getFallbackResult('product-id', 'DE', 'sales-channel-id', 'EUR');

        self::assertSame(4.95, $result->shippingCost);
        self::assertSame('product-id', $result->productId);
        self::assertSame('DE', $result->countryIso);
        self::assertSame('EUR', $result->currencyIso);
    }

    public function testGetFallbackResultAlwaysSetsFallbackFlag(): void
    {
        $this->configurationService->method('getFallbackShippingCostForCountry')->willReturn(0.0);

        $result = $this->service->getFallbackResult('product-id', 'DE', 'sales-channel-id', 'EUR');

        self::assertTrue($result->isFallback);
    }
}
