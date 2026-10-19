<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Subscriber;

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FeedImageContextProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Subscriber\FeedImageSubscriber;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Der Bild-Dienst im Kontext des Produktexports.
 */
#[CoversClass(FeedImageSubscriber::class)]
final class FeedImageSubscriberTest extends TestCase
{
    /**
     * Was: Export mit Kanal, Bildwahl eingeschaltet.
     * Warum: Die Vorlage ruft `rcFeedImage.url(product)`; ohne den Dienst im Kontext bricht der Export ab.
     * Erwartet: `rcFeedImage` steht im Kontext, die Einstellung wird für den Kanal des Exports gelesen.
     */
    public function testTheProviderIsAddedForTheChannelOfTheExport(): void
    {
        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->expects(self::once())->method('isFeedImageReplacementEnabled')->with('kanal-1')->willReturn(true);

        $event = new ProductExportRenderBodyContextEvent(['context' => $this->salesChannelContext('kanal-1')]);
        $this->subscriber($configuration)->onProductExportRender($event);

        self::assertInstanceOf(FeedImageContextProvider::class, $event->getContext()['rcFeedImage']);
        self::assertArrayHasKey('context', $event->getContext());
    }

    /**
     * Was: Export ohne Kanal-Kontext und mit ausgeschalteter Bildwahl.
     * Warum: Der Export läuft mit `strict_variables`. Fehlte der Dienst in diesen Fällen, bräche eine Vorlage ab, die
     *        ihn ruft, sobald jemand die Bildwahl abschaltet.
     * Erwartet: Der Dienst steht trotzdem im Kontext; gelesen wird die globale Einstellung.
     */
    public function testTheProviderIsAlwaysAdded(): void
    {
        $configuration = $this->createMock(ConfigurationService::class);
        $configuration->expects(self::once())->method('isFeedImageReplacementEnabled')->with(null)->willReturn(false);

        $event = new ProductExportRenderBodyContextEvent([]);
        $this->subscriber($configuration)->onProductExportRender($event);

        self::assertInstanceOf(FeedImageContextProvider::class, $event->getContext()['rcFeedImage']);
    }

    /**
     * Was: Die Anmeldung am Export-Ereignis.
     * Warum: Ein falscher Name meldet keinen Fehler; das Plugin wäre aktiv und wirkungslos.
     * Erwartet: das richtige Ereignis mit der richtigen Methode.
     */
    public function testTheExportEventIsSubscribed(): void
    {
        self::assertSame(
            [ProductExportRenderBodyContextEvent::class => 'onProductExportRender'],
            FeedImageSubscriber::getSubscribedEvents(),
        );
    }

    private function subscriber(ConfigurationService $configuration): FeedImageSubscriber
    {
        return new FeedImageSubscriber(
            $this->createStub(FeedImageService::class),
            new Filesystem(new InMemoryFilesystemAdapter()),
            $configuration,
        );
    }

    private function salesChannelContext(string $salesChannelId): SalesChannelContext
    {
        $salesChannel = new SalesChannelEntity();
        $salesChannel->setId($salesChannelId);

        $context = $this->createStub(SalesChannelContext::class);
        $context->method('getSalesChannel')->willReturn($salesChannel);

        return $context;
    }
}
