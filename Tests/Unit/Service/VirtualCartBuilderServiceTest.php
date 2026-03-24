<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuhrCoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class VirtualCartBuilderServiceTest extends TestCase
{
    private CartCalculator&MockObject $calculator;
    private VirtualCartBuilderService $service;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(CartCalculator::class);
        $this->service = new VirtualCartBuilderService($this->calculator);
    }

    public function testBuildCalculatedCartReturnsCart(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $calculatedCart = new Cart('test-token');

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->willReturn($calculatedCart);

        $result = $this->service->buildCalculatedCart('product-id', $context);

        self::assertInstanceOf(Cart::class, $result);
    }

    public function testBuildCalculatedCartPassesCartWithLineItemToCalculator(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $calculatedCart = new Cart('test-token');
        $productId = 'abc123';

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->with(
                self::callback(function (Cart $cart) use ($productId) {
                    $lineItems = $cart->getLineItems();
                    if ($lineItems->count() !== 1) {
                        return false;
                    }
                    $lineItem = $lineItems->first();

                    return $lineItem instanceof LineItem
                        && $lineItem->getType() === LineItem::PRODUCT_LINE_ITEM_TYPE
                        && $lineItem->getReferencedId() === $productId
                        && $lineItem->getQuantity() === 1;
                }),
                $context,
            )
            ->willReturn($calculatedCart);

        $this->service->buildCalculatedCart($productId, $context);
    }

    public function testCalculatorRecalculateIsAlwaysCalled(): void
    {
        $context = $this->createMock(SalesChannelContext::class);

        $this->calculator
            ->expects($this->once())
            ->method('calculate')
            ->willReturn(new Cart('result'));

        $this->service->buildCalculatedCart('any-product', $context);
    }
}
