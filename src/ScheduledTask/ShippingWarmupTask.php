<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask;

use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Hält den Versandkosten-Speicher warm.
 *
 * Ohne diese Aufgabe ist das Plugin wirkungslos: Der Feed rechnet nicht mehr selbst (er kann es
 * nicht, siehe `ShippingContextProvider`), sondern liest nur vorberechnete Werte. Läuft niemand
 * vor, liefert der Feed den Ersatzwert.
 *
 * Der Abstand ist bewusst deutlich kürzer als die Haltbarkeit der Einträge
 * (`ShippingPriceStore::MAX_AGE_SECONDS`, 24 Stunden). Bei gleichem Abstand entschiede die Reihenfolge
 * innerhalb einer Minute darüber, ob der Feed noch Werte findet — und ein einziger ausgefallener
 * Lauf risse ein Loch, das erst der übernächste wieder schließt.
 *
 * Dieser Abstand wirkt allerdings nur, weil der Warmup **jeden** Eintrag neu rechnet. Solange er
 * den Speicher-Kurzschluss von `calculate()` mitbenutzte, übersprang er alles noch Gültige und
 * erneuerte nichts: Der Lauf nach 24 Stunden traf Einträge, die knapp jünger waren, ließ sie
 * stehen, und Minuten später verfielen sie. Der Faktor 4 stand im Code und wirkte nicht.
 */
class ShippingWarmupTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'rc_product_feed_shipping.warmup';
    }

    public static function getDefaultInterval(): int
    {
        return (int) (ShippingPriceStore::MAX_AGE_SECONDS / 4);
    }
}
