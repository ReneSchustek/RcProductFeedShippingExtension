<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\CalculationChannelProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\CalculationChannelSelection;
use Shopware\Core\Framework\Context;
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
 * Die Prüfung sieht dieselbe Kanalmenge wie der Warmup. Zählte sie Kanäle mit, die kein Warenstrom
 * ausliest, stünden dort Zahlen ohne Bedeutung für den Warenstrom, und die Fehlersuche liefe an der
 * eigentlichen Ursache vorbei.
 */
#[AsCommand(name: 'rc:shipping:check', description: 'List products using fallback shipping costs')]
final class ShippingCheckCommand extends AbstractShippingCommand
{
    public function __construct(
        ShippingCostCalculatorService $calculator,
        ConfigurationService $configurationService,
        ActiveProductProviderService $activeProductProvider,
        private readonly CalculationChannelProvider $channelProvider,
        private readonly VirtualCartBuilderService $cartBuilder,
    ) {
        parent::__construct($calculator, $configurationService, $activeProductProvider);
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
     * Die Berechnungs-Kanäle der Prüfung; dieselben wie beim Warmup. Was wegfällt, steht mit Grund in der Konsole.
     *
     * @return array<string, array{name: string, countries: array<int, string>}> je Berechnungs-Kanal einmal
     */
    private function selectChannels(SymfonyStyle $io, Context $context): array
    {
        $selection = $this->channelProvider->select($context);

        foreach ($selection->skipped() as $skipped) {
            if ($skipped['reason'] === CalculationChannelSelection::SKIP_UNREAD) {
                $io->writeln(sprintf('  Übersprungen: %s — kein Produktexport liest diesen Kanal aus.', $skipped['name']));
            } elseif ($skipped['reason'] === CalculationChannelSelection::SKIP_NO_CONTEXT) {
                $io->warning(sprintf('Kanal %s: Context-Erstellung fehlgeschlagen (%s). Berechnungs-Kanal prüfen.', $skipped['name'], $skipped['detail']));
            }
        }

        return $selection->channels();
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
        /** @var array<string, int> $reasons Zählwerk je Grund, weil die Einzelzeilen zu viele zum Durchsehen sind */
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
