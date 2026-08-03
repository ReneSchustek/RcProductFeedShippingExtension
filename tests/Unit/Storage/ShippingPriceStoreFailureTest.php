<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Storage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\InvalidArgumentException as DbalException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Was der Speicher tut, wenn die Datenbank nicht antwortet.
 *
 * Der Speicher gibt ein Versprechen, das in seinem eigenen Kommentar steht: Ein Lesefehler
 * darf den Feed nicht reißen. Das ist keine Kleinigkeit — gäbe `get()` eine Ausnahme weiter,
 * bräche der **gesamte** Export ab statt eines einzelnen Preises. Und zwar erst im Betrieb,
 * denn im Testlauf fällt keine Datenbank aus.
 *
 * Deshalb hier ein Doppelgänger für die Verbindung: Er ist der einzige Weg, den Ausfall
 * herbeizuführen. Der Gutfall bleibt beim Integrationstest gegen die echte Tabelle — dort
 * gehört er hin.
 */
class ShippingPriceStoreFailureTest extends TestCase
{
    private Connection&MockObject $connection;

    private LoggerInterface&MockObject $logger;

    private ShippingPriceStore $store;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->store = new ShippingPriceStore($this->connection, $this->logger);
    }

    /**
     * Was: Lesen bei ausgefallener Datenbank.
     * Warum: Der Aufrufer behandelt `null` als „nicht vorberechnet" und trägt den Ersatzwert
     *        mit eigener Warnung ein. Eine durchgereichte Ausnahme würde stattdessen den
     *        ganzen Feed abbrechen.
     * Erwartet: `null` und eine Warnung.
     */
    public function testReadFailureReturnsNullInsteadOfThrowing(): void
    {
        $this->connection->method('fetchAssociative')
            ->willThrowException(new DbalException('connection refused'));

        $this->logger->expects(self::once())->method('warning');

        self::assertNull($this->store->get(Uuid::randomHex(), 'DE', Uuid::randomHex()));
    }

    /**
     * Was: Schreiben bei ausgefallener Datenbank.
     * Warum: Ein Schreibfehler kostet Geschwindigkeit, nicht Richtigkeit — der nächste Warmup
     *        versucht es erneut. Er darf den laufenden Warmup nicht abbrechen, sonst bleiben
     *        auch alle **folgenden** Kombinationen ungeschrieben.
     * Erwartet: kehrt zurück, mit Warnung.
     */
    public function testWriteFailureIsSwallowedWithAWarning(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new DbalException('connection refused'));

        $this->logger->expects(self::once())->method('warning');

        $this->store->set(
            Uuid::randomHex(),
            'DE',
            Uuid::randomHex(),
            new ShippingCalculationResult('product-id', 'DE', 12.5, 'EUR', false, null),
        );
    }

    /**
     * Was: Abräumen bei ausgefallener Datenbank.
     * Warum: Der Aufruf hängt an einer Änderung der Versandart, also an einer Admin-Aktion.
     *        Eine Ausnahme dort würde das Speichern der Versandart selbst scheitern lassen.
     * Erwartet: kehrt zurück, mit Warnung.
     */
    public function testInvalidateFailureDoesNotBreakTheCaller(): void
    {
        $this->connection->method('executeStatement')
            ->willThrowException(new DbalException('connection refused'));

        $this->logger->expects(self::once())->method('warning');

        $this->store->invalidateAll();
    }
}
