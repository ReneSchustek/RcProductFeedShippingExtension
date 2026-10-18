<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Struct;

use League\Flysystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FeedImageContextProvider;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaEntity;
use Shopware\Core\Content\Product\ProductEntity;

/**
 * `rcFeedImage.url(product)` im Warenstrom: Wann bleibt das Titelbild, wann kommt ein
 * größeres Bild desselben Artikels?
 */
#[CoversClass(FeedImageContextProvider::class)]
final class FeedImageContextProviderTest extends TestCase
{
    /**
     * Was: Austausch in den Einstellungen aus, Titelbild zu klein.
     * Warum: Die Vorgabe darf den Feed nicht ändern; die Vorlage lässt sich umstellen, bevor der
     *        Austausch eingeschaltet wird.
     * Erwartet: das Titelbild, wie mit `product.cover.media.url`.
     */
    public function testReturnsTheCoverWhenReplacementIsDisabled(): void
    {
        $provider = $this->provider(['p1' => 'media/breit.jpg'], enabled: false);

        self::assertSame('https://shop/klein.jpg', $provider->url($this->product('p1', null, 400, 400)));
    }

    /**
     * Was: Austausch an, Titelbild 800 × 800.
     * Warum: Ein ausreichendes Titelbild bleibt, samt der Adresse mit Zeitstempel, die Shopware vergibt.
     * Erwartet: das Titelbild.
     */
    public function testKeepsASufficientCover(): void
    {
        $provider = $this->provider(['p1' => 'media/anderes.jpg'], enabled: true);

        self::assertSame('https://shop/klein.jpg', $provider->url($this->product('p1', null, 800, 800)));
    }

    /**
     * Was: Austausch an, Titelbild 500 × 250, ein anderes Bild des Artikels ist groß genug.
     * Warum: Der häufige Fall; bei 500 × 250 reißt nur eine Kante die Vorgabe.
     * Erwartet: die öffentliche Adresse des größeren Bildes.
     */
    public function testUsesTheLargerImageOfTheSameProduct(): void
    {
        $provider = $this->provider(['p1' => 'media/breit.jpg'], enabled: true);

        self::assertSame('https://cdn/media/breit.jpg', $provider->url($this->product('p1', null, 500, 250)));
    }

    /**
     * Was: Ersatzbild mit Leerzeichen und Komma im Dateinamen.
     * Warum: So heißen die Maßbilder in der Mediathek; roh wäre die Adresse im Feed ungültig.
     * Erwartet: jeder Pfadteil kodiert, Schrägstriche bleiben.
     */
    public function testEncodesFileNamesWithSpacesAndCommas(): void
    {
        $provider = $this->provider(['p1' => 'media/91/a7/Zeichnung_E 4191,122.jpg'], enabled: true);

        self::assertSame('https://cdn/media/91/a7/Zeichnung_E%204191%2C122.jpg', $provider->url($this->product('p1', null, 400, 400)));
    }

    /**
     * Was: Variante ohne eigene Bilder, der Hauptartikel hat ein großes.
     * Warum: Die Bilder einer solchen Variante hängen am Hauptartikel, so zeigt sie auch der Shop.
     * Erwartet: das Bild des Hauptartikels.
     */
    public function testAVariantFallsBackToItsParent(): void
    {
        $provider = $this->provider(['parent' => 'media/haupt.jpg'], enabled: true);

        self::assertSame('https://cdn/media/haupt.jpg', $provider->url($this->product('variante', 'parent', 400, 400)));
    }

    /**
     * Was: Austausch an, kein ausreichendes Bild.
     * Warum: Nie hochrechnen; der Artikel gehört auf die Liste für neues Material.
     * Erwartet: das (zu kleine) Titelbild.
     */
    public function testKeepsTheCoverWhenThereIsNoReplacement(): void
    {
        $provider = $this->provider([], enabled: true);

        self::assertSame('https://shop/klein.jpg', $provider->url($this->product('p1', null, 400, 400)));
    }

    /**
     * Was: Artikel ohne Titelbild, aber mit einem großen Bild.
     * Erwartet: das große Bild; ohne jedes Bild `null`, dann schreibt die Vorlage keinen Eintrag.
     */
    public function testWorksWithoutACover(): void
    {
        $withImage = $this->provider(['p1' => 'media/breit.jpg'], enabled: true);
        $without = $this->provider([], enabled: true);

        $product = new ProductEntity();
        $product->setId('p1');

        self::assertSame('https://cdn/media/breit.jpg', $withImage->url($product));
        self::assertNull($without->url($product));
    }

    /**
     * @param array<string, string> $resolved Produkt-Id => Pfad des ausreichend großen Bildes
     */
    private function provider(array $resolved, bool $enabled): FeedImageContextProvider
    {
        $service = $this->createMock(FeedImageService::class);
        $service->method('resolveImageUrl')->willReturnCallback(static fn (string $id): ?string => $resolved[$id] ?? null);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('publicUrl')->willReturnCallback(static fn (string $path): string => 'https://cdn/' . $path);

        return new FeedImageContextProvider($service, $filesystem, $enabled);
    }

    private function product(string $id, ?string $parentId, int $width, int $height): ProductEntity
    {
        $media = new MediaEntity();
        $media->setId('m-' . $id);
        $media->setUrl('https://shop/klein.jpg');
        $media->setMetaData(['width' => $width, 'height' => $height]);

        $cover = new ProductMediaEntity();
        $cover->setId('c-' . $id);
        $cover->setMedia($media);

        $product = new ProductEntity();
        $product->setId($id);
        if ($parentId !== null) {
            $product->setParentId($parentId);
        }
        $product->setCover($cover);

        return $product;
    }
}
