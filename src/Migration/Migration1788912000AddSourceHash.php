<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ergänzt den Prüfwert, mit dem der Warmup erkennt, ob sich überhaupt etwas geändert hat.
 *
 * Ohne ihn rechnete jeder Lauf jede Kombination neu — auf dem produktiven Shop 21 172 Berechnungen
 * an einem Tag, bei einer Bestellung. Die Begründung steht in `Service\ShippingFingerprintService`.
 *
 * `NULL` ist der richtige Vorgabewert: Bestehende Zeilen tragen keinen Prüfwert und werden beim
 * ersten Lauf nach der Umstellung neu gerechnet. Ein erfundener Wert würde sie als „unverändert"
 * ausgeben, obwohl niemand sie je verglichen hat.
 *
 * Forward-only — nach Veröffentlichung nicht mehr verändern.
 */
final class Migration1788912000AddSourceHash extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1788912000;
    }

    public function update(Connection $connection): void
    {
        $spalten = $connection->fetchAllAssociative(
            'SHOW COLUMNS FROM `rc_product_feed_shipping_price` LIKE "source_hash"'
        );

        if ($spalten !== []) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `rc_product_feed_shipping_price`
                ADD COLUMN `source_hash` VARCHAR(40) NULL AFTER `fallback_reason`'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nichts abzureißen.
    }
}
