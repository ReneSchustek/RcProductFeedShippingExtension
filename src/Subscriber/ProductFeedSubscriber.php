<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Subscriber;

use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderBodyContextEvent;
use Shopware\Core\Content\ProductExport\Event\ProductExportRenderFooterContextEvent;
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
final class ProductFeedSubscriber implements EventSubscriberInterface
{
    /**
     * Der Provider des laufenden Exports, damit am Fuß des Feeds eine Zusammenfassung
     * geschrieben werden kann. Ein Export läuft je Request einzeln durch; ein Feld genügt.
     */
    private ?ShippingContextProvider $currentProvider = null;

    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ShippingFallbackService $fallbackService,
        private readonly ConfigurationService $configurationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductExportRenderBodyContextEvent::class => 'onProductExportRender',
            ProductExportRenderFooterContextEvent::class => 'onProductExportFooter',
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

        $provider = new ShippingContextProvider(
            $this->calculator,
            $this->fallbackService,
            $countries,
            $calcChannelId,
            $currencyIso,
            $this->logger,
            $this->configurationService->shouldOmitCountryWithoutShippingMethod($salesChannelId),
        );

        $context['rcShipping'] = $provider;
        $this->currentProvider = $provider;

        $event->setContext($context);
    }

    /**
     * Schreibt am Ende des Feeds eine Zeile, wenn Produkte den Ersatzwert bekommen haben.
     *
     * **Vorbehalt:** Shopware löst dieses Ereignis nur aus, wenn der Export eine Fuß-Vorlage hat
     * (`ProductExportRenderer::renderFooter` steigt bei `null` vorher aus). Die Einzelwarnung je
     * Land in `ShippingContextProvider::get()` kommt unabhängig davon — die Zusammenfassung ist die
     * Zugabe, nicht die einzige Meldung.
     */
    public function onProductExportFooter(ProductExportRenderFooterContextEvent $event): void
    {
        $this->currentProvider?->logSummary();
        $this->currentProvider = null;
    }
}
