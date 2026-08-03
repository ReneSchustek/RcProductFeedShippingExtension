<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension;

use Doctrine\DBAL\Connection;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

final class RcProductFeedShippingExtension extends Plugin
{
    /**
     * Räumt die Tabelle der vorberechneten Versandkosten ab.
     *
     * Nur, wenn der Nutzer seine Daten nicht behalten will -- `keepUserData()` ist die
     * Entscheidung, die er im Verwaltungsbereich beim Deinstallieren trifft. Ohne diese
     * Prüfung verlöre ein Update mit zwischenzeitlicher Deinstallation den ganzen Bestand,
     * und der erste Feed danach nennte für jeden Artikel den Ersatzwert.
     *
     * Der Inhalt ist jederzeit neu berechenbar (`rc:shipping:warmup`) -- das Löschen ist
     * deshalb unkritisch, sobald es gewollt ist.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container?->get(Connection::class);
        if (!$connection instanceof Connection) {
            return;
        }

        $connection->executeStatement('DROP TABLE IF EXISTS `' . ShippingPriceStore::TABLE . '`');
    }
}
