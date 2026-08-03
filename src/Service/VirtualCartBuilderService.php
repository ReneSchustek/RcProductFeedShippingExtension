<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Erstellt einen temporären Warenkorb für die Versandkostenberechnung.
 *
 * **Er wird gespeichert, anders als hier lange behauptet.** `CartCalculator::calculate()` geht über
 * `CartRuleLoader::load()`, und das schreibt den Warenkorb bei geändertem Datenstempel weg
 * (Kern 6.7.12.1, Zeile 112-119). Weil alle Kombinationen eines Kanals dasselbe Token teilen,
 * bleibt es bei **einer** Zeile in `cart` — aber der Warmup löst dabei je Produkt und Land einen
 * Schreibvorgang aus, bei rund 8000 Kombinationen also 8000.
 *
 * Hier steht die Wahrheit statt der Zusicherung, weil die falsche Zusicherung teurer war: Wer sie
 * las, suchte die Last woanders.
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
