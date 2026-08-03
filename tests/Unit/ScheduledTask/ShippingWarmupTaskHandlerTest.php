<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\ScheduledTask;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask\ShippingWarmupTask;
use Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask\ShippingWarmupTaskHandler;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\WarmupResult;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Die geplante Aufgabe, die den Speicher gefüllt hält.
 *
 * Sie läuft unbeobachtet im Worker. Was sie nicht ins Protokoll schreibt, sieht niemand — und
 * genau darum geht es hier: Ein Lauf, der durchläuft und dabei ausschließlich Ersatzwerte
 * einträgt, ist kein erfolgreicher Lauf, sieht aber wie einer aus.
 */
class ShippingWarmupTaskHandlerTest extends TestCase
{
    private ShippingWarmupService&MockObject $warmupService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->warmupService = $this->createMock(ShippingWarmupService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Was: Ein Lauf, der gerechnet hat.
     * Warum: Der Normalfall darf das Fehlerprotokoll nicht anfassen. Täte er es, wäre die Meldung
     *        wertlos — vier Fehlermeldungen am Tag liest niemand mehr.
     * Erwartet: kein error().
     */
    public function testASuccessfulRunLogsNoError(): void
    {
        $result = new WarmupResult();
        $result->record(false);
        $result->record(true);
        $this->warmupService->method('warmup')->willReturn($result);

        $this->logger->expects(self::never())->method('error');

        $this->createHandler()->run();
    }

    /**
     * Was: Ein Lauf, der nur Ersatzwerte eingetragen hat.
     * Warum: Das ist der Zustand, in dem der Feed für jeden Artikel denselben Pauschalbetrag
     *        ausweist. Ohne diese Meldung sieht im Protokoll alles gut aus.
     * Erwartet: eine Fehlermeldung mit der Zahl der Kombinationen und einem Hinweis, wo zu suchen
     *           ist.
     */
    public function testARunWithOnlyFallbacksIsLoggedAsAnError(): void
    {
        $result = new WarmupResult();
        $result->record(true);
        $result->record(true);
        $result->record(true);
        $this->warmupService->method('warmup')->willReturn($result);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('nichts berechnet'),
                self::callback(static function (array $context): bool {
                    return $context['combinations'] === 3
                        && $context['metric'] === 'warmup_only_fallback'
                        && \is_string($context['hint']) && $context['hint'] !== '';
                })
            );

        $this->createHandler()->run();
    }

    /**
     * Was: Ein Lauf, bei dem es nichts zu rechnen gab.
     * Warum: Kein Verkaufskanal mit aktivem Plugin ist kein Fehler. Eine Fehlermeldung hier wäre
     *        ein Fehlalarm, der bei jedem Lauf wiederkäme.
     * Erwartet: kein error().
     */
    public function testAnEmptyRunLogsNoError(): void
    {
        $this->warmupService->method('warmup')->willReturn(new WarmupResult());

        $this->logger->expects(self::never())->method('error');

        $this->createHandler()->run();
    }

    /**
     * Was: Der Takt der geplanten Aufgabe.
     * Warum: Er hängt am Höchstalter der gespeicherten Werte. Läuft die Aufgabe seltener als
     *        diese Frist, verfallen Einträge zwischen zwei Läufen und der Feed fällt auf den
     *        Ersatzwert zurück — genau der Zustand, den Fassung 1.3.0 beseitigt hat.
     * Erwartet: der Takt teilt das Höchstalter, mit Reserve.
     */
    public function testTheScheduleRunsOftenEnoughToBeatTheMaximumAge(): void
    {
        $takt = ShippingWarmupTask::getDefaultInterval();

        self::assertGreaterThan(0, $takt);
        self::assertLessThanOrEqual(
            ShippingPriceStore::MAX_AGE_SECONDS / 2,
            $takt,
            'Der Warmup muss mindestens zweimal laufen, bevor ein Eintrag verfallen kann.'
        );
    }

    private function createHandler(): ShippingWarmupTaskHandler
    {
        return new ShippingWarmupTaskHandler(
            $this->createMock(EntityRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->warmupService,
            $this->logger,
        );
    }
}
