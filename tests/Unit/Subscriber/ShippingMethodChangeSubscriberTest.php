<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Subscriber\ShippingMethodChangeSubscriber;
use Shopware\Core\Checkout\Shipping\ShippingMethodDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Was beim Ändern einer Versandart passiert — und was ausdrücklich nicht mehr.
 *
 * Bis zum 2026-08-03 warf dieser Abonnent den gesamten Bestand weg. Seit dem Umbau auf den
 * Speicher rechnet der Feed nicht mehr selbst; ein leerer Bestand hieß deshalb, dass jedes
 * Produkt bis zum nächsten geplanten Lauf mit 4,95 Euro im Feed stand. Eine gewöhnliche
 * Handlung im Verwaltungsbereich stellte damit genau den Zustand her, den der Speicher
 * verhindern soll.
 */
#[CoversClass(ShippingMethodChangeSubscriber::class)]
final class ShippingMethodChangeSubscriberTest extends TestCase
{
    private EntityRepository&MockObject $scheduledTaskRepository;
    private LoggerInterface&MockObject $logger;
    private ShippingMethodChangeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->scheduledTaskRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subscriber = new ShippingMethodChangeSubscriber($this->scheduledTaskRepository, $this->logger);
    }

    public function testSubscribesToEntityWrittenContainerEvent(): void
    {
        $events = ShippingMethodChangeSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(EntityWrittenContainerEvent::class, $events);
    }

    /**
     * Was: Eine Versandart wird gespeichert.
     * Warum: Die vorberechneten Preise können jetzt falsch sein. Sie deshalb wegzuwerfen wäre
     *        aber der schlechtere Tausch — ein veralteter echter Preis liegt näher an der
     *        Wahrheit als ein Ersatzwert für das gesamte Sortiment.
     * Erwartet: Der Warmup wird vorgezogen, nichts wird gelöscht.
     */
    public function testShippingMethodChangeMovesTheWarmupForward(): void
    {
        $taskId = Uuid::randomHex();
        $this->scheduledTaskRepository->method('searchIds')
            ->willReturn(new IdSearchResult(1, [['primaryKey' => $taskId, 'data' => []]], new Criteria(), Context::createDefaultContext()));

        $this->scheduledTaskRepository->expects(self::once())
            ->method('update')
            ->with(self::callback(function (array $payload) use ($taskId): bool {
                self::assertSame($taskId, $payload[0]['id']);
                self::assertInstanceOf(\DateTimeInterface::class, $payload[0]['nextExecutionTime']);

                return true;
            }));

        $this->subscriber->onEntityWritten($this->buildEventFor(ShippingMethodDefinition::ENTITY_NAME));
    }

    /**
     * Was: Ein Versandpreis wird gespeichert.
     * Warum: derselbe Anlass, anderer Auslöser — der Preis ist die häufigere Änderung.
     * Erwartet: ebenfalls ein vorgezogener Warmup.
     */
    public function testPriceChangeMovesTheWarmupForward(): void
    {
        $this->scheduledTaskRepository->method('searchIds')
            ->willReturn(new IdSearchResult(1, [['primaryKey' => Uuid::randomHex(), 'data' => []]], new Criteria(), Context::createDefaultContext()));

        $this->scheduledTaskRepository->expects(self::once())->method('update');
        $this->logger->expects(self::once())->method('info');

        $this->subscriber->onEntityWritten($this->buildEventFor('shipping_method_price'));
    }

    /**
     * Was: Irgendeine andere Entität wird geschrieben.
     * Warum: Der Abonnent hört auf **jedes** Schreib-Ereignis im Shop. Griffe er zu weit, zöge
     *        jeder Produktimport einen Warmup nach sich.
     * Erwartet: nichts.
     */
    public function testUnrelatedChangesDoNothing(): void
    {
        $containerEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $containerEvent->method('getEventByEntityName')->willReturn(null);

        $this->scheduledTaskRepository->expects(self::never())->method('update');
        $this->logger->expects(self::never())->method('info');

        $this->subscriber->onEntityWritten($containerEvent);
    }

    /**
     * Was: Die geplante Aufgabe läuft gerade oder ist nicht eingerichtet.
     * Warum: Ein Warmup-Lauf ist teuer; ihn doppelt zu starten wäre schlimmer als ihn später zu
     *        starten. Und das Speichern der Versandart darf an dieser Nebensache nie scheitern.
     * Erwartet: kein Eingriff, keine Ausnahme.
     */
    public function testNothingHappensWhenTheTaskIsNotWaiting(): void
    {
        $this->scheduledTaskRepository->method('searchIds')
            ->willReturn(new IdSearchResult(0, [], new Criteria(), Context::createDefaultContext()));

        $this->scheduledTaskRepository->expects(self::never())->method('update');

        $this->subscriber->onEntityWritten($this->buildEventFor(ShippingMethodDefinition::ENTITY_NAME));
    }

    /**
     * Was: Die Aufgaben-Verwaltung ist nicht erreichbar.
     * Warum: Der Abonnent hängt am Speichern der Versandart. Reichte er die Ausnahme durch,
     *        ließe sich im Verwaltungsbereich keine Versandart mehr speichern — wegen einer
     *        Nebenwirkung eines Feed-Plugins.
     * Erwartet: eine Warnung, keine Ausnahme.
     */
    public function testAnUnreachableTaskRepositoryDoesNotBreakTheSave(): void
    {
        $this->scheduledTaskRepository->method('searchIds')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->logger->expects(self::once())->method('warning');

        $this->subscriber->onEntityWritten($this->buildEventFor(ShippingMethodDefinition::ENTITY_NAME));
    }

    private function buildEventFor(string $entityName): EntityWrittenContainerEvent&MockObject
    {
        $written = $this->createMock(EntityWrittenEvent::class);

        $containerEvent = $this->createMock(EntityWrittenContainerEvent::class);
        $containerEvent->method('getEventByEntityName')
            ->willReturnCallback(
                static fn (string $name): ?EntityWrittenEvent => $name === $entityName ? $written : null,
            );

        return $containerEvent;
    }
}
