<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class VirtualCartBuilderServiceTest extends TestCase
{
    private CartCalculator&MockObject $calculator;
    private Connection&MockObject $connection;
    private VirtualCartBuilderService $service;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(CartCalculator::class);
        $this->connection = $this->createMock(Connection::class);
        $this->service = new VirtualCartBuilderService(
            $this->calculator,
            $this->connection,
            $this->createMock(LoggerInterface::class),
        );
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

    /**
     * Was: Zwei Warenkörbe nacheinander aus demselben Dienst.
     * Warum: Shopware schreibt jeden berechneten Warenkorb weg. Trüge jeder ein eigenes Token,
     *        ließe der Warmup je Produkt und Land eine Zeile zurück, bei über 21 000 Berechnungen
     *        am Tag rund ein Gigabyte bei dreißig Tagen Aufbewahrung.
     * Erwartet: dasselbe Token, also eine einzige Zeile für den ganzen Lauf.
     */
    public function testAllCartsOfOneProcessShareOneToken(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $this->calculator->method('calculate')->willReturnArgument(0);

        $erster = $this->service->buildCalculatedCart('produkt-1', $context);
        $zweiter = $this->service->buildCalculatedCart('produkt-2', $context);

        self::assertSame($erster->getToken(), $zweiter->getToken());
    }

    /**
     * Was: Das Aufräumen am Ende eines Laufs.
     * Warum: Der Dienst kennt sein Token. Ein Aufräumen von außen hätte diese Sicherheit nicht.
     *        In der Tabelle `cart` sind alle Merkmalsspalten leer, es bliebe die Uhrzeit, und wer
     *        sich in der Minute irrt, löscht einen echten Kundenwarenkorb.
     * Erwartet: gelöscht wird genau nach dem eigenen Token.
     */
    public function testRemoveTraceDeletesExactlyItsOwnToken(): void
    {
        $context = $this->createMock(SalesChannelContext::class);
        $this->calculator->method('calculate')->willReturnArgument(0);
        $token = $this->service->buildCalculatedCart('produkt-1', $context)->getToken();

        $this->connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringContains('DELETE FROM `cart`'), ['token' => $token]);

        $this->service->removeTrace();
    }

    /**
     * Was: Die Datenbank verweigert das Aufräumen.
     * Warum: Eine liegengebliebene Zeile ist ein Schönheitsfehler, ein abgebrochener Warmup-Lauf
     *        nicht; dann stünden im Feed Ersatzwerte.
     * Erwartet: keine Ausnahme nach außen.
     */
    public function testAFailingCleanupDoesNotBreakTheRun(): void
    {
        $this->connection->method('executeStatement')->willThrowException(new \RuntimeException('weg'));

        $this->service->removeTrace();

        self::assertTrue(true);
    }
}
