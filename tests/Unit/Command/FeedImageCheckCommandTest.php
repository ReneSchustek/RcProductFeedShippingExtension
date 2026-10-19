<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Command\FeedImageCheckCommand;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Die Bildprüfung in der Konsole: Zählung, Liste zum Abarbeiten und Gegenprobe an den Dateien.
 */
#[CoversClass(FeedImageCheckCommand::class)]
final class FeedImageCheckCommandTest extends TestCase
{
    private string $publicPath;

    protected function setUp(): void
    {
        $this->publicPath = sys_get_temp_dir() . '/rc-feed-image-' . bin2hex(random_bytes(4));
        mkdir($this->publicPath . '/media', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->publicPath . '/media/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->publicPath . '/media');
        rmdir($this->publicPath);
    }

    /**
     * Was: Die Zählung mit Artikeln, die sich retten lassen, und solchen, die neues Material brauchen.
     * Warum: Die beiden Fälle brauchen verschiedene Lösungen; die Ausgabe muss sie getrennt nennen.
     * Erwartet: alle fünf Zahlen in der Tabelle, beide Hinweise, Erfolg.
     */
    public function testTheCountsAndBothHintsAreShown(): void
    {
        $tester = $this->execute($this->service(['sufficient' => 7, 'tooSmall' => 3, 'withReplacement' => 1, 'withoutReplacement' => 2, 'withoutImage' => 4]));
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Titelbild groß genug', $display);
        self::assertStringContainsString('1 Artikel lassen sich ohne neues Material retten', $display);
        self::assertStringContainsString('2 Artikel brauchen neues Bildmaterial', $display);
    }

    /**
     * Was: `--list` mit zwei Artikeln, einer ohne jedes Bild.
     * Warum: Die Liste ist die Arbeitsgrundlage für neues Material; ein Artikel ohne Bild muss als solcher erkennbar sein.
     * Erwartet: Nummer, Maß und „kein Bild".
     */
    public function testTheListNamesProductsAndSizes(): void
    {
        $service = $this->service();
        $service->method('productsNeedingNewImages')->willReturn([
            ['productNumber' => 'EG-100', 'name' => 'Geländer', 'width' => 400, 'height' => 300],
            ['productNumber' => 'EG-200', 'name' => 'Handlauf', 'width' => 0, 'height' => 0],
        ]);

        $display = $this->execute($service, ['--list' => true])->getDisplay();

        self::assertStringContainsString('EG-100', $display);
        self::assertStringContainsString('400 x 300', $display);
        self::assertStringContainsString('kein Bild', $display);
    }

    /**
     * Was: `--list`, wenn kein Artikel neues Material braucht.
     * Erwartet: ein Satz statt einer leeren Tabelle.
     */
    public function testAnEmptyListSaysSo(): void
    {
        $service = $this->service();
        $service->method('productsNeedingNewImages')->willReturn([]);

        self::assertStringContainsString('Keiner.', $this->execute($service, ['--list' => true])->getDisplay());
    }

    /**
     * Was: `--verify` mit einer zu kleinen, einer ausreichenden, einer kaputten und einer fehlenden Datei.
     * Warum: Weicht eine Datei von der Mediathek ab, ist der Bestand das Problem, nicht der Feed. Eine kaputte Datei darf
     *        die Prüfung nicht abbrechen.
     * Erwartet: drei gelesene oder unlesbare Dateien gezählt, die zu kleine als Abweichung genannt.
     */
    public function testVerifyComparesTheRealFiles(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD fehlt; ohne GD lassen sich keine Testbilder erzeugen.');
        }

        $this->png('klein.png', 300, 600);
        $this->png('ausreichend.png', 600, 600);
        file_put_contents($this->publicPath . '/media/kaputt.png', 'kein Bild');

        $service = $this->service();
        $service->method('all')->willReturn([
            'aaaaaaaa11111111' => 'media/klein.png',
            'bbbbbbbb22222222' => 'media/ausreichend.png',
            'cccccccc33333333' => '/media/kaputt.png',
            'dddddddd44444444' => 'media/fehlt.png',
        ]);

        $display = $this->execute($service, ['--verify' => true])->getDisplay();

        self::assertStringContainsString('2 Dateien gelesen, 2 nicht erreichbar.', $display);
        self::assertStringContainsString('aaaaaaaa: Datei ist 300 x 600', $display);
    }

    /**
     * Was: `--verify` ohne Abweichung und ohne jedes Bild.
     * Erwartet: die Bestätigung „Die Mediathek stimmt" bzw. der Hinweis, dass nichts nachzumessen ist.
     */
    public function testVerifyWithoutDeviationOrImages(): void
    {
        if (!\function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD fehlt; ohne GD lassen sich keine Testbilder erzeugen.');
        }

        $this->png('ausreichend.png', 800, 500);
        $service = $this->service();
        $service->method('all')->willReturn(['aaaaaaaa11111111' => 'media/ausreichend.png']);
        self::assertStringContainsString('Die Mediathek stimmt', $this->execute($service, ['--verify' => true])->getDisplay());

        $empty = $this->service();
        $empty->method('all')->willReturn([]);
        self::assertStringContainsString('Kein Bild zum Nachmessen gefunden.', $this->execute($empty, ['--verify' => true])->getDisplay());
    }

    /**
     * @param array{sufficient: int, tooSmall: int, withReplacement: int, withoutReplacement: int, withoutImage: int}|null $counts
     */
    private function service(?array $counts = null): FeedImageService&\PHPUnit\Framework\MockObject\MockObject
    {
        $service = $this->createMock(FeedImageService::class);
        $service->method('audit')->willReturn($counts ?? ['sufficient' => 1, 'tooSmall' => 0, 'withReplacement' => 0, 'withoutReplacement' => 0, 'withoutImage' => 0]);

        return $service;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function execute(FeedImageService $service, array $options = []): CommandTester
    {
        $tester = new CommandTester(new FeedImageCheckCommand($service, $this->publicPath));
        $tester->execute($options);

        return $tester;
    }

    private function png(string $name, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $this->publicPath . '/media/' . $name);
    }
}
