<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Console\Command\Command;

abstract class AbstractShippingCommand extends Command
{
    public function __construct(
        protected readonly ShippingCostCalculatorService $calculator,
        protected readonly ConfigurationService $configurationService,
        protected readonly AbstractSalesChannelContextFactory $contextFactory,
        protected readonly EntityRepository $salesChannelRepository,
        protected readonly EntityRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function canCreateContext(string $salesChannelId): bool
    {
        try {
            $this->contextFactory->create(Uuid::randomHex(), $salesChannelId, []);

            return true;
        } catch (\Throwable) {
            // Context-Erstellung kann bei fehlender Sprachkonfiguration fehlschlagen — erwartetes Verhalten
            return false;
        }
    }

    protected function loadActiveSalesChannels(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->salesChannelRepository->search($criteria, $context)->getElements();
    }

    protected function loadActiveProductIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->productRepository->searchIds($criteria, $context)->getIds();
    }
}
