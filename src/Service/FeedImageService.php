<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FeedImageContextProvider;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Findet für den Warenstrom das Produktbild, das Googles Mindestmaß erfüllt.
 *
 * Der Anlass
 * ----------
 * **Ab dem 31.01.2027 verlangt das Merchant Center für `image_link` mindestens 500 × 500 Pixel.**
 * Kleinere Bilder werden nicht mehr genehmigt, das Produkt erscheint nicht mehr.
 *
 * Was die Messung auf dem Datenbestand ergab (09.09.2026)
 * -------------------------------------------------------
 * | | |
 * |---|--:|
 * | aktive Produkte mit Titelbild | 2 094 |
 * | Titelbild kleiner als 500 × 500 | **413** |
 * | davon mit einem **anderen** Bild ≥ 500 × 500 | **313** |
 * | ohne jedes ausreichende Bild | **100** |
 *
 * **Vermutet war zuerst eine andere Ursache** — ein zu klein gewähltes Vorschaubild. Alle
 * drei Export-Vorlagen des Shops verlinken jedoch `product.cover.media.url`, also das **Original**.
 * Eine andere Vorschaufassung zu wählen hilft deshalb nicht; was hilft, ist ein **anderes Bild**
 * desselben Artikels. Die häufigsten zu kleinen Maße sind 400 × 400, 500 × 250, 500 × 333 und
 * 480 × 400 — knapp daneben, nicht winzig.
 *
 * Was dieser Dienst tut und was nicht
 * -----------------------------------
 * Er wählt das **erste Bild in der Reihenfolge des Artikels, das beide Kanten ≥ 500 px hat** und keine
 * Maßzeichnung ist, und bevorzugt dabei das Titelbild, wenn es die Vorgabe erfüllt. Gibt es kein solches Bild, liefert er
 * `null` — dann bleibt es beim Titelbild, und der Artikel gehört auf die Liste für neues Material.
 *
 * **Hochskaliert wird unter keinen Umständen.** Ein hochgerechnetes 400er-Bild bestünde Googles
 * Prüfung und stünde danach unscharf im Shop.
 */
class FeedImageService implements ResetInterface
{
    /** Googles Mindestmaß ab dem 31.01.2027 — beide Kanten, nicht die Fläche. */
    public const MINIMUM_EDGE = 500;

    /**
     * Maßzeichnungen sind kein Hauptbild für Google (Renes Entscheidung, 13.10.2026). Nach der Bildregel des
     * Hauses ist das zweite Bild eines Artikels meist das Maßbild; ohne diese Regel wären 306 der 327
     * Ersatzbilder Zeichnungen gewesen. Erkannt wird am Dateinamen, das Material heißt durchgängig
     * „Zeichnung_…". Ein ausreichend großes Titelbild bleibt immer, auch wenn es so heißt.
     */
    private const DRAWING_MARKER = 'zeichnung';

    /** @var array<string, string>|null Produkt-Id (hex) => Adresse des ersten ausreichend großen Bildes */
    private ?array $resolved = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Die Adresse eines ausreichend großen Bildes, oder `null`, wenn der Artikel keines hat.
     *
     * Beim ersten Aufruf wird der ganze Bestand in **einer** Abfrage geladen. Der Warenstrom rendert
     * mehrere tausend Artikel in einem Durchgang; eine Abfrage je Artikel wäre an dieser Stelle der
     * teuerste denkbare Weg.
     */
    public function resolveImageUrl(string $productId): ?string
    {
        return $this->all()[$productId] ?? null;
    }

    /**
     * Alle Artikel mit ihrem ersten ausreichend großen Bild.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(pm.product_id)) AS product_id,
                    m.path AS path,
                    m.file_name,
                    m.file_extension,
                    CAST(JSON_EXTRACT(m.meta_data, "$.width") AS UNSIGNED) AS width,
                    CAST(JSON_EXTRACT(m.meta_data, "$.height") AS UNSIGNED) AS height,
                    (p.product_media_id = pm.id) AS is_cover,
                    pm.position
             FROM product_media pm
             JOIN media m ON m.id = pm.media_id
             JOIN product p ON p.id = pm.product_id AND p.version_id = pm.version_id
             WHERE pm.version_id = :version
               AND m.meta_data IS NOT NULL
             ORDER BY pm.product_id, is_cover DESC, pm.position ASC',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        $resolved = [];

        foreach ($rows as $row) {
            $productId = (string) $row['product_id'];

            // Das erste ausreichend große Bild gewinnt — die Sortierung stellt das Titelbild
            // voran, danach gilt die Reihenfolge, die der Betreiber am Artikel gepflegt hat.
            if (isset($resolved[$productId])) {
                continue;
            }

            if ((int) $row['width'] < self::MINIMUM_EDGE || (int) $row['height'] < self::MINIMUM_EDGE) {
                continue;
            }

            if (!(bool) $row['is_cover'] && str_contains(mb_strtolower((string) ($row['file_name'] ?? '')), self::DRAWING_MARKER)) {
                continue;
            }

            $path = (string) ($row['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $resolved[$productId] = $path;
        }

        return $this->resolved = $resolved;
    }

    /**
     * Die drei Zahlen der Bestandsaufnahme — je Artikel eine Einordnung.
     *
     * @return array{sufficient: int, tooSmall: int, withReplacement: int, withoutReplacement: int, withoutImage: int}
     */
    public function audit(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS product_id,
                    LOWER(HEX(p.parent_id)) AS parent_id,
                    CAST(JSON_EXTRACT(m.meta_data, "$.width") AS UNSIGNED) AS width,
                    CAST(JSON_EXTRACT(m.meta_data, "$.height") AS UNSIGNED) AS height
             FROM product p
             LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             LEFT JOIN product_media pm
                    ON pm.id = COALESCE(p.product_media_id, parent.product_media_id) AND pm.version_id = p.version_id
             LEFT JOIN media m ON m.id = pm.media_id
             WHERE p.version_id = :version
               AND COALESCE(p.active, parent.active) = 1',
            ['version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        $replacement = $this->all();
        $counts = ['sufficient' => 0, 'tooSmall' => 0, 'withReplacement' => 0, 'withoutReplacement' => 0, 'withoutImage' => 0];

        foreach ($rows as $row) {
            $width = (int) $row['width'];
            $height = (int) $row['height'];

            if ($width === 0 || $height === 0) {
                ++$counts['withoutImage'];

                continue;
            }

            if ($width >= self::MINIMUM_EDGE && $height >= self::MINIMUM_EDGE) {
                ++$counts['sufficient'];

                continue;
            }

            ++$counts['tooSmall'];

            if (self::hasReplacement($replacement, $row)) {
                ++$counts['withReplacement'];

                continue;
            }

            ++$counts['withoutReplacement'];
        }

        return $counts;
    }

    /**
     * Die Artikel, für die es kein ausreichend großes Bild gibt — die Liste zum Abarbeiten.
     *
     * @return array<int, array{productNumber: string, name: string, width: int, height: int}>
     */
    public function productsNeedingNewImages(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(p.id)) AS product_id,
                    LOWER(HEX(p.parent_id)) AS parent_id,
                    p.product_number,
                    COALESCE(pt.name, parent_translation.name) AS name,
                    CAST(JSON_EXTRACT(m.meta_data, "$.width") AS UNSIGNED) AS width,
                    CAST(JSON_EXTRACT(m.meta_data, "$.height") AS UNSIGNED) AS height
             FROM product p
             LEFT JOIN product parent ON parent.id = p.parent_id AND parent.version_id = p.version_id
             LEFT JOIN product_media pm
                    ON pm.id = COALESCE(p.product_media_id, parent.product_media_id) AND pm.version_id = p.version_id
             LEFT JOIN media m ON m.id = pm.media_id
             LEFT JOIN product_translation pt
                    ON pt.product_id = p.id AND pt.product_version_id = p.version_id AND pt.language_id = :language
             LEFT JOIN product_translation parent_translation
                    ON parent_translation.product_id = parent.id AND parent_translation.product_version_id = parent.version_id
                   AND parent_translation.language_id = :language
             WHERE p.version_id = :version
               AND COALESCE(p.active, parent.active) = 1
               AND (
                   m.meta_data IS NULL
                   OR CAST(JSON_EXTRACT(m.meta_data, "$.width") AS UNSIGNED) < :minimumEdge
                   OR CAST(JSON_EXTRACT(m.meta_data, "$.height") AS UNSIGNED) < :minimumEdge
               )
             ORDER BY p.product_number',
            [
                'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'language' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                'minimumEdge' => self::MINIMUM_EDGE,
            ]
        );

        $replacement = $this->all();
        $list = [];

        foreach ($rows as $row) {
            if (self::hasReplacement($replacement, $row)) {
                continue;
            }

            $list[] = [
                'productNumber' => (string) ($row['product_number'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
            ];
        }

        return $list;
    }

    /**
     * Leert die Zwischenspeicher zwischen zwei Nachrichten eines langlebigen Workers (`kernel.reset`). Sonst rechnete der
     * nächste Warmup mit Versandarten, Ländern und Einstellungen aus dem Lauf davor.
     */
    public function reset(): void
    {
        $this->resolved = null;
    }

    /**
     * Ob es für den Artikel ein ausreichend großes Bild gibt, beim Artikel selbst oder, für eine Variante mit geerbten
     * Bildern, beim Hauptartikel. Genau so sucht der Feed ({@see FeedImageContextProvider::url()}).
     *
     * @param array<string, string> $replacement
     * @param array<string, mixed>  $row
     */
    private static function hasReplacement(array $replacement, array $row): bool
    {
        return isset($replacement[(string) $row['product_id']])
            || (\is_string($row['parent_id'] ?? null) && $row['parent_id'] !== '' && isset($replacement[$row['parent_id']]));
    }
}
