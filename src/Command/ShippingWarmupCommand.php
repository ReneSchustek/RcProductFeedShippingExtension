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
 * Befüllt den Versandkosten-Cache für alle aktiven Produkte und Länder vorab.
 *
 * Für jeden aktivierten Feed-Kanal wird der konfigurierte Berechnungs-Kanal
 * (calculationSalesChannelId) verwendet. Teilen sich mehrere Feed-Kanäle
 * denselben Berechnungs-Kanal, wird dieser nur einmal durchlaufen.
 *
 * Sinnvoll nach der Erstinstallation, nach Änderungen an Versandmethoden oder
 * Versandregeln sowie nach einem vollständigen Cache-Clear.
 */
#[AsCommand(name: 'rc:shipping:warmup', description: 'Versandkosten für alle Produkte vorberechnen und cachen')]
class ShippingWarmupCommand extends Command
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
        $io->writeln(sprintf('Found %d active sales channel(s).', count($salesChannels)));

        // Deduplizierung: Mehrere Feed-Kanäle können denselben Berechnungs-Kanal teilen.
        // Warmup pro einzigartiger Berechnungs-Kanal-ID, nicht pro Feed-Kanal.
        $processedCalcChannels = [];

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
                $io->writeln(sprintf(
                    'Skipping: %s (Berechnungs-Kanal %s bereits verarbeitet)',
                    $salesChannel->getName(),
                    substr($calcChannelId, 0, 8),
                ));
                continue;
            }

            // Berechnungs-Kanal vorab prüfen — schlägt die Context-Erstellung fehl
            // (z.B. fehlende Sprachkonfiguration), überspringen wir den ganzen Kanal
            // statt für jedes Produkt einen Fehler zu produzieren.
            if (!$this->canCreateContext($calcChannelId)) {
                $io->warning(sprintf(
                    'Skipping: %s → Berechnungs-Kanal %s kann keinen Context erstellen. '
                    . 'Bitte unter Plugin-Einstellungen einen gültigen Storefront-Kanal konfigurieren.',
                    $salesChannel->getName(),
                    substr($calcChannelId, 0, 8),
                ));
                continue;
            }

            $processedCalcChannels[$calcChannelId] = true;

            $io->writeln(sprintf(
                'Processing: %s → Berechnungs-Kanal: %s (%d countries)',
                $salesChannel->getName(),
                substr($calcChannelId, 0, 8),
                count($countries),
            ));

            $productIds = $this->loadActiveProductIds($context);
            $io->writeln(sprintf('  Products: %d', count($productIds)));

            $this->warmupProducts($productIds, $countries, $calcChannelId, $io);
        }

        $io->success('Warmup completed.');

        return Command::SUCCESS;
    }

    private function warmupProducts(array $productIds, array $countries, string $calcChannelId, SymfonyStyle $io): void
    {
        $total = count($productIds) * count($countries);
        $progressBar = $io->createProgressBar($total);

        foreach ($productIds as $productId) {
            foreach ($countries as $countryIso) {
                $this->calculator->calculate($productId, $countryIso, $calcChannelId);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine();
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
