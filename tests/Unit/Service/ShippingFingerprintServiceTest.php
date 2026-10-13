<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFingerprintService;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Der Prüfwert entscheidet, ob überhaupt gerechnet wird.
 *
 * Was hier schiefgehen kann, geht leise schief: Ein Prüfwert, der eine Änderung nicht erfasst, hält
 * ein geändertes Produkt für unverändert — und im Feed steht wochenlang ein falscher Betrag, ohne
 * dass irgendwo ein Fehler auftaucht. Deshalb prüfen diese Tests nicht, *dass* gehasht wird,
 * sondern *worüber*.
 */
class ShippingFingerprintServiceTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * Was: Zweimal derselbe Aufruf.
     * Warum: Der Stempel wird für jedes der mehreren tausend Produkte gebraucht. Fragte ihn der
     *        Dienst jedes Mal neu ab, ersetzte er tausende Berechnungen durch tausende Abfragen.
     * Erwartet: vier Abfragen für vier Quellen — beim zweiten Aufruf keine weitere.
     */
    public function testTheConfigurationStampIsQueriedOnlyOnce(): void
    {
        $this->connection->expects(self::exactly(4))
            ->method('fetchAssociative')
            ->willReturn(['count' => '4', 'latest' => '2026-09-09 10:00:00']);

        $service = new ShippingFingerprintService($this->connection);

        self::assertSame($service->configurationStamp(), $service->configurationStamp());
    }

    /**
     * Was: Zwei Läufe mit unterschiedlichem Stand der Versandkonfiguration.
     * Warum: Ändert sich eine Versandart oder ihre Preismatrix, sind die Werte **aller** Produkte
     *        hinfällig. Der Stempel steckt deshalb in jedem einzelnen Prüfwert — sonst bliebe der
     *        Feed nach einer Preisanpassung so lange falsch, bis jedes Produkt zufällig altert.
     * Erwartet: derselbe Artikel bekommt einen anderen Prüfwert.
     */
    public function testAChangedShippingConfigurationChangesEveryFingerprint(): void
    {
        $productId = Uuid::randomHex();
        $line = [['id' => $productId, 'source' => '5.4|100|200|300|{"net":10}|abc|0|1']];

        $before = $this->serviceWithStamp('2026-09-09 10:00:00', $line)->loadFingerprints([$productId]);
        $after = $this->serviceWithStamp('2026-09-09 11:30:00', $line)->loadFingerprints([$productId]);

        self::assertNotSame($before[$productId], $after[$productId]);
    }

    /**
     * Was: Eine gelöschte Versandart — gleicher Zeitstempel, eine Zeile weniger.
     * Warum: `MAX(updated_at)` merkt kein Löschen. Ohne die Anzahl im Stempel bliebe der Wegfall
     *        einer Versandart unsichtbar, und der Feed nennte weiter ihre Preise.
     * Erwartet: der Prüfwert ändert sich trotzdem.
     */
    public function testARemovedShippingMethodChangesTheStamp(): void
    {
        $productId = Uuid::randomHex();
        $line = [['id' => $productId, 'source' => '5.4|100|200|300|{"net":10}|abc|0|1']];

        $four = $this->serviceWithStamp('2026-09-09 10:00:00', $line, '4')->loadFingerprints([$productId]);
        $three = $this->serviceWithStamp('2026-09-09 10:00:00', $line, '3')->loadFingerprints([$productId]);

        self::assertNotSame($four[$productId], $three[$productId]);
    }

    /**
     * Was: Zwei Produkte, die sich nur im Preis unterscheiden.
     * Warum: Der Preis ist die übersehene Eingangsgröße. Wo es „versandkostenfrei ab X" gibt,
     *        ändert eine Preisänderung die Versandkosten, obwohl das Gewicht gleich blieb.
     * Erwartet: unterschiedliche Prüfwerte.
     */
    public function testProductsDifferingOnlyInPriceGetDifferentFingerprints(): void
    {
        $one = Uuid::randomHex();
        $two = Uuid::randomHex();

        $fingerprints = $this->serviceWithStamp('2026-09-09 10:00:00', [
            ['id' => $one, 'source' => '5.4|100|200|300|{"net":10}|abc|0|1'],
            ['id' => $two, 'source' => '5.4|100|200|300|{"net":99}|abc|0|1'],
        ])->loadFingerprints([$one, $two]);

        self::assertNotSame($fingerprints[$one], $fingerprints[$two]);
    }

    /**
     * Was: Ein Aufruf ohne Produkte.
     * Warum: Ein Kanal ohne aktive Produkte ist ein zulässiger Zustand, kein Fehler. Eine
     *        `IN ()`-Abfrage wäre an dieser Stelle ein Syntaxfehler in der Datenbank.
     * Erwartet: leeres Ergebnis, keine einzige Abfrage.
     */
    public function testAnEmptyProductListQueriesNothing(): void
    {
        $this->connection->expects(self::never())->method('fetchAllAssociative');
        $this->connection->expects(self::never())->method('fetchAssociative');

        self::assertSame([], (new ShippingFingerprintService($this->connection))->loadFingerprints([]));
    }

    /**
     * Was: Ein Produkt, das die Abfrage nicht zurückliefert.
     * Warum: Zwischen dem Einlesen der Produktliste und dem Prüfwert kann ein Produkt gelöscht
     *        worden sein. Ohne Prüfwert muss gerechnet werden — ein erfundener Wert hielte die
     *        Kombination fälschlich für unverändert.
     * Erwartet: es fehlt einfach, statt einen Wert zu erfinden.
     */
    public function testAProductWithoutARowHasNoFingerprint(): void
    {
        $present = Uuid::randomHex();
        $vanished = Uuid::randomHex();

        $fingerprints = $this->serviceWithStamp('2026-09-09 10:00:00', [
            ['id' => $present, 'source' => '5.4|100|200|300|{"net":10}|abc|0|1'],
        ])->loadFingerprints([$present, $vanished]);

        self::assertArrayHasKey($present, $fingerprints);
        self::assertArrayNotHasKey($vanished, $fingerprints);
    }

    /**
     * Was: Die Regeltabelle wird während des Laufs geschrieben.
     * Warum: **Am 09.09.2026 auf der Testkopie gemessen.** Shopware kompiliert beim Berechnen eines
     *        Warenkorbs die Regel-Nutzlast und schreibt dabei `rule.updated_at`. Zählte dieser
     *        Zeitstempel mit, verschöbe der Lauf seinen eigenen Prüfwert — kein Eintrag wäre je
     *        unverändert, und die ganze Ersparnis fiele lautlos aus.
     * Erwartet: ein neuer Zeitstempel in `rule` ändert den Stempel nicht.
     */
    public function testTheRuleTableIsCountedButNotDated(): void
    {
        $queries = [];

        $this->connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql) use (&$queries): array {
                $queries[] = $sql;

                return ['count' => '297', 'latest' => '2026-09-09 10:42:08'];
            }
        );

        (new ShippingFingerprintService($this->connection))->configurationStamp();

        $ruleQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'FROM `rule`')
        ));

        self::assertCount(1, $ruleQueries);
        self::assertStringNotContainsString('MAX(', $ruleQueries[0]);
        self::assertStringContainsString('COUNT(*)', $ruleQueries[0]);
    }

    /**
     * @param array<int, array<string, string>> $rows
     */
    private function serviceWithStamp(string $latest, array $rows, string $count = '4'): ShippingFingerprintService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['count' => $count, 'latest' => $latest]);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return new ShippingFingerprintService($connection);
    }
}
