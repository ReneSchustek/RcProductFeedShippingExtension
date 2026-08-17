<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Tabelle der vorberechneten Versandkosten je Produkt, Land und Verkaufskanal.
 *
 * Vorher lagen diese Werte in `cache.object` und verschwanden bei jedem `cache:clear` --
 * der Feed nannte danach bis zum nächsten Warmup für jeden Artikel den Ersatzwert. Die
 * Begründung steht ausführlich in `Storage\ShippingPriceStore`.
 *
 * Forward-only -- nach Veröffentlichung nicht mehr verändern.
 */
final class Migration1785715200CreateShippingPriceTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1785715200;
    }

    public function update(Connection $connection): void
    {
        // Zusammengesetzter Primärschlüssel statt einer künstlichen `id`: Die Tabelle wird
        // ausschließlich über genau diese drei Spalten gelesen und geschrieben. Eine
        // zusätzliche Spalte brächte einen zweiten Index und keinen einzigen Zugriff.
        //
        // Kein Fremdschlüssel auf `product`: Der Warmup schreibt für 2112 Produkte, und ein
        // gelöschtes Produkt soll den Bestand nicht mit einer Ausnahme reißen. Verwaiste
        // Zeilen laufen über das Höchstalter aus und werden vom nächsten Warmup nicht mehr
        // erneuert.
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `rc_product_feed_shipping_price` (
                `product_id`        BINARY(16)      NOT NULL,
                `country_iso`       VARCHAR(3)      NOT NULL,
                `sales_channel_id`  BINARY(16)      NOT NULL,
                `shipping_cost`     DOUBLE          NOT NULL,
                `currency_iso`      VARCHAR(3)      NOT NULL,
                `is_fallback`       TINYINT(1)      NOT NULL DEFAULT 0,
                `fallback_reason`   VARCHAR(32)     NULL,
                `calculated_at`     DATETIME(3)     NOT NULL,
                PRIMARY KEY (`product_id`, `country_iso`, `sales_channel_id`),
                KEY `idx.rc_shipping_price.calculated_at` (`calculated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nichts abzureißen. Die Tabelle wird bei der Deinstallation entfernt, und zwar
        // nur dann, wenn der Nutzer seine Daten nicht behalten will -- siehe Plugin-Klasse.
    }
}
