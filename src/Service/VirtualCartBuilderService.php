<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Erstellt einen temporären Warenkorb für die Versandkostenberechnung.
 *
 * Der Warenkorb wird ausschließlich im Arbeitsspeicher berechnet und nie persistiert.
 */
class VirtualCartBuilderService
{
    public function __construct(private readonly CartCalculator $calculator)
    {
    }

    /**
     * Erstellt einen berechneten Warenkorb mit einem Stück des angegebenen Produkts.
     *
     * Shopware berechnet dabei Versandkosten anhand des SalesChannelContext, der bereits
     * auf das Zielland ausgerichtet ist. Der zurückgegebene Warenkorb enthält die
     * fertigen Deliveries mit Versandkosten.
     */
    public function buildCalculatedCart(string $productId, SalesChannelContext $context): Cart
    {
        $cart = new Cart(Uuid::randomHex());

        $cart->add(new LineItem(
            id: Uuid::randomHex(),
            type: LineItem::PRODUCT_LINE_ITEM_TYPE,
            referencedId: $productId,
            quantity: 1,
        ));

        return $this->calculator->calculate($cart, $context);
    }
}
