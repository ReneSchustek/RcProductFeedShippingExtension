<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Cache\ShippingCacheService;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingAddressProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingAddress;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceCollection;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ShippingCostCalculatorServiceTest extends TestCase
{
    private const PRODUCT_ID = 'product-id';
    private const SALES_CHANNEL_ID = 'channel-id';
    private const CURRENCY_ID = 'currency-id';

    private VirtualCartBuilderService&MockObject $cartBuilder;
    private ShippingFallbackService&MockObject $fallbackService;
    private ShippingCacheService&MockObject $cacheService;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private ShippingAddressProviderService&MockObject $addressProvider;
    private EntityRepository&MockObject $countryRepository;
    private EntityRepository&MockObject $shippingMethodRepository;
    private LoggerInterface&MockObject $logger;
    private ShippingCostCalculatorService $service;

    protected function setUp(): void
    {
        $this->cartBuilder = $this->createMock(VirtualCartBuilderService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->cacheService = $this->createMock(ShippingCacheService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->addressProvider = $this->createMock(ShippingAddressProviderService::class);
        $this->countryRepository = $this->createMock(EntityRepository::class);
        $this->shippingMethodRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ShippingCostCalculatorService(
            $this->cartBuilder,
            $this->fallbackService,
            $this->cacheService,
            $this->configurationService,
            $this->contextFactory,
            $this->addressProvider,
            $this->countryRepository,
            $this->shippingMethodRepository,
            $this->logger,
        );
    }

    public function testReturnsCachedResultWithoutCalculating(): void
    {
        $cached = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 4.95, 'EUR', false);

        $this->cacheService->method('get')->willReturn($cached);
        $this->cartBuilder->expects($this->never())->method('buildCalculatedCart');

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame($cached, $result);
    }

    public function testSuccessfulCalculationReturnsFalseIsFallback(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(4.95, $result->shippingCost);
    }

    public function testFreeShippingZeroIsValidResult(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Kostenlos', null, null, 0.0),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(0.0, $result->shippingCost);
    }

    public function testExceptionTriggersFallback(): void
    {
        $this->setUpCacheMiss();
        $this->contextFactory->method('create')
            ->willThrowException(new \RuntimeException('DB error'));

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 9.99, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
    }

    public function testExceptionIsLogged(): void
    {
        $this->setUpCacheMiss();
        $this->contextFactory->method('create')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 0.0, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);
    }

    public function testNoShippingMethodsTriggersFallback(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([]);

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 4.95, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
    }

    public function testExcludedMethodIsSkipped(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], ['Selbstabholung']);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Selbstabholung Filiale', null, null, 0.0),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testCheapestMethodWins(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Express', null, null, 9.90),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testMethodWithNonMatchingAvailabilityRuleIsSkipped(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext(['rule-1']);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Spedition', 'rule-99', null, 2.00),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testResultIsCached(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $this->cacheService->expects($this->once())->method('set');

        $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);
    }

    /**
     * @param array<int, string> $matchedRuleIds
     * @param array<int, string> $excludedMethods
     */
    private function setUpSuccessfulContext(array $matchedRuleIds, array $excludedMethods = []): void
    {
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getId')->willReturn(self::CURRENCY_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getRuleIds')->willReturn($matchedRuleIds);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $this->contextFactory->method('create')->willReturn($context);

        $this->addressProvider->method('getReferenceAddress')
            ->willReturn(new ShippingAddress('DE', 'Kassel', '34117', 'Königsplatz 1'));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());

        $countryResult = $this->createMock(EntitySearchResult::class);
        $countryResult->method('first')->willReturn($country);
        $this->countryRepository->method('search')->willReturn($countryResult);

        $this->cartBuilder->method('buildCalculatedCart')->willReturn(new Cart('test'));

        $this->configurationService->method('getExcludedShippingMethods')
            ->willReturn($excludedMethods);
    }

    private function setUpCacheMiss(): void
    {
        $this->cacheService->method('get')->willReturn(null);
    }

    /** @param array<int, ShippingMethodEntity> $methods */
    private function setUpShippingMethods(array $methods): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getElements')->willReturn($methods);
        $this->shippingMethodRepository->method('search')->willReturn($result);
    }

    private function buildShippingMethod(
        string $name,
        ?string $availabilityRuleId,
        ?string $priceRuleId,
        float $grossPrice,
    ): ShippingMethodEntity {
        $method = new ShippingMethodEntity();
        $method->setId(Uuid::randomHex());
        $method->setName($name);
        $method->setTranslated(['name' => $name]);
        $method->setActive(true);
        $method->setAvailabilityRuleId($availabilityRuleId);

        $priceEntity = new ShippingMethodPriceEntity();
        $priceEntity->setId(Uuid::randomHex());
        $priceEntity->setRuleId($priceRuleId);
        $priceEntity->setCurrencyPrice(new PriceCollection([
            new Price(self::CURRENCY_ID, $grossPrice, $grossPrice, false),
        ]));

        $method->setPrices(new ShippingMethodPriceCollection([$priceEntity]));

        return $method;
    }
}
