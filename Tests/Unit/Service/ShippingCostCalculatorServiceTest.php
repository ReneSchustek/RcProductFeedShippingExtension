<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuhrCoder\RcProductFeedShippingExtension\Cache\ShippingCacheService;
use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingAddressProviderService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use RuhrCoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ShippingCostCalculatorServiceTest extends TestCase
{
    private ShippingAddressProviderService&MockObject $addressProvider;
    private VirtualCartBuilderService&MockObject $cartBuilder;
    private ShippingFallbackService&MockObject $fallbackService;
    private ShippingCacheService&MockObject $cacheService;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private EntityRepository&MockObject $countryRepository;
    private LoggerInterface&MockObject $logger;
    private ShippingCostCalculatorService $service;

    protected function setUp(): void
    {
        $this->addressProvider = $this->createMock(ShippingAddressProviderService::class);
        $this->cartBuilder = $this->createMock(VirtualCartBuilderService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->cacheService = $this->createMock(ShippingCacheService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->countryRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ShippingCostCalculatorService(
            $this->addressProvider,
            $this->cartBuilder,
            $this->fallbackService,
            $this->cacheService,
            $this->configurationService,
            $this->contextFactory,
            $this->countryRepository,
            $this->logger,
        );
    }

    public function testReturnsCachedResultWithoutCalculating(): void
    {
        $cached = new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false);

        $this->cacheService->method('get')->willReturn($cached);
        $this->cartBuilder->expects($this->never())->method('buildCalculatedCart');

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertSame($cached, $result);
    }

    public function testSuccessfulCalculationReturnsFalseIsFallback(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->configurationService->method('getExcludedShippingMethods')->willReturn([]);

        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('firstId')->willReturn('country-uuid');
        $this->countryRepository->method('searchIds')->willReturn($idResult);

        $context = $this->createMock(SalesChannelContext::class);
        $this->contextFactory->method('create')->willReturn($context);

        $cart = $this->buildCartWithDelivery(4.95, 'Standard');
        $this->cartBuilder->method('buildCalculatedCart')->willReturn($cart);

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertFalse($result->isFallback);
        self::assertSame(4.95, $result->shippingCost);
    }

    public function testExceptionTriggersFallback(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->countryRepository->method('searchIds')->willThrowException(new \RuntimeException('DB error'));

        $fallback = new ShippingCalculationResult('product-id', 'DE', 9.99, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertTrue($result->isFallback);
    }

    public function testExceptionIsLogged(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->countryRepository->method('searchIds')->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        $fallback = new ShippingCalculationResult('product-id', 'DE', 0.0, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $this->service->calculate('product-id', 'DE', 'channel-id');
    }

    public function testEmptyDeliveriesReturnsZero(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->configurationService->method('getExcludedShippingMethods')->willReturn([]);

        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('firstId')->willReturn('country-uuid');
        $this->countryRepository->method('searchIds')->willReturn($idResult);

        $context = $this->createMock(SalesChannelContext::class);
        $this->contextFactory->method('create')->willReturn($context);

        $cart = new Cart('test');
        $this->cartBuilder->method('buildCalculatedCart')->willReturn($cart);

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertSame(0.0, $result->shippingCost);
    }

    public function testSelbstabholungIsFilteredOut(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->configurationService->method('getExcludedShippingMethods')->willReturn(['Selbstabholung']);

        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('firstId')->willReturn('country-uuid');
        $this->countryRepository->method('searchIds')->willReturn($idResult);

        $context = $this->createMock(SalesChannelContext::class);
        $this->contextFactory->method('create')->willReturn($context);

        $cart = $this->buildCartWithDelivery(0.0, 'Selbstabholung Filiale');
        $this->cartBuilder->method('buildCalculatedCart')->willReturn($cart);

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertSame(0.0, $result->shippingCost);
        self::assertFalse($result->isFallback);
    }

    public function testLowestPriceAfterFilteringIsReturned(): void
    {
        $this->cacheService->method('get')->willReturn(null);
        $this->configurationService->method('getExcludedShippingMethods')->willReturn(['Abholung']);

        $idResult = $this->createMock(IdSearchResult::class);
        $idResult->method('firstId')->willReturn('country-uuid');
        $this->countryRepository->method('searchIds')->willReturn($idResult);

        $context = $this->createMock(SalesChannelContext::class);
        $this->contextFactory->method('create')->willReturn($context);

        $cart = new Cart('test');
        $deliveries = new DeliveryCollection([
            $this->buildDelivery(0.0, 'Selbstabholung'),
            $this->buildDelivery(9.90, 'Express'),
            $this->buildDelivery(4.95, 'Standard'),
        ]);
        $cart->setDeliveries($deliveries);
        $this->cartBuilder->method('buildCalculatedCart')->willReturn($cart);

        $result = $this->service->calculate('product-id', 'DE', 'channel-id');

        self::assertSame(4.95, $result->shippingCost);
    }

    private function buildCartWithDelivery(float $cost, string $methodName): Cart
    {
        $cart = new Cart('test');
        $deliveries = new DeliveryCollection([$this->buildDelivery($cost, $methodName)]);
        $cart->setDeliveries($deliveries);

        return $cart;
    }

    private function buildDelivery(float $cost, string $methodName): Delivery
    {
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setName($methodName);

        $price = new CalculatedPrice($cost, $cost, new CalculatedTaxCollection(), new TaxRuleCollection());

        $delivery = $this->createMock(Delivery::class);
        $delivery->method('getShippingMethod')->willReturn($shippingMethod);
        $delivery->method('getShippingCosts')->willReturn($price);

        return $delivery;
    }
}
