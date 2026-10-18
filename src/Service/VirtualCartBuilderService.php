<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Erstellt einen temporären Warenkorb für die Versandkostenberechnung.
 *
 * Er wird gespeichert. `CartCalculator::calculate()` geht über `CartRuleLoader::load()`, und das
 * schreibt den Warenkorb bei geändertem Datenstempel weg (Kern 6.7.12.1, Zeile 112-119).
 *
 * Das Token entsteht deshalb einmal je Prozess und nicht je Berechnung. Trüge jeder Warenkorb
 * ein eigenes `Uuid::randomHex()`, hinterließe jede Kombination aus Produkt und Land eine eigene
 * Zeile: bei über 21 000 Berechnungen am Tag ebenso viele Zeilen, rund ein Gigabyte Rohdaten, die
 * dreißig Tage liegen blieben und die Warenkorb-Kennzahlen des Shops unbrauchbar machten. Ein Token
 * je Prozess macht daraus eine Zeile je Lauf; ein zufälliges Token je Instanz statt eines festen
 * Wertes hält auch parallele Arbeiter auseinander.
 *
 * Diese eine Zeile räumt {@see self::removeTrace()} am Ende des Laufs weg. Der Dienst kennt sein
 * Token. Ein Aufräumen von außen hätte diese Sicherheit nicht und träfe irgendwann einen echten
 * Kundenwarenkorb.
 */
class VirtualCartBuilderService
{
    /**
     * Das Token aller Warenkörbe dieses Prozesses.
     *
     * Zufällig, damit zwei gleichzeitig laufende Arbeiter sich nicht dieselbe Zeile überschreiben,
     * und für die Lebensdauer des Dienstes stabil, damit nur eine Zeile entsteht.
     */
    private readonly string $token;

    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        $this->token = Uuid::randomHex();
    }

    /**
     * Löscht die Warenkorbzeile, die dieser Prozess hinterlassen hat.
     *
     * Wird am Ende eines Warmup-Laufs gerufen. Schlägt es fehl, ist das kein Grund, den Lauf als
     * gescheitert zu melden; die Zeile läuft dann über Shopwares eigene Aufräumaufgabe aus.
     */
    public function removeTrace(): void
    {
        try {
            $this->connection->executeStatement(
                'DELETE FROM `cart` WHERE `token` = :token',
                ['token' => $this->token],
            );
        } catch (\Throwable $error) {
            $this->logger->warning('RcProductFeedShipping: Warenkorb-Spur konnte nicht entfernt werden.', [
                'context' => 'ruhrcoder_product_feed_shipping.warmup',
                'error' => $error->getMessage(),
            ]);
        }
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
        $cart = new Cart($this->token);

        $cart->add(new LineItem(
            id: Uuid::randomHex(),
            type: LineItem::PRODUCT_LINE_ITEM_TYPE,
            referencedId: $productId,
            quantity: 1,
        ));

        return $this->calculator->calculate($cart, $context);
    }
}
