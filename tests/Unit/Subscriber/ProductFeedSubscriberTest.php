<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Subscriber;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Subscriber\ProductFeedSubscriber;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderFooterContextEvent;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

class ProductFeedSubscriberTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ShippingFallbackService&MockObject $fallbackService;
    private ConfigurationService&MockObject $configurationService;
    private LoggerInterface&MockObject $logger;
    private ProductFeedSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subscriber = new ProductFeedSubscriber(
            $this->calculator,
            $this->fallbackService,
            $this->configurationService,
            $this->logger,
        );
    }

    public function testDisabledPluginSetsNoProvider(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(false);

        $event = $this->buildEvent('channel-id', ['DE']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    public function testEmptyCountriesSetsNoProvider(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn([]);

        $event = $this->buildEvent('channel-id', []);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    public function testProviderIsInjectedIntoContext(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE', 'AT']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);

        $event = $this->buildEvent('channel-id', ['DE', 'AT']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayHasKey('rcShipping', $event->getContext());
        self::assertInstanceOf(ShippingContextProvider::class, $event->getContext()['rcShipping']);
    }

    public function testCalculationSalesChannelIdIsUsedWhenConfigured(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn('calc-channel-id');

        $event = $this->buildEvent('feed-channel-id', ['DE']);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayHasKey('rcShipping', $event->getContext());
    }

    public function testMissingSalesChannelContextSkipsInjection(): void
    {
        $event = new ProductExportRenderBodyContextEvent([]);
        $this->subscriber->onProductExportRender($event);

        self::assertArrayNotHasKey('rcShipping', $event->getContext());
    }

    private function buildEvent(string $salesChannelId, array $countries): ProductExportRenderBodyContextEvent
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);

        $currency = new CurrencyEntity();
        $currency->setIsoCode('EUR');

        $salesChannelContext = $this->createMock(SalesChannelContext::class);
        $salesChannelContext->method('getSalesChannel')->willReturn($salesChannel);
        $salesChannelContext->method('getCurrency')->willReturn($currency);

        return new ProductExportRenderBodyContextEvent([
            'context' => $salesChannelContext,
        ]);
    }

    /**
     * Was: Die Anmeldung an den beiden Export-Ereignissen.
     * Warum: Steht dort ein falscher Name, meldet nichts einen Fehler. Das Plugin wäre
     *        installiert, aktiv — und wirkungslos: Der Feed liefe weiter, nur ohne
     *        Versandblöcke. Ein Tippfehler hätte genau diese Wirkung.
     * Erwartet: beide Ereignisse mit ihren Methoden.
     */
    public function testBothExportEventsAreSubscribed(): void
    {
        $events = ProductFeedSubscriber::getSubscribedEvents();

        self::assertSame('onProductExportRender', $events[ProductExportRenderBodyContextEvent::class]);
        self::assertSame('onProductExportFooter', $events[ProductExportRenderFooterContextEvent::class]);
    }

    /**
     * Was: Die Fußzeile am Ende des Feeds.
     * Warum: Dort wird zusammengefasst, wie viele Produkte den Ersatzwert bekommen haben —
     *        die Stelle, an der ein stiller Ausfall überhaupt auffällt. Danach muss der
     *        Anbieter freigegeben werden, sonst trüge der nächste Export die Zählung des
     *        vorigen weiter.
     * Erwartet: Zusammenfassung genau einmal, danach kein zweites Mal.
     */
    public function testFooterLogsTheSummaryOnceAndReleasesTheProvider(): void
    {
        $this->configurationService->method('isEnabled')->willReturn(true);
        $this->configurationService->method('getCountries')->willReturn(['DE']);
        $this->configurationService->method('getCalculationSalesChannelId')->willReturn(null);

        // Kein vorberechneter Wert: Das Produkt bekommt den Ersatzwert, und genau darüber
        // berichtet die Zusammenfassung. Ohne diesen Fall schwiege sie, und der Test wüsste
        // nicht, ob sie überhaupt aufgerufen wurde.
        $this->calculator->method('lookupCached')->willReturn(null);
        $this->fallbackService->method('getFallbackResult')->willReturn(
            new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', true, FallbackReason::NotPrecomputed),
        );

        $meldungen = [];
        $this->logger->method('warning')->willReturnCallback(
            static function (string $meldung) use (&$meldungen): void {
                $meldungen[] = $meldung;
            },
        );

        $event = $this->buildEvent('channel-id', ['DE']);
        $this->subscriber->onProductExportRender($event);

        $provider = $event->getContext()['rcShipping'];
        self::assertInstanceOf(ShippingContextProvider::class, $provider);
        $provider->get('product-id', 'DE');

        $footerEvent = new ProductExportRenderFooterContextEvent([]);
        $this->subscriber->onProductExportFooter($footerEvent);

        // Der zweite Aufruf findet keinen Anbieter mehr vor. Bliebe er stehen, trüge der
        // nächste Export die Zählung des vorigen weiter.
        $this->subscriber->onProductExportFooter($footerEvent);

        $zusammenfassungen = array_filter(
            $meldungen,
            static fn (string $meldung): bool => str_contains($meldung, 'Ersatzwerten ausgeliefert'),
        );

        self::assertCount(1, $zusammenfassungen);
    }
}
