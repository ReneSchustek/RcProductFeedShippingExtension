<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedChannelProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
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
 * Fehlende Werte rechnet die Prüfung und legt sie wie der Warmup im Speicher ab; den Warenkorb, den sie dafür anlegt,
 * räumt sie am Ende wieder weg.
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
        private readonly VirtualCartBuilderService $cartBuilder,
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

        $totalFallbacks = 0;

        try {
            foreach ($this->selectChannels($io, $context) as $calcChannelId => $job) {
                $io->section(sprintf('%s (Kanal: %s)', $job['name'], substr($calcChannelId, 0, 8)));

                $fallbacks = $this->collectFallbacks(
                    $this->loadActiveProductIds($context),
                    $job['countries'],
                    $calcChannelId,
                );

                $totalFallbacks += count($fallbacks['rows']);
                $this->reportChannel($io, $fallbacks['rows'], $fallbacks['reasons']);
            }
        } finally {
            $this->cartBuilder->removeTrace();
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

    /**
     * Wählt aus, welche Berechnungs-Kanäle geprüft werden — und meldet, was warum wegfällt.
     *
     * Eigene Methode, weil hier fünf voneinander unabhängige Gründe zum Überspringen führen.
     * Zusammen mit dem Sammeln und dem Ausgeben in einer Methode lag die Verzweigungszahl bei 17;
     * die Regel zieht die Grenze bei 15.
     *
     * @return array<string, array{name: string, countries: array<int, string>}> je Berechnungs-Kanal einmal
     */
    private function selectChannels(SymfonyStyle $io, Context $context): array
    {
        $readingChannels = $this->feedChannelProvider->loadReadingChannelIds($context);
        $jobs = [];

        foreach ($this->loadActiveSalesChannels($context) as $salesChannel) {
            $feedChannelId = $salesChannel->getId();
            $name = $salesChannel->getName() ?? $feedChannelId;

            if (!$this->configurationService->isEnabled($feedChannelId)) {
                continue;
            }

            if (!isset($readingChannels[$feedChannelId])) {
                $io->writeln(sprintf('  Übersprungen: %s — kein Produktexport liest diesen Kanal aus.', $name));
                continue;
            }

            $countries = $this->configurationService->getCountries($feedChannelId);
            if ($countries === []) {
                continue;
            }

            $calcChannelId = $this->configurationService->getCalculationSalesChannelId($feedChannelId) ?? $feedChannelId;

            // Mehrere Feed-Kanäle können denselben Berechnungs-Kanal teilen. Ohne Merkliste liefe
            // die Prüfung zweimal über denselben Bestand und nennte jede Kombination doppelt.
            if (isset($jobs[$calcChannelId])) {
                continue;
            }

            $contextError = $this->contextError($calcChannelId);
            if ($contextError !== null) {
                $io->warning(sprintf('Kanal %s: Context-Erstellung fehlgeschlagen (%s). Berechnungs-Kanal prüfen.', $name, $contextError));
                continue;
            }

            $jobs[$calcChannelId] = ['name' => $name, 'countries' => $countries];
        }

        return $jobs;
    }

    /**
     * Rechnet jede Kombination aus Artikel und Land und sammelt die Ersatzwerte ein.
     *
     * @param array<int, string> $productIds
     * @param array<int, string> $countries
     *
     * @return array{rows: array<int, array<int, string>>, reasons: array<string, int>}
     */
    private function collectFallbacks(array $productIds, array $countries, string $calcChannelId): array
    {
        $rows = [];
        /** @var array<string, int> $reasons Zählwerk je Grund — die Einzelzeilen sind zu viele zum Durchsehen */
        $reasons = [];

        foreach ($productIds as $productId) {
            foreach ($countries as $countryIso) {
                $result = $this->calculator->calculate($productId, $countryIso, $calcChannelId);

                if (!$result->isFallback) {
                    continue;
                }

                $reason = $result->fallbackReason?->label() ?? 'unbekannt';
                $rows[] = [
                    substr($productId, 0, 8) . '…',
                    $countryIso,
                    number_format($result->shippingCost, 2, '.', '') . ' ' . $result->currencyIso,
                    $reason,
                ];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }

        return ['rows' => $rows, 'reasons' => $reasons];
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<string, int>             $reasons
     */
    private function reportChannel(SymfonyStyle $io, array $rows, array $reasons): void
    {
        if ($rows === []) {
            $io->writeln('  Keine Fallback-Produkte gefunden.');

            return;
        }

        $io->table(['Produkt-ID (gekürzt)', 'Land', 'Fallback-Preis', 'Grund'], $rows);
        $io->writeln(sprintf('  %d Produkt/Land-Kombinationen mit Fallback.', count($rows)));

        foreach ($reasons as $reason => $count) {
            $io->writeln(sprintf('    davon %s: %d', $reason, $count));
        }
    }
}
