<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Bildet je Produkt einen Prüfwert über alles, was die Versandkosten bestimmt.
 *
 * Warum es ihn gibt
 * -----------------
 * Der Warmup rechnete bisher bei jedem Lauf jede Kombination aus Produkt und Land neu — dreimal am
 * Tag dasselbe Ergebnis. Gemessen auf dem produktiven Shop: 21 172 Berechnungen an einem Tag, bei
 * einer einzigen Bestellung. **Google verlangt korrekte Werte im Feed, keine ständige
 * Neuberechnung.** Ist der Prüfwert unverändert, liefert eine Neuberechnung dasselbe Ergebnis.
 *
 * Was in den Prüfwert eingeht
 * ---------------------------
 * Gewicht und Abmessungen sind die offensichtlichen Größen. **Der Preis ist die übersehene:** Wo es
 * „versandkostenfrei ab X" gibt, ändert eine Preisänderung die Versandkosten, obwohl das Gewicht
 * gleich blieb. Dazu Steuersatz, das Kennzeichen für Versandkostenfreiheit und das Aktiv-Merkmal.
 *
 * Varianten erben ihre Werte vom Elternartikel — steht dort `NULL`, gilt der Wert des Elternteils.
 * Ein Prüfwert, der das übergeht, hielte jede Variante für unverändert, sobald ihr eigenes Feld
 * leer ist.
 *
 * Die Versandkonfiguration steckt mit drin
 * ----------------------------------------
 * Ändert sich eine Versandart, ihre Preismatrix oder eine Regel, ist der Prüfwert **aller** Produkte
 * hinfällig. Statt eines Sonderwegs geht der Stand der Konfiguration als Stempel in jeden einzelnen
 * Prüfwert ein: Ändert er sich, ändern sich alle Prüfwerte, und der nächste Lauf rechnet alles neu.
 *
 * **In den Stempel gehört auch die Anzahl der Zeilen.** Ein `MAX(updated_at)` allein merkt kein
 * Löschen — wer eine Versandart entfernt, hinterlässt keinen neueren Zeitstempel, wohl aber eine
 * Zeile weniger.
 *
 * Warum `rule` nur gezählt und nicht datiert wird
 * -----------------------------------------------
 * **Gemessen am 09.09.2026 auf der Testkopie:** Der erste Lauf nach der Umstellung rechnete alles neu,
 * der zweite ebenfalls — erst der dritte übersprang. Ursache war der Stempel selbst: Shopware
 * kompiliert beim Berechnen eines Warenkorbs die Regel-Nutzlast und schreibt dabei `rule.updated_at`.
 * **Der Lauf verschob damit seinen eigenen Prüfwert**, und kein Eintrag konnte je unverändert sein.
 *
 * Von `rule` geht deshalb nur die **Anzahl** in den Stempel ein — sie merkt eine hinzugefügte oder
 * gelöschte Regel. Eine inhaltliche Änderung fängt `rule_condition` ab: Dort stehen die Bedingungen
 * selbst, und diese Tabelle schreibt niemand nebenbei.
 *
 * Auch der Ausschluss gehört in den Stempel
 * -----------------------------------------
 * Welche Versandarten der Feed nie nehmen darf, steht in der Plugin-Konfiguration, nicht in den
 * Versandtabellen. Ohne diese Werte im Stempel blieb eine geänderte Auswahl wirkungslos, bis ein
 * Lauf mit `--force` alles neu rechnete: Die Abholung für 0,00 € stand weiter im Speicher, obwohl
 * sie längst ausgeschlossen war. Eingelesen werden die Werte aller Kanäle; ändert sich einer,
 * rechnet der nächste Lauf alles neu — selten genug, dass die Unschärfe nicht zählt.
 */
class ShippingFingerprintService
{
    /**
     * Tabellen, deren Stand die Versandkosten aller Produkte bestimmt.
     *
     * Der Wert sagt, ob der Zeitstempel mitzählt. Bei `rule` tut er das **nicht** — Shopware
     * schreibt ihn beim Berechnen selbst; die Begründung steht im Klassenkommentar.
     */
    private const CONFIGURATION_SOURCES = [
        'shipping_method' => true,
        'shipping_method_price' => true,
        'rule_condition' => true,
        'rule' => false,
    ];

    /** Einstellungen, die bestimmen, welche Versandarten überhaupt in Frage kommen. */
    private const CONFIGURATION_KEYS = [
        'RcProductFeedShippingExtension.config.excludedShippingMethodIds',
        'RcProductFeedShippingExtension.config.excludedShippingMethods',
    ];

    /** Produkte je Abfrage — eine `IN`-Liste mit mehreren tausend Werten will niemand. */
    private const CHUNK_SIZE = 500;

    private ?string $configurationStamp = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Der Stand der Versandkonfiguration, einmal je Lauf ermittelt.
     *
     * Wird zwischengespeichert: Innerhalb eines Warmup-Laufs ändert sie sich nicht, und die Abfrage
     * für jedes der mehreren tausend Produkte zu wiederholen wäre genau die Art von Aufwand, die
     * dieser Dienst beseitigen soll.
     */
    public function configurationStamp(): string
    {
        if ($this->configurationStamp !== null) {
            return $this->configurationStamp;
        }

        $parts = [];

        foreach (self::CONFIGURATION_SOURCES as $table => $withTimestamp) {
            $columns = $withTimestamp
                ? 'COUNT(*) AS count, MAX(COALESCE(`updated_at`, `created_at`)) AS latest'
                : 'COUNT(*) AS count, "-" AS latest';

            $row = $this->connection->fetchAssociative('SELECT ' . $columns . ' FROM `' . $table . '`');

            $parts[] = $table . ':' . (string) ($row['count'] ?? '0') . ':' . (string) ($row['latest'] ?? '-');
        }

        $settings = $this->connection->fetchAllNumeric(
            'SELECT `configuration_key`, COALESCE(LOWER(HEX(`sales_channel_id`)), "-"), `configuration_value`
               FROM `system_config`
              WHERE `configuration_key` IN (:keys)
              ORDER BY 1, 2',
            ['keys' => self::CONFIGURATION_KEYS],
            ['keys' => ArrayParameterType::STRING],
        );

        foreach ($settings as $setting) {
            $parts[] = implode(':', array_map(static fn (mixed $value): string => (string) $value, $setting));
        }

        return $this->configurationStamp = sha1(implode('|', $parts));
    }

    /**
     * Liefert je Produkt-Id den Prüfwert — in einer Abfrage je Stapel, nicht je Produkt.
     *
     * @param array<int, string> $productIds
     *
     * @return array<string, string> Produkt-Id (hex) => Prüfwert
     */
    public function loadFingerprints(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $stamp = $this->configurationStamp();
        $fingerprints = [];

        foreach (array_chunk($productIds, self::CHUNK_SIZE) as $chunk) {
            $bytes = array_map(static fn (string $id): string => Uuid::fromHexToBytes($id), $chunk);

            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(p.id)) AS id,
                        CONCAT_WS("|",
                            COALESCE(p.weight, parent.weight, ""),
                            COALESCE(p.width, parent.width, ""),
                            COALESCE(p.height, parent.height, ""),
                            COALESCE(p.length, parent.length, ""),
                            COALESCE(p.price, parent.price, ""),
                            LOWER(HEX(COALESCE(p.tax_id, parent.tax_id))),
                            COALESCE(p.shipping_free, parent.shipping_free, 0),
                            COALESCE(p.active, parent.active, 0)
                        ) AS source
                 FROM `product` p
                 LEFT JOIN `product` parent
                        ON parent.id = p.parent_id
                       AND parent.version_id = p.version_id
                 WHERE p.id IN (:ids)
                   AND p.version_id = :version',
                [
                    'ids' => $bytes,
                    'version' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                ],
                [
                    'ids' => ArrayParameterType::BINARY,
                ]
            );

            foreach ($rows as $row) {
                $fingerprints[(string) $row['id']] = sha1($stamp . '|' . (string) $row['source']);
            }
        }

        return $fingerprints;
    }
}
