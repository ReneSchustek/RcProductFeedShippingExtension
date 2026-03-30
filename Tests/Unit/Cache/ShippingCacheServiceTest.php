<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcProductFeedShippingExtension\Cache\ShippingCacheService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

class ShippingCacheServiceTest extends TestCase
{
    private ShippingCacheService $service;

    protected function setUp(): void
    {
        $adapter = new TagAwareAdapter(new ArrayAdapter());
        $this->service = new ShippingCacheService($adapter, new NullLogger());
    }

    public function testGetReturnsNullWhenNoEntryExists(): void
    {
        $result = $this->service->get('product-id', 'DE', 'sales-channel-id');

        self::assertNull($result);
    }

    public function testSetAndGetReturnStoredResult(): void
    {
        $result = new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false);

        $this->service->set('product-id', 'DE', 'sales-channel-id', $result);
        $cached = $this->service->get('product-id', 'DE', 'sales-channel-id');

        self::assertNotNull($cached);
        self::assertSame(4.95, $cached->shippingCost);
        self::assertSame('DE', $cached->countryIso);
    }

    public function testInvalidateAllClearsAllEntries(): void
    {
        $result = new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false);
        $this->service->set('product-id', 'DE', 'sales-channel-id', $result);

        $this->service->invalidateAll();

        self::assertNull($this->service->get('product-id', 'DE', 'sales-channel-id'));
    }

    public function testDifferentSalesChannelsHaveSeparateCacheEntries(): void
    {
        $resultA = new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false);
        $resultB = new ShippingCalculationResult('product-id', 'DE', 9.90, 'EUR', false);

        $this->service->set('product-id', 'DE', 'channel-a', $resultA);
        $this->service->set('product-id', 'DE', 'channel-b', $resultB);

        $cachedA = $this->service->get('product-id', 'DE', 'channel-a');
        $cachedB = $this->service->get('product-id', 'DE', 'channel-b');

        self::assertNotNull($cachedA);
        self::assertNotNull($cachedB);
        self::assertSame(4.95, $cachedA->shippingCost);
        self::assertSame(9.90, $cachedB->shippingCost);
    }

    public function testCacheKeyIncludesAllThreeParameters(): void
    {
        $result = new ShippingCalculationResult('product-a', 'DE', 4.95, 'EUR', false);
        $this->service->set('product-a', 'DE', 'channel-id', $result);

        self::assertNull($this->service->get('product-b', 'DE', 'channel-id'));
        self::assertNull($this->service->get('product-a', 'AT', 'channel-id'));
        self::assertNull($this->service->get('product-a', 'DE', 'other-channel'));
    }
}
