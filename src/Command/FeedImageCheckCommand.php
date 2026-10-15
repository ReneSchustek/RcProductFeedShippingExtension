<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Command;

use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zählt, wie viele Artikel Googles Mindestmaß für `image_link` verfehlen.
 *
 * **Ab dem 31.01.2027 verlangt das Merchant Center 500 × 500 Pixel**, beide Kanten. Wer darunter
 * liegt, wird nicht mehr genehmigt und erscheint nicht mehr bei Google.
 *
 * Der Befehl nennt drei Zahlen und trennt dabei die beiden Fälle, die verschiedene Lösungen
 * brauchen: Ein Artikel mit einem **anderen** ausreichend großen Bild ist eine Sache des Feeds; ein
 * Artikel ohne jedes ausreichende Bild braucht neues Material und keine Programmänderung.
 *
 * Mit `--liste` erscheinen die Artikel der zweiten Gruppe mit Nummer, Namen und heutigem Maß —
 * die Liste zum Abarbeiten.
 *
 * **`--nachmessen` lädt die Bilddateien wirklich** und vergleicht ihre Maße mit den Angaben der
 * Mediathek. Standardmäßig an einer Stichprobe: Die Angaben stammen aus Shopwares Analyse beim
 * Hochladen und sind fast immer richtig; sie **alle** nachzuladen kostet mehrere tausend Abrufe.
 * Weicht die Stichprobe ab, ist das ein eigener Befund — dann stimmt die Mediathek nicht.
 */
#[AsCommand(
    name: 'rc:feed:image-check',
    description: 'Prüft, welche Artikel Googles Mindestmaß von 500 x 500 Pixeln für image_link verfehlen',
)]
final class FeedImageCheckCommand extends Command
{
    /** So viele Dateien werden ohne weitere Angabe wirklich geladen. */
    private const SAMPLE_SIZE = 25;

    public function __construct(
        private readonly FeedImageService $imageService,
        private readonly string $publicPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('liste', null, InputOption::VALUE_NONE, 'Die Artikel ohne ausreichendes Bild einzeln nennen');
        $this->addOption('nachmessen', null, InputOption::VALUE_NONE, 'Bilddateien wirklich laden und gegen die Mediathek halten');
        $this->addOption('stichprobe', null, InputOption::VALUE_REQUIRED, 'Wie viele Dateien nachgemessen werden', (string) self::SAMPLE_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $counts = $this->imageService->audit();

        $io->title('Bildmaße im Warenstrom');
        $io->writeln('Googles Vorgabe ab dem 31.01.2027: beide Kanten mindestens 500 Pixel.');
        $io->newLine();

        $io->table(
            ['', 'Artikel'],
            [
                ['Titelbild groß genug', (string) $counts['sufficient']],
                ['Titelbild zu klein', (string) $counts['tooSmall']],
                ['  davon mit einem anderen ausreichenden Bild', (string) $counts['withReplacement']],
                ['  davon ohne jedes ausreichende Bild', (string) $counts['withoutReplacement']],
                ['ohne Titelbild', (string) $counts['withoutImage']],
            ]
        );

        if ($counts['withReplacement'] > 0) {
            $io->writeln(sprintf(
                '<info>%d Artikel lassen sich ohne neues Material retten</info> — der Warenstrom verlinkt '
                . 'dort statt des Titelbildes das erste ausreichend große Bild desselben Artikels.',
                $counts['withReplacement'],
            ));
        }

        if ($counts['withoutReplacement'] > 0) {
            $io->writeln(sprintf(
                '<comment>%d Artikel brauchen neues Bildmaterial.</comment> Hochskalieren ist keine Lösung: '
                . 'Google nähme das Bild an, im Shop stünde es unscharf.',
                $counts['withoutReplacement'],
            ));
        }

        if ($input->getOption('nachmessen')) {
            $this->verifyFiles($io, (int) $input->getOption('stichprobe'));
        }

        if ($input->getOption('liste')) {
            $this->printList($io);
        }

        return self::SUCCESS;
    }

    /**
     * Lädt Bilddateien und hält ihre wahren Maße gegen die Angaben der Mediathek.
     */
    private function verifyFiles(SymfonyStyle $io, int $sampleSize): void
    {
        $io->section('Gegenprobe an den Dateien');

        $paths = array_slice($this->imageService->all(), 0, max(1, $sampleSize));

        if ($paths === []) {
            $io->writeln('Kein Bild zum Nachmessen gefunden.');

            return;
        }

        $checked = 0;
        $deviations = [];
        $unreadable = 0;

        foreach ($paths as $productId => $path) {
            $file = rtrim($this->publicPath, '/') . '/' . ltrim($path, '/');

            if (!is_readable($file)) {
                ++$unreadable;

                continue;
            }

            $size = self::imageSize($file);
            if ($size === null) {
                ++$unreadable;

                continue;
            }

            ++$checked;

            if ($size[0] < FeedImageService::MINIMUM_EDGE || $size[1] < FeedImageService::MINIMUM_EDGE) {
                $deviations[] = sprintf('%s: Datei ist %d x %d', substr($productId, 0, 8), $size[0], $size[1]);
            }
        }

        $io->writeln(sprintf('%d Dateien gelesen, %d nicht erreichbar.', $checked, $unreadable));

        if ($deviations === []) {
            $io->writeln('<info>Die Mediathek stimmt</info> — keine Datei war kleiner, als sie angibt.');

            return;
        }

        $io->warning('Die Mediathek weicht ab. Dann ist nicht der Feed das Problem, sondern der Bestand:');
        $io->listing($deviations);
    }

    private function printList(SymfonyStyle $io): void
    {
        $list = $this->imageService->productsNeedingNewImages();

        $io->section(sprintf('Artikel ohne ausreichendes Bild (%d)', count($list)));

        if ($list === []) {
            $io->writeln('Keiner. Jeder Artikel hat mindestens ein Bild mit 500 x 500 Pixeln.');

            return;
        }

        $io->table(
            ['Artikelnummer', 'Bezeichnung', 'heutiges Maß'],
            array_map(
                static fn (array $line): array => [
                    $line['productNumber'],
                    mb_substr($line['name'], 0, 60),
                    $line['width'] > 0 ? $line['width'] . ' x ' . $line['height'] : 'kein Bild',
                ],
                $list
            )
        );
    }

    /**
     * Breite und Höhe eines Bildes, `null` für eine Datei, die kein lesbares Bild ist.
     *
     * `getimagesize()` meldet eine beschädigte oder fremde Datei mit einer PHP-Warnung. Der Fehlerhandler fängt genau diese
     * eine Meldung ab; die Datei zählt dann als unlesbar, statt die Prüfung abzubrechen.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function imageSize(string $file): ?array
    {
        set_error_handler(static fn (): bool => true, \E_WARNING | \E_NOTICE);
        try {
            $size = getimagesize($file);
        } finally {
            restore_error_handler();
        }

        return $size === false ? null : [$size[0], $size[1]];
    }
}
