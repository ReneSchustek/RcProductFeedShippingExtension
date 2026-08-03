<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Integration\Storage;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Der Speicher der vorberechneten Versandkosten, gegen die echte Datenbank.
 *
 * Warum Integration und nicht Unit: Der ganze Zweck dieses Speichers ist, einen
 * `cache:clear` und einen Neustart zu überleben. Das lässt sich nur an einer echten
 * Tabelle zeigen -- ein Test gegen einen Doppelgänger würde genau die Eigenschaft
 * nicht prüfen, wegen der es ihn gibt.
 */
class ShippingPriceStoreTest extends TestCase
{
    use IntegrationTestBehaviour;

    private ShippingPriceStore $store;

    private Connection $connection;

    private string $salesChannelId;

    protected function setUp(): void
    {
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);

        $this->connection = $connection;
        $this->store = new ShippingPriceStore($connection, new NullLogger());
        $this->salesChannelId = Uuid::randomHex();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM rc_product_feed_shipping_price WHERE sales_channel_id = :salesChannelId',
            ['salesChannelId' => Uuid::fromHexToBytes($this->salesChannelId)]
        );
    }

    /**
     * Was: Ein geschriebener Preis kommt unverändert zurück.
     * Warum: Das ist der Grundvertrag. Stimmt der nicht, ist jede weitere Aussage wertlos.
     * Erwartet: Alle Felder des Ergebnisses überstehen den Umweg über die Datenbank.
     */
    public function testWrittenPriceIsReadBackUnchanged(): void
    {
        $productId = Uuid::randomHex();
        $this->store->set(
            $productId,
            'DE',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'DE', 17.26, 'EUR', false)
        );

        $result = $this->store->get($productId, 'DE', $this->salesChannelId);

        self::assertNotNull($result);
        self::assertSame($productId, $result->productId);
        self::assertSame('DE', $result->countryIso);
        self::assertSame(17.26, $result->shippingCost);
        self::assertSame('EUR', $result->currencyIso);
        self::assertFalse($result->isFallback);
    }

    /**
     * Was: Ein Ersatzwert samt Grund übersteht den Speicher.
     * Warum: Die Unterscheidung zwischen „keine Versandart" und „nicht vorberechnet" ist der
     *        Kern der Unterscheidung. Ginge der Grund beim Speichern verloren, fiele die
     *        Unterscheidung beim Lesen wieder in sich zusammen.
     * Erwartet: `fallbackReason` kommt als derselbe Fall zurück.
     */
    public function testFallbackReasonSurvivesTheRoundTrip(): void
    {
        $productId = Uuid::randomHex();
        $this->store->set(
            $productId,
            'CH',
            $this->salesChannelId,
            new ShippingCalculationResult(
                $productId,
                'CH',
                4.95,
                'EUR',
                true,
                FallbackReason::NoShippingMethod
            )
        );

        $result = $this->store->get($productId, 'CH', $this->salesChannelId);

        self::assertNotNull($result);
        self::assertTrue($result->isFallback);
        self::assertSame(FallbackReason::NoShippingMethod, $result->fallbackReason);
    }

    /**
     * Was: Ein zweiter Schreibvorgang auf dieselbe Kombination überschreibt statt zu doppeln.
     * Warum: Der Warmup läuft alle sechs Stunden über denselben Bestand. Ohne Überschreiben
     *        wüchse die Tabelle bei 8217 Kombinationen um denselben Betrag pro Lauf, und das
     *        Lesen bekäme irgendwann den ältesten statt den neuesten Wert.
     * Erwartet: Eine Zeile, der neue Wert.
     */
    public function testWritingTwiceOverwritesInsteadOfDuplicating(): void
    {
        $productId = Uuid::randomHex();
        $this->store->set(
            $productId,
            'AT',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'AT', 8.93, 'EUR', false)
        );
        $this->store->set(
            $productId,
            'AT',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'AT', 20.83, 'EUR', false)
        );

        $rows = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM rc_product_feed_shipping_price
             WHERE product_id = :productId AND country_iso = :countryIso
               AND sales_channel_id = :salesChannelId',
            [
                'productId' => Uuid::fromHexToBytes($productId),
                'countryIso' => 'AT',
                'salesChannelId' => Uuid::fromHexToBytes($this->salesChannelId),
            ]
        );

        $result = $this->store->get($productId, 'AT', $this->salesChannelId);

        self::assertSame(1, (int) $rows);
        self::assertNotNull($result);
        self::assertSame(20.83, $result->shippingCost);
    }

    /**
     * Was: Eine Kombination, die nie geschrieben wurde.
     * Warum: Der Aufrufer unterscheidet „nichts da" von „null gespeichert". Käme hier ein
     *        Ergebnis mit 0,00 € zurück, stünde im Feed ein kostenloser Versand.
     * Erwartet: null.
     */
    public function testUnknownCombinationReturnsNull(): void
    {
        self::assertNull($this->store->get(Uuid::randomHex(), 'DE', $this->salesChannelId));
    }

    /**
     * Was: Ein Eintrag, der älter ist als die zulässige Höchstdauer.
     * Warum: Versandkosten hängen auch an Gewicht und Maßen des Produkts. Ändert die jemand,
     *        merkt das kein Subscriber -- nur das Alter schützt davor, einen längst falschen
     *        Wert weiterzureichen.
     * Erwartet: Der Eintrag gilt als nicht vorhanden.
     */
    public function testEntryBeyondTheMaximumAgeCountsAsAbsent(): void
    {
        $productId = Uuid::randomHex();
        $this->store->set(
            $productId,
            'DE',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'DE', 17.26, 'EUR', false)
        );

        $this->connection->executeStatement(
            'UPDATE rc_product_feed_shipping_price
             SET calculated_at = DATE_SUB(NOW(), INTERVAL :hours HOUR)
             WHERE product_id = :productId',
            [
                'hours' => \intdiv(ShippingPriceStore::MAX_AGE_SECONDS, 3600) + 1,
                'productId' => Uuid::fromHexToBytes($productId),
            ]
        );

        self::assertNull($this->store->get($productId, 'DE', $this->salesChannelId));
    }

    /**
     * Was: `invalidateAll()` räumt den Bestand ab.
     * Warum: Ändert jemand eine Versandart, sind alle vorberechneten Werte hinfällig. Genau
     *        das ruft der vorhandene Subscriber auf.
     * Erwartet: Danach findet sich nichts mehr.
     */
    public function testInvalidateAllEmptiesTheStore(): void
    {
        $productId = Uuid::randomHex();
        $this->store->set(
            $productId,
            'DE',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'DE', 17.26, 'EUR', false)
        );

        $this->store->invalidateAll();

        self::assertNull($this->store->get($productId, 'DE', $this->salesChannelId));
    }

    /**
     * Was: Derselbe Artikel in zwei Verkaufskanälen.
     * Warum: Versandarten hängen am Kanal. Würde der Schlüssel den Kanal nicht führen, bekäme
     *        ein Kanal die Preise des anderen.
     * Erwartet: Jeder Kanal behält seinen eigenen Wert.
     */
    public function testSalesChannelsAreKeptApart(): void
    {
        $productId = Uuid::randomHex();
        $otherChannelId = Uuid::randomHex();

        $this->store->set(
            $productId,
            'DE',
            $this->salesChannelId,
            new ShippingCalculationResult($productId, 'DE', 17.26, 'EUR', false)
        );
        $this->store->set(
            $productId,
            'DE',
            $otherChannelId,
            new ShippingCalculationResult($productId, 'DE', 99.99, 'EUR', false)
        );

        $first = $this->store->get($productId, 'DE', $this->salesChannelId);
        $second = $this->store->get($productId, 'DE', $otherChannelId);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(17.26, $first->shippingCost);
        self::assertSame(99.99, $second->shippingCost);

        $this->connection->executeStatement(
            'DELETE FROM rc_product_feed_shipping_price WHERE sales_channel_id = :salesChannelId',
            ['salesChannelId' => Uuid::fromHexToBytes($otherChannelId)]
        );
    }
}
