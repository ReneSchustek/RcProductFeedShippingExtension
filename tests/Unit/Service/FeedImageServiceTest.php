<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;

/**
 * Googles Mindestmaß für `image_link` ab dem 31.01.2027.
 *
 * Die Tests halten die beiden Fälle auseinander, die verschiedene Lösungen brauchen: ein Artikel
 * mit einem anderen ausreichend großen Bild ist eine Sache des Feeds, ein Artikel ohne jedes
 * ausreichende Bild braucht neues Material. Wer beides zusammenwirft, verlinkt am Ende ein
 * hochskaliertes Bild — es bestünde Googles Prüfung und stünde unscharf im Shop.
 */
class FeedImageServiceTest extends TestCase
{
    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
    }

    /**
     * Was: Ein Artikel, dessen Titelbild 400 x 400 misst und dessen zweites Bild 800 x 800.
     * Warum: Der häufigste Fall im Bestand — 313 von 413 zu kleinen Titelbildern haben ein
     *        ausreichendes Geschwisterbild. Ohne diese Auswahl bräuchten sie alle neues Material.
     * Erwartet: das große Bild gewinnt.
     */
    public function testALargeSiblingImageIsUsedWhenTheCoverIsTooSmall(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/klein.jpg', 400, 400, isCover: true, position: 0),
            $this->row('produkt-1', 'media/breit.jpg', 800, 800, isCover: false, position: 1),
        ]);

        self::assertSame('media/breit.jpg', $this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Titelbild und Geschwisterbild sind beide groß genug.
     * Warum: Der Betreiber hat das Titelbild bewusst gewählt. Ein Wechsel ohne Not wäre eine
     *        stillschweigende Änderung des Feeds.
     * Erwartet: das Titelbild bleibt.
     */
    public function testTheCoverWinsWhenItIsLargeEnough(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/titel.jpg', 900, 900, isCover: true, position: 0),
            $this->row('produkt-1', 'media/anderes.jpg', 1200, 1200, isCover: false, position: 1),
        ]);

        self::assertSame('media/titel.jpg', $this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Ein Bild mit 800 x 400.
     * Warum: Google verlangt **beide** Kanten mit mindestens 500 Pixeln. Eine breite Fassung
     *        erfüllt die Vorgabe nicht — und im Bestand sind 500 x 250 und 500 x 333 genau die
     *        zweit- und dritthäufigsten zu kleinen Maße.
     * Erwartet: nicht verwendet.
     */
    public function testAWideImageWithATooShortEdgeDoesNotCount(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/breit.jpg', 800, 400, isCover: true, position: 0),
        ]);

        self::assertNull($this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Ein Artikel, dessen Bilder alle zu klein sind.
     * Warum: Er braucht neues Material. Ein hochskaliertes Bild wäre der bequeme Ausweg und genau
     *        der Fehler, den diese Prüfung ausschließt.
     * Erwartet: kein Ergebnis — und damit erscheint er in der Liste zum Abarbeiten.
     */
    public function testAProductWithoutAnySufficientImageGetsNothing(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/a.jpg', 400, 400, isCover: true, position: 0),
            $this->row('produkt-1', 'media/b.jpg', 480, 400, isCover: false, position: 1),
        ]);

        self::assertNull($this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Zwei ausreichend große Bilder, keines davon Titelbild.
     * Warum: Dann gilt die Reihenfolge, die der Betreiber am Artikel gepflegt hat — nicht die
     *        Reihenfolge, in der die Datenbank sie zufällig liefert.
     * Erwartet: das mit der kleineren Position.
     */
    public function testThePositionDecidesBetweenTwoCandidates(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/erstes.jpg', 600, 600, isCover: false, position: 1),
            $this->row('produkt-1', 'media/zweites.jpg', 900, 900, isCover: false, position: 2),
        ]);

        self::assertSame('media/erstes.jpg', $this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Ein Bild ohne Pfad in der Mediathek.
     * Warum: Eine leere Adresse im `image_link` macht den Feed ungültig — schlimmer als ein
     *        fehlendes Bild.
     * Erwartet: übersprungen.
     */
    public function testAMediaRowWithoutAPathIsSkipped(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', '', 900, 900, isCover: true, position: 0),
            $this->row('produkt-1', 'media/gut.jpg', 700, 700, isCover: false, position: 1),
        ]);

        self::assertSame('media/gut.jpg', $this->service()->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Zweimal dieselbe Abfrage.
     * Warum: Der Warenstrom rendert mehrere tausend Artikel in einem Durchgang. Eine Abfrage je
     *        Artikel wäre an dieser Stelle der teuerste denkbare Weg.
     * Erwartet: genau eine Abfrage.
     */
    public function testTheDataIsLoadedOnlyOnce(): void
    {
        $this->connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([$this->row('produkt-1', 'media/breit.jpg', 800, 800, isCover: true, position: 0)]);

        $service = $this->service();
        $service->resolveImageUrl('produkt-1');
        $service->resolveImageUrl('produkt-1');
    }

    private function service(): FeedImageService
    {
        return new FeedImageService($this->connection);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Was: Titelbild 400 x 400, das zweite Bild ist eine große Maßzeichnung, das dritte ein großes Foto.
     * Warum: Renes Entscheidung vom 13.10.: Eine Zeichnung ist kein Hauptbild für Google.
     * Erwartet: das Foto, nicht die Zeichnung.
     */
    public function testSkipsDimensionDrawings(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/E_4191.jpg', 400, 400, isCover: true, position: 0),
            $this->row('produkt-1', 'media/Zeichnung_E 4191,122.jpg', 1200, 900, isCover: false, position: 1),
            $this->row('produkt-1', 'media/einbau.jpg', 1000, 800, isCover: false, position: 2),
        ]);

        self::assertSame('media/einbau.jpg', (new FeedImageService($this->connection))->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Nur eine große Maßzeichnung neben dem zu kleinen Titelbild.
     * Erwartet: kein Ersatz; der Artikel gehört auf die Liste für neues Material.
     */
    public function testADrawingAloneIsNoReplacement(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/E_4191.jpg', 400, 400, isCover: true, position: 0),
            $this->row('produkt-1', 'media/Zeichnung_E 4191,122.jpg', 1200, 900, isCover: false, position: 1),
        ]);

        self::assertNull((new FeedImageService($this->connection))->resolveImageUrl('produkt-1'));
    }

    /**
     * Was: Das Titelbild selbst heißt „Zeichnung" und ist groß genug.
     * Erwartet: Es bleibt; die Regel gilt nur für Ersatzbilder.
     */
    public function testASufficientCoverStaysEvenIfItIsADrawing(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            $this->row('produkt-1', 'media/Zeichnung_X.jpg', 800, 800, isCover: true, position: 0),
        ]);

        self::assertSame('media/Zeichnung_X.jpg', (new FeedImageService($this->connection))->resolveImageUrl('produkt-1'));
    }

    private function row(
        string $productId,
        string $path,
        int $width,
        int $height,
        bool $isCover,
        int $position,
    ): array {
        return [
            'product_id' => $productId,
            'path' => $path,
            'file_name' => basename($path),
            'file_extension' => 'jpg',
            'width' => $width,
            'height' => $height,
            'is_cover' => $isCover ? 1 : 0,
            'position' => $position,
        ];
    }
}
