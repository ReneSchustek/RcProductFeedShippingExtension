<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use RuhrCoder\RcProductFeedShippingExtension\Subscriber\ProductFeedSubscriber;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContext;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ProductFeedSubscriberTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ConfigurationService&MockObject $configurationService;
    private ProductFeedSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->subscriber = new ProductFeedSubscriber($this->calculator, $this->configurationService);
    }

    public function testDisabledPluginSkipsCalculation(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(false);

        $this->calculator->expects($this->never())->method('calculate');

        $event = $this->buildEvent('channel-id', ['DE'], true);
        $this->subscriber->onProductExportRender($event);
    }

    public function testEmptyCountriesSkipsCalculation(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn([]);

        $this->calculator->expects($this->never())->method('calculate');

        $event = $this->buildEvent('channel-id', [], true);
        $this->subscriber->onProductExportRender($event);
    }

    public function testCalculateIsCalledForEachConfiguredCountry(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT', 'CH']);

        $this->calculator
            ->expects($this->exactly(3))
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $event = $this->buildEvent('channel-id', ['DE', 'AT', 'CH'], true);
        $this->subscriber->onProductExportRender($event);
    }

    public function testExtensionIsSetOnProduct(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);

        $this->calculator
            ->method('calculate')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $event = $this->buildEvent('channel-id', ['DE'], true);
        $context = $event->getContext();
        /** @var ProductEntity $product */
        $product = $context['product'];

        $this->subscriber->onProductExportRender($event);

        $extension = $product->getExtension('rcShipping');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertSame(4.95, $extension['DE']);
    }

    public function testIndividualCountryFailureDoesNotStopOtherCountries(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);

        $this->calculator
            ->method('calculate')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('Failed')),
                new ShippingCalculationResult('product-id', 'AT', 9.90, 'EUR', false),
            );

        $event = $this->buildEvent('channel-id', ['DE', 'AT'], true);
        $context = $event->getContext();
        /** @var ProductEntity $product */
        $product = $context['product'];

        $this->subscriber->onProductExportRender($event);

        $extension = $product->getExtension('rcShipping');
        self::assertInstanceOf(ArrayStruct::class, $extension);
        self::assertFalse(isset($extension['DE']));
        self::assertSame(9.90, $extension['AT']);
    }

    private function buildEvent(string $salesChannelId, array $countries, bool $enabled): ProductExportRenderBodyContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getCurrency')->willReturn($currency);

        $product = new ProductEntity();
        $product->setId('product-id');

        return new ProductExportRenderBodyContext([
            'product' => $product,
            'salesChannelContext' => $salesChannelContext,
        ]);
    }
}
