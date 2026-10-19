<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Console\Command\Command;

abstract class AbstractShippingCommand extends Command
{
    public function __construct(
        protected readonly ShippingCostCalculatorService $calculator,
        protected readonly ConfigurationService $configurationService,
        protected readonly ActiveProductProviderService $activeProductProvider,
    ) {
        parent::__construct();
    }

    /** @return array<int, string> */
    protected function loadActiveProductIds(Context $context): array
    {
        return $this->activeProductProvider->loadActiveProductIds($context);
    }
}
