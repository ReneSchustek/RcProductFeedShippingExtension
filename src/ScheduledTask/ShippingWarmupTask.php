<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\ScheduledTask;

use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Hält den Versandkosten-Speicher warm.
 *
 * Ohne diese Aufgabe ist das Plugin wirkungslos: Der Feed rechnet nicht selbst (er kann es
 * nicht, siehe `ShippingContextProvider`), sondern liest nur vorberechnete Werte. Läuft niemand
 * vor, liefert der Feed den Ersatzwert.
 *
 * Der Abstand ist bewusst deutlich kürzer als die Haltbarkeit der Einträge
 * (`ShippingPriceStore::MAX_AGE_SECONDS`, 24 Stunden). Bei gleichem Abstand entschiede die Reihenfolge
 * innerhalb einer Minute darüber, ob der Feed noch Werte findet, und ein einziger ausgefallener
 * Lauf risse ein Loch, das erst der übernächste wieder schließt.
 *
 * Dieser Abstand wirkt nur, weil jeder Lauf jeden Eintrag erneuert: Geänderte rechnet er neu,
 * unveränderte frischt er auf (`touch()`). Bliebe ein gültiger Eintrag liegen, verfiele er zwischen
 * zwei Läufen, und der Feed lieferte bis zum nächsten den Ersatzwert.
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
