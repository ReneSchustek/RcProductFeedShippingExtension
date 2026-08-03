<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\RcProductFeedShippingExtension;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Die Deinstallation — der einzige Pfad im Plugin, der Daten löscht.
 *
 * Er hängt an einer einzigen Bedingung: der Entscheidung des Nutzers, seine Daten zu behalten.
 * Läse man sie falsch herum, verlöre ein Update mit zwischenzeitlicher Deinstallation den
 * gesamten Bestand — und der erste Feed danach nennte für jeden Artikel den Ersatzwert.
 */
class RcProductFeedShippingExtensionTest extends TestCase
{
    /**
     * Was: Deinstallation, bei der die Daten behalten werden sollen.
     * Warum: Das ist der Fall bei jedem Update, das intern deinstalliert und neu installiert.
     *        Ein Löschen hier wäre Datenverlust ohne Ansage.
     * Erwartet: Die Tabelle wird nicht angefasst.
     */
    public function testKeepingUserDataLeavesTheTableAlone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->uninstall(keepUserData: true, connection: $connection);
    }

    /**
     * Was: Deinstallation ohne Datenerhalt.
     * Warum: Dann soll die Tabelle weg — ihr Inhalt ist jederzeit neu berechenbar, und eine
     *        verwaiste Tabelle bliebe sonst für immer stehen.
     * Erwartet: genau ein DROP auf die Tabelle des Plugins.
     */
    public function testDiscardingUserDataDropsTheTable(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(self::stringContains(ShippingPriceStore::TABLE));

        $this->uninstall(keepUserData: false, connection: $connection);
    }

    /**
     * Was: Deinstallation ohne verfügbaren Datenbankdienst.
     * Warum: Beim Deinstallieren ist der Container nicht immer vollständig. Eine Ausnahme hier
     *        ließe das Plugin halb deinstalliert zurück — der unangenehmste aller Zustände.
     * Erwartet: kein Wurf.
     */
    public function testAMissingDatabaseServiceDoesNotBreakTheUninstall(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(null);

        $plugin = new RcProductFeedShippingExtension(true, __DIR__);
        $plugin->setContainer($container);

        $context = $this->createMock(UninstallContext::class);
        $context->method('keepUserData')->willReturn(false);

        $plugin->uninstall($context);

        $this->addToAssertionCount(1);
    }

    private function uninstall(bool $keepUserData, Connection $connection): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->with(Connection::class)->willReturn($connection);

        $plugin = new RcProductFeedShippingExtension(true, __DIR__);
        $plugin->setContainer($container);

        $context = $this->createMock(UninstallContext::class);
        $context->method('keepUserData')->willReturn($keepUserData);

        $plugin->uninstall($context);
    }
}
