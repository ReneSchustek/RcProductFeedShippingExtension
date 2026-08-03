<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Führt den Warmup aus, damit der Feed vorberechnete Werte vorfindet.
 *
 * Läuft im Worker und damit außerhalb von Twig — nur dort ist die Berechnung überhaupt möglich.
 *
 * **Voraussetzung im Betrieb:** ein laufender `messenger:consume`-Worker und der
 * `scheduled-task:run`-Dienst. Ohne beides bleibt die Aufgabe stehen, der Speicher läuft nach 24
 * Stunden ab, und der Feed fällt auf den Ersatzwert zurück — sichtbar an der Warnung
 * `feed_fallback_summary` im Protokoll.
 */
#[AsMessageHandler(handles: ShippingWarmupTask::class)]
final class ShippingWarmupTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ShippingWarmupService $warmupService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $result = $this->warmupService->warmup();

        // Ein Lauf, der durchläuft und dabei ausschließlich Ersatzwerte einträgt, ist kein
        // erfolgreicher Lauf. Ohne diese Meldung sähe im Protokoll alles gut aus, während der Feed
        // für jedes Produkt denselben Pauschalbetrag ausweist -- und niemandem fällt es auf.
        if ($result->fallbacksOnly()) {
            $this->logger->error('RcProductFeedShipping: Warmup hat nichts berechnet, alle Werte sind Ersatzwerte.', [
                'context' => 'ruhrcoder_product_feed_shipping.warmup',
                'combinations' => $result->total(),
                'hint' => 'Berechnungs-Verkaufskanal, Versandmethoden und Verfügbarkeitsregeln prüfen. '
                    . 'Details liefert rc:shipping:check.',
                'metric' => 'warmup_only_fallback',
            ]);
        }
    }
}
