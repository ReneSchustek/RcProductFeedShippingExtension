<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

use League\Flysystem\Filesystem;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Die Adresse für `image_link` im Warenstrom.
 *
 * Die Vorlage ruft `rcFeedImage.url(product)` statt `product.cover.media.url`. Ist der Austausch in den
 * Einstellungen aus (Vorgabe), kommt genau das Titelbild heraus, also dasselbe wie mit
 * `product.cover.media.url`; eine Vorlage lässt sich so umstellen, bevor der Austausch eingeschaltet wird.
 *
 * Ist er an und das Titelbild kleiner als 500 × 500, kommt das erste ausreichend große Bild desselben Artikels.
 * Eine Variante ohne eigene Bilder bekommt die ihres Hauptartikels, so wie Shopware sie auch im Shop zeigt.
 * Gibt es kein ausreichend großes Bild, bleibt es beim Titelbild: lieber ein Bild, das Google ablehnt und das
 * auf der Liste für neues Material steht, als ein hochgerechnetes, das im Shop unscharf wirkt.
 *
 * Bewusst ein Objekt im Kontext und keine Twig-Funktion: Der Produktexport rendert in einer eigenen
 * Umgebung, und `rcShipping` geht denselben Weg. `Struct`, weil der Kontext des Exports nur solche nimmt.
 */
final class FeedImageContextProvider extends Struct
{
    public function __construct(
        private readonly FeedImageService $feedImageService,
        private readonly Filesystem $publicFilesystem,
        private readonly bool $replacementEnabled,
    ) {
    }

    public function url(ProductEntity $product): ?string
    {
        $cover = $product->getCover()?->getMedia();
        $coverUrl = $cover?->getUrl() ?: null;

        if (!$this->replacementEnabled || ($cover !== null && self::meetsMinimum($cover))) {
            return $coverUrl;
        }

        $path = $this->feedImageService->resolveImageUrl($product->getId());
        if ($path === null && $product->getParentId() !== null) {
            $path = $this->feedImageService->resolveImageUrl($product->getParentId());
        }

        return $path !== null ? $this->publicFilesystem->publicUrl(self::encodePath($path)) : $coverUrl;
    }

    /**
     * Dateinamen in der Mediathek enthalten Leerzeichen und Kommas („Zeichnung_E 4191,122.jpg"); roh in die
     * Adresse geschrieben, wäre der Eintrag im Feed ungültig. Jeder Pfadteil wird deshalb einzeln kodiert, die
     * Schrägstriche bleiben.
     */
    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function meetsMinimum(MediaEntity $media): bool
    {
        $meta = $media->getMetaData() ?? [];
        $width = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);

        return $width >= FeedImageService::MINIMUM_EDGE && $height >= FeedImageService::MINIMUM_EDGE;
    }
}
