<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedChannelProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Listet Produkte auf, für die keine reguläre Versandmethode gefunden wurde
 * und daher auf den Fallback-Preis zurückgegriffen wird.
 *
 * Hilfreich um Konfigurationslücken in Versandregeln oder -methoden zu erkennen.
 *
 * **Dieselbe Kanalmenge wie der Warmup.** Zählte die Prüfung Kanäle mit, die kein Warenstrom
 * ausliest, stünden dort Zahlen ohne Bedeutung für den Warenstrom — genau daran ist die
 * Fehlersuche am 2026-08-19 zweimal vorbeigelaufen.
 */
#[AsCommand(name: 'rc:shipping:check', description: 'List products using fallback shipping costs')]
final class ShippingCheckCommand extends AbstractShippingCommand
{
    /**
     * @param EntityRepository<SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        ShippingCostCalculatorService $calculator,
        ConfigurationService $configurationService,
        AbstractSalesChannelContextFactory $contextFactory,
        EntityRepository $salesChannelRepository,
        ActiveProductProviderService $activeProductProvider,
        private readonly FeedChannelProviderService $feedChannelProvider,
    ) {
        parent::__construct(
            $calculator,
            $configurationService,
            $contextFactory,
            $salesChannelRepository,
            $activeProductProvider,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $context = Context::createDefaultContext();

        $salesChannels = $this->loadActiveSalesChannels($context);
        $readingChannels = $this->feedChannelProvider->loadReadingChannelIds($context);
        $processedCalcChannels = [];
        $totalFallbacks = 0;

        foreach ($salesChannels as $salesChannel) {
            $feedChannelId = $salesChannel->getId();

            if (!$this->configurationService->isEnabled($feedChannelId)) {
                continue;
            }

            if (!isset($readingChannels[$feedChannelId])) {
                $io->writeln(sprintf(
                    '  Übersprungen: %s — kein Produktexport liest diesen Kanal aus.',
                    $salesChannel->getName() ?? $feedChannelId,
                ));
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
            /** @var array<string, int> $reasons Zählwerk je Grund — die Einzelzeilen sind zu viele zum Durchsehen */
            $reasons = [];

            foreach ($productIds as $productId) {
                foreach ($countries as $countryIso) {
                    $result = $this->calculator->calculate($productId, $countryIso, $calcChannelId);

                    if ($result->isFallback) {
                        $reason = $result->fallbackReason?->label() ?? 'unbekannt';
                        $fallbackRows[] = [
                            substr($productId, 0, 8) . '…',
                            $countryIso,
                            number_format($result->shippingCost, 2, '.', '') . ' ' . $result->currencyIso,
                            $reason,
                        ];
                        $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                        ++$totalFallbacks;
                    }
                }
            }

            if (empty($fallbackRows)) {
                $io->writeln('  Keine Fallback-Produkte gefunden.');
            } else {
                $io->table(['Produkt-ID (gekürzt)', 'Land', 'Fallback-Preis', 'Grund'], $fallbackRows);
                $io->writeln(sprintf('  %d Produkt/Land-Kombinationen mit Fallback.', count($fallbackRows)));

                foreach ($reasons as $reason => $count) {
                    $io->writeln(sprintf('    davon %s: %d', $reason, $count));
                }
            }
        }

        if ($totalFallbacks > 0) {
            $io->warning(sprintf(
                'Gesamt: %d Kombination(en) verwenden den Fallback-Preis. '
                . 'Versandmethoden und Verfügbarkeitsregeln prüfen.',
                $totalFallbacks,
            ));

            return self::FAILURE;
        }

        $io->success('Alle Produkte haben reguläre Versandkosten.');

        return self::SUCCESS;
    }
}
