<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask\ShippingWarmupTask;
use Shopware\Core\Checkout\Shipping\ShippingMethodDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Fordert eine Neuberechnung an, sobald sich eine Versandart oder einer ihrer Preise ändert.
 *
 * Warum hier nichts mehr gelöscht wird
 * ------------------------------------
 * Bis zum 2026-08-03 warf dieser Abonnent den gesamten Bestand weg, begründet mit „der nächste
 * Feed-Request rechnet neu". Seit dem Umbau auf den Speicher stimmt das nicht mehr: **Der Feed
 * rechnet gar nicht mehr, er liest nur.** Ein Löschen hieß deshalb, dass der Feed bis zum nächsten
 * geplanten Lauf — bis zu sechs Stunden — für **jedes** Produkt den Ersatzwert von 4,95 Euro
 * nannte, auch für Ware, deren Versand über 200 Euro kostet.
 *
 * Damit stellte eine gewöhnliche Handlung im Verwaltungsbereich genau den Zustand her, den der
 * Speicher verhindern soll. Zu niedrig angegebene Versandkosten sind bei Google ein
 * Richtlinienverstoß.
 *
 * Die Abwägung: Ein veralteter, aber echter Preis liegt näher an der Wahrheit als ein Ersatzwert
 * für das gesamte Sortiment. Er hält ohnehin höchstens 24 Stunden, und der angeforderte Lauf
 * überschreibt ihn üblicherweise innerhalb einer Minute.
 *
 * Nicht erfasst: eine reine Umbenennung schreibt `shipping_method_translation`. Sie ändert keinen
 * Preis, verschiebt aber die Wirkung der Ausschlussliste, die auf dem übersetzten Namen arbeitet.
 * Wer eine Versandart umbenennt, muss `rc:shipping:warmup` von Hand anstoßen.
 */
final class ShippingMethodChangeSubscriber implements EventSubscriberInterface
{
    private const SHIPPING_METHOD_PRICE_ENTITY = 'shipping_method_price';

    /** @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository */
    public function __construct(
        private readonly EntityRepository $scheduledTaskRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntityWrittenContainerEvent::class => 'onEntityWritten',
        ];
    }

    public function onEntityWritten(EntityWrittenContainerEvent $event): void
    {
        $hasShippingMethodChange = $event->getEventByEntityName(ShippingMethodDefinition::ENTITY_NAME) !== null;
        $hasPriceChange = $event->getEventByEntityName(self::SHIPPING_METHOD_PRICE_ENTITY) !== null;

        if (!$hasShippingMethodChange && !$hasPriceChange) {
            return;
        }

        $this->requestWarmup();

        $this->logger->info('RcProductFeedShipping: Neuberechnung nach Methoden- oder Preisänderung angefordert.', [
            'context' => 'ruhrcoder_product_feed_shipping.warmup_requested',
            'shippingMethodChanged' => $hasShippingMethodChange,
            'priceChanged' => $hasPriceChange,
            'metric' => 'warmup_requested',
        ]);
    }

    /**
     * Zieht die geplante Aufgabe auf „jetzt" vor.
     *
     * Nur aus dem Zustand `scheduled` heraus: Läuft sie gerade oder wartet sie in der Warteschlange,
     * würde ein Eingriff sie doppelt starten — und ein Warmup-Lauf ist teuer.
     */
    private function requestWarmup(): void
    {
        try {
            $context = Context::createDefaultContext();

            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', ShippingWarmupTask::getTaskName()));
            $criteria->addFilter(new EqualsFilter('status', ScheduledTaskDefinition::STATUS_SCHEDULED));
            $criteria->setLimit(1);

            $taskId = $this->scheduledTaskRepository->searchIds($criteria, $context)->firstId();
            if ($taskId === null) {
                return;
            }

            // Ausdrücklich UTC: Shopware legt Zeitstempel in UTC ab. Ohne Zeitzone käme die des
            // Servers zum Zug, und auf einem Server in Berlin läge die Planzeit im Sommer zwei
            // Stunden in der Zukunft — der vorgezogene Lauf käme später als der reguläre.
            $this->scheduledTaskRepository->update([[
                'id' => $taskId,
                'nextExecutionTime' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            ]], $context);
        } catch (\Throwable $error) {
            // Das Speichern der Versandart darf daran nicht scheitern. Ohne die Anforderung greift
            // der reguläre Takt — später, aber nicht falsch.
            $this->logger->warning('RcProductFeedShipping: Neuberechnung konnte nicht angefordert werden.', [
                'context' => 'ruhrcoder_product_feed_shipping.warmup_requested',
                'error' => $error->getMessage(),
            ]);
        }
    }
}
