<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\CalculationChannelSelection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;

/**
 * Entscheidet, für welche Berechnungs-Kanäle vorgerechnet wird.
 *
 * Ein Kanal zählt, wenn er aktiv ist, das Plugin dort eingeschaltet ist, ein Produktexport ihn ausliest, Länder
 * eingetragen sind und sich für seinen Berechnungs-Kanal ein Kontext bauen lässt. Mehrere Kanäle mit demselben
 * Berechnungs-Kanal zählen einmal.
 *
 * Warmup, Fortschrittsanzeige und Diagnose fragen alle hier. Stünde die Regel dreimal da, widersprächen sich ihre
 * Zahlen, sobald eine Fassung abweicht: Der Fortschrittsbalken zählte andere Kombinationen, als der Lauf rechnet,
 * und die Diagnose prüfte Kanäle, die kein Warmup füllt.
 */
final readonly class CalculationChannelProvider
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private ConfigurationService $configurationService,
        private AbstractSalesChannelContextFactory $contextFactory,
        private EntityRepository $salesChannelRepository,
        private FeedChannelProviderService $feedChannelProvider,
    ) {
    }

    public function select(Context $context): CalculationChannelSelection
    {
        $selection = new CalculationChannelSelection();
        $readingChannels = $this->feedChannelProvider->loadReadingChannelIds($context);

        foreach ($this->loadActiveSalesChannels($context) as $salesChannel) {
            $feedChannelId = $salesChannel->getId();
            $name = $salesChannel->getName() ?? $feedChannelId;

            // Ausgeschaltet oder ohne Länder: gewollt, keine Meldung.
            if (!$this->configurationService->isEnabled($feedChannelId)) {
                continue;
            }

            if (!isset($readingChannels[$feedChannelId])) {
                $selection->skip($name, CalculationChannelSelection::SKIP_UNREAD);
                continue;
            }

            $countries = $this->configurationService->getCountries($feedChannelId);
            if ($countries === []) {
                continue;
            }

            $calcChannelId = $this->configurationService->getCalculationSalesChannelId($feedChannelId) ?? $feedChannelId;

            if ($selection->has($calcChannelId)) {
                $selection->skip($name, CalculationChannelSelection::SKIP_DUPLICATE, $calcChannelId);
                continue;
            }

            // Vorab prüfen, ob sich ein Kontext bauen lässt (etwa wegen fehlender Sprachkonfiguration). Sonst entstünde
            // derselbe Fehler für jedes Produkt einzeln.
            $contextError = $this->contextError($calcChannelId);
            if ($contextError !== null) {
                $selection->skip($name, CalculationChannelSelection::SKIP_NO_CONTEXT, $calcChannelId, $contextError);
                continue;
            }

            $selection->add($calcChannelId, $name, $countries);
        }

        return $selection;
    }

    /**
     * Warum sich für den Kanal kein Kontext bauen lässt, oder `null`, wenn es geht. Häufigster Grund ist eine fehlende
     * Sprachkonfiguration, etwa bei Produktvergleichs-Kanälen.
     */
    private function contextError(string $salesChannelId): ?string
    {
        try {
            $this->contextFactory->create(Uuid::randomHex(), $salesChannelId, []);

            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    /** @return array<string, \Shopware\Core\System\SalesChannel\SalesChannelEntity> */
    private function loadActiveSalesChannels(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->salesChannelRepository->search($criteria, $context)->getEntities()->getElements();
    }
}
