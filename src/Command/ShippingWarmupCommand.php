<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingWarmupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Füllt den Versandkosten-Speicher für alle aktiven Produkte und Länder vorab.
 *
 * Der Feed rechnet nicht selbst — er kann es nicht, siehe `ShippingContextProvider`. Ohne einen
 * vorherigen Warmup liefert er den Ersatzwert. Im Regelbetrieb erledigt das die geplante Aufgabe
 * `rc_product_feed_shipping.warmup`; dieses Kommando ist der Weg von Hand: nach der
 * Erstinstallation, nach Änderungen an Versandmethoden oder -regeln und nach einem vollständigen
 * Cache-Clear.
 *
 * Die Arbeit selbst steckt in `ShippingWarmupService`, damit Kommando und geplante Aufgabe
 * nachweislich dasselbe tun.
 */
#[AsCommand(name: 'rc:shipping:warmup', description: 'Versandkosten für alle Produkte vorberechnen und speichern')]
final class ShippingWarmupCommand extends Command
{
    public function __construct(private readonly ShippingWarmupService $warmupService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $total = $this->warmupService->combinationCount();
        if ($total === 0) {
            $io->warning(
                'Nichts zu tun: kein aktiver Verkaufskanal mit eingeschaltetem Plugin, konfigurierten Ländern '
                . 'und gültigem Berechnungs-Kanal.'
            );

            return self::SUCCESS;
        }

        $progressBar = $io->createProgressBar($total);

        $result = $this->warmupService->warmup(
            static function (int $steps) use ($progressBar): void {
                $progressBar->advance($steps);
            },
            static function (string $message) use ($io, $progressBar): void {
                $progressBar->clear();
                $io->writeln($message);
                $progressBar->display();
            },
        );

        $progressBar->finish();
        $io->newLine(2);

        $io->writeln(sprintf(
            'Berechnet: %d · Ersatzwert: %d · Gesamt: %d',
            $result->calculated,
            $result->fallback,
            $result->total(),
        ));

        foreach ($result->skippedChannels as $hint) {
            $io->warning($hint);
        }

        // Ein Lauf ohne eine einzige echte Berechnung sieht sonst aus wie ein Erfolg. Genau dieser
        // Zustand bestand monatelang, ohne dass ihn etwas gemeldet hätte.
        if ($result->fallbacksOnly()) {
            $io->error(
                'Kein einziger Preis wurde berechnet — alle Werte sind Ersatzwerte. '
                . 'Berechnungs-Verkaufskanal, Versandmethoden und Verfügbarkeitsregeln prüfen; '
                . 'Details liefert rc:shipping:check.'
            );

            return self::FAILURE;
        }

        $io->success('Warmup abgeschlossen.');

        return self::SUCCESS;
    }
}
