<?php

declare(strict_types=1);

namespace RuhrCoder\RcProductFeedShippingExtension\Command;

use RuhrCoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use RuhrCoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Listet Produkte auf, für die keine reguläre Versandmethode gefunden wurde
 * und daher auf den Fallback-Preis zurückgegriffen wird.
 *
 * Hilfreich um Konfigurationslücken in Versandregeln oder -methoden zu erkennen.
 */
#[AsCommand(name: 'rc:shipping:check', description: 'Produkte mit Fallback-Versandkosten auflisten')]
class ShippingCheckCommand extends Command
{
    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ConfigurationService $configurationService,
        private readonly AbstractSalesChannelContextFactory $contextFactory,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $productRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $salesChannels = $this->loadActiveSalesChannels($context);
        $processedCalcChannels = [];
        $totalFallbacks = 0;

        foreach ($salesChannels as $salesChannel) {
            $feedChannelId = $salesChannel->getId();

            if (!$this->configurationService->isEnabled($feedChannelId)) {
                continue;
            }

            $countries = $this->configurationService->getCountries($feedChannelId);
            if (empty($countries)) {
                continue;
            }

            $calcChannelId = $this->configurationService->getCalculationSalesChannelId($feedChannelId) ?? $feedChannelId;

            if (isset($processedCalcChannels[$calcChannelId])) {
                continue;
            }

            if (!$this->canCreateContext($calcChannelId)) {
                $io->warning(sprintf(
                    'Kanal %s: Context-Erstellung fehlgeschlagen — Berechnungs-Kanal prüfen.',
                    $salesChannel->getName(),
                ));
                continue;
            }

            $processedCalcChannels[$calcChannelId] = true;

            $io->section(sprintf('%s (Kanal: %s)', $salesChannel->getName(), substr($calcChannelId, 0, 8)));

            $productIds = $this->loadActiveProductIds($context);
            $fallbackRows = [];

            foreach ($productIds as $productId) {
                foreach ($countries as $countryIso) {
                    $result = $this->calculator->calculate($productId, $countryIso, $calcChannelId);

                    if ($result->isFallback) {
                        $fallbackRows[] = [
                            substr($productId, 0, 8) . '…',
                            $countryIso,
                            number_format($result->shippingCost, 2, '.', '') . ' ' . $result->currencyIso,
                        ];
                        ++$totalFallbacks;
                    }
                }
            }

            if (empty($fallbackRows)) {
                $io->writeln('  Keine Fallback-Produkte gefunden.');
            } else {
                $io->table(['Produkt-ID (gekürzt)', 'Land', 'Fallback-Preis'], $fallbackRows);
                $io->writeln(sprintf('  %d Produkt/Land-Kombinationen mit Fallback.', count($fallbackRows)));
            }
        }

        if ($totalFallbacks > 0) {
            $io->warning(sprintf(
                'Gesamt: %d Kombination(en) verwenden den Fallback-Preis. '
                . 'Versandmethoden und Verfügbarkeitsregeln prüfen.',
                $totalFallbacks,
            ));

            return Command::FAILURE;
        }

        $io->success('Alle Produkte haben reguläre Versandkosten.');

        return Command::SUCCESS;
    }

    private function canCreateContext(string $salesChannelId): bool
    {
        try {
            $this->contextFactory->create(\Shopware\Core\Framework\Uuid\Uuid::randomHex(), $salesChannelId, []);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function loadActiveSalesChannels(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->salesChannelRepository->search($criteria, $context)->getElements();
    }

    private function loadActiveProductIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('active', true));

        return $this->productRepository->searchIds($criteria, $context)->getIds();
    }
}
