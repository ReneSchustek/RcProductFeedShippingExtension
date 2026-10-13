<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Subscriber;

use League\Flysystem\Filesystem;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FeedImageContextProvider;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Legt `rcFeedImage` in den Kontext jedes Produktexports.
 *
 * Immer, unabhängig von den Versand-Einstellungen: Der Export läuft mit `strict_variables`. Eine Vorlage, die
 * `rcFeedImage.url(product)` ruft, bräche sonst ab, sobald jemand die Versandberechnung abschaltet.
 */
final class FeedImageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly FeedImageService $feedImageService,
        private readonly Filesystem $publicFilesystem,
        private readonly ConfigurationService $configurationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductExportRenderBodyContextEvent::class => 'onProductExportRender'];
    }

    public function onProductExportRender(ProductExportRenderBodyContextEvent $event): void
    {
        $context = $event->getContext();
        $salesChannelContext = $context['context'] ?? null;
        $salesChannelId = $salesChannelContext instanceof SalesChannelContext
            ? $salesChannelContext->getSalesChannel()->getId()
            : null;

        $context['rcFeedImage'] = new FeedImageContextProvider(
            $this->feedImageService,
            $this->publicFilesystem,
            $this->configurationService->isFeedImageReplacementEnabled($salesChannelId),
        );
        $event->setContext($context);
    }
}
