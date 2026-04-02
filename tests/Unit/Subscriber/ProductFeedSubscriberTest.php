<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Subscriber\ProductFeedSubscriber;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContext;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ProductFeedSubscriberTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ShippingFallbackService&MockObject $fallbackService;
    private ConfigurationService&MockObject $configurationService;
    private ProductFeedSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->subscriber = new ProductFeedSubscriber(
            $this->calculator,
            $this->fallbackService,
            $this->configurationService,
        );
    }

    public function testDisabledPluginSetsNoProvider(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(false);

        $event = $this->buildEvent('channel-id', ['DE']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    public function testEmptyCountriesSetsNoProvider(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn([]);

        $event = $this->buildEvent('channel-id', []);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    public function testProviderIsInjectedIntoContext(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);

        $event = $this->buildEvent('channel-id', ['DE', 'AT']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayHasKey('rcShipping', $event->getContext());
        self::assertInstanceOf(ShippingContextProvider::class, $event->getContext()['rcShipping']);
    }

    public function testCalculationSalesChannelIdIsUsedWhenConfigured(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn('calc-channel-id');

        $event = $this->buildEvent('feed-channel-id', ['DE']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayHasKey('rcShipping', $event->getContext());
    }

    public function testMissingSalesChannelContextSkipsInjection(): void
    {
        $event = new ProductExportRenderBodyContext([]);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    private function buildEvent(string $salesChannelId, array $countries): ProductExportRenderBodyContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getCurrency')->willReturn($currency);

        return new ProductExportRenderBodyContext([
            'context' => $salesChannelContext,
        ]);
    }
}
