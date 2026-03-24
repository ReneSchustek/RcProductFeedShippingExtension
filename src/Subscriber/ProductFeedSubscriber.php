<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Subscriber;

use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use RuhrCoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injiziert einen ShippingContextProvider in den Feed-Template-Kontext.
 *
 * Das Event `ProductExportRenderBodyContextEvent` feuert einmalig vor der Produkt-Schleife —
 * zu diesem Zeitpunkt ist noch kein einzelnes Produkt im Kontext. Daher wird ein
 * Provider-Objekt in den Kontext gelegt, den das Template per `rcShipping.get(product.id, 'DE')`
 * für jedes Produkt einzeln aufrufen kann.
 *
 * Greift ausschließlich in den Feed-Export ein — niemals in den normalen Shop-Betrieb.
 */
class ProductFeedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ConfigurationService $configurationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductExportRenderBodyContextEvent::class => 'onProductExportRender',
        ];
    }

    /**
     * Fügt dem Template-Kontext einen ShippingContextProvider hinzu.
     *
     * Der Provider wird einmalig konfiguriert (Länder, SalesChannel, Währung) und
     * vom Template pro Produkt aufgerufen. Die Cache-Schicht im Calculator stellt
     * sicher, dass jede Berechnung nur einmal durchgeführt wird.
     */
    public function onProductExportRender(ProductExportRenderBodyContextEvent $event): void
    {
        $context = $event->getContext();

        /** @var SalesChannelContext|null $salesChannelContext */
        $salesChannelContext = $context['context'] ?? null;

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return;
        }

        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        if (!$this->configurationService->isEnabled($salesChannelId)) {
            return;
        }

        $countries = $this->configurationService->getCountries($salesChannelId);
        if (empty($countries)) {
            return;
        }

        $currencyIso = $salesChannelContext->getCurrency()->getIsoCode();

        $calcChannelId = $this->configurationService->getCalculationSalesChannelId($salesChannelId) ?? $salesChannelId;

        $context['rcShipping'] = new ShippingContextProvider(
            $this->calculator,
            $countries,
            $calcChannelId,
            $currencyIso,
        );

        $event->setContext($context);
    }
}
