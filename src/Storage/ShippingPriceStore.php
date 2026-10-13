<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Storage;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Speicher der vorberechneten Versandkosten.
 *
 * Warum eine eigene Tabelle und kein Cache
 * ----------------------------------------
 * Bis zum 2026-08-03 lagen diese Werte in `cache.object`. Das sah naheliegend aus -- sie
 * werden ja errechnet und haben eine Haltbarkeit. Es war trotzdem der falsche Ort: Ein
 * `cache:clear` leerte sie mit, und der Feed nannte danach bis zum nächsten Warmup für
 * **jeden** Artikel den Ersatzwert von 4,95 Euro. Bei einem Warmup-Takt von sechs Stunden
 * also bis zu sechs Stunden lang -- auch für Ware, deren Versand über 200 Euro kostet. Zu
 * niedrig angegebene Versandkosten sind bei Google ein Richtlinienverstoß.
 *
 * `cache:clear` gehört zu jedem Plugin-Update, jeder Theme-Änderung und jedem Deployment.
 * Der Zustand trat damit planmäßig ein, nicht ausnahmsweise.
 *
 * Ein Cache darf jederzeit leer sein, ohne dass etwas Falsches herauskommt. Genau das war
 * hier nicht der Fall -- also sind es keine Cache-Einträge, sondern vorberechnete Stammdaten,
 * und die gehören in eine Tabelle.
 *
 * Was der Wechsel nebenbei erledigt hat
 * -------------------------------------
 * Der Vorgänger speicherte ein serialisiertes `ShippingCalculationResult` und führte deshalb
 * eine Schema-Version im Schlüssel mit: Kam dem Struct ein Feld hinzu, war es in den alten
 * Daten nicht enthalten, und der erste Zugriff auf die getypte Eigenschaft war ein Fehler.
 * Mit ausgeschriebenen Spalten stellt sich die Frage nicht mehr.
 */
class ShippingPriceStore
{
    public const TABLE = 'rc_product_feed_shipping_price';

    /**
     * Höchstalter eines Eintrags in Sekunden (24 Stunden).
     *
     * Eine Änderung an einer Versandart räumt den Bestand über den vorhandenen Subscriber ab.
     * Gewicht und Maße eines Produkts bestimmen die Versandkosten aber ebenfalls, und deren
     * Änderung merkt niemand. Das Alter ist die Rückfalllinie dagegen: Bei einem Warmup-Takt
     * von sechs Stunden hat der Bestand vier Versuche, bevor ein Eintrag verfällt.
     */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Liefert den vorberechneten Preis oder null, wenn keiner vorliegt.
     *
     * Null heißt „wir wissen nichts" und ist ausdrücklich nicht dasselbe wie ein Preis von
     * 0,00 Euro -- den gibt es als echtes Ergebnis (Versandkostenfreiheit ab einem Warenwert).
     * Der Aufrufer muss beides auseinanderhalten können.
     */
    public function get(string $productId, string $countryIso, string $salesChannelId): ?ShippingCalculationResult
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT shipping_cost, currency_iso, is_fallback, fallback_reason
                 FROM ' . self::TABLE . '
                 WHERE product_id = :productId
                   AND country_iso = :countryIso
                   AND sales_channel_id = :salesChannelId
                   AND calculated_at >= DATE_SUB(NOW(), INTERVAL :maxAge SECOND)',
                [
                    'productId' => Uuid::fromHexToBytes($productId),
                    'countryIso' => strtoupper($countryIso),
                    'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                    'maxAge' => self::MAX_AGE_SECONDS,
                ]
            );

            if ($row === false) {
                return null;
            }

            return new ShippingCalculationResult(
                $productId,
                strtoupper($countryIso),
                (float) $row['shipping_cost'],
                (string) $row['currency_iso'],
                (bool) $row['is_fallback'],
                $this->toFallbackReason($row['fallback_reason']),
            );
        } catch (\Throwable $error) {
            // Ein Lesefehler darf den Feed nicht reißen. Der Aufrufer behandelt null als
            // „nicht vorberechnet" und trägt den Ersatzwert ein -- mit eigener Warnung.
            $this->logger->warning('Vorberechneten Versandpreis konnte nicht gelesen werden.', [
                'error' => $error->getMessage(),
                'productId' => substr($productId, 0, 8),
                'countryIso' => $countryIso,
            ]);

            return null;
        }
    }

    /**
     * Schreibt einen berechneten Preis und ersetzt einen vorhandenen Eintrag.
     *
     * `ON DUPLICATE KEY UPDATE` statt Löschen-und-Einfügen: Der Warmup schreibt bei jedem Lauf
     * über den gesamten Bestand, und zwei Anweisungen je Kombination wären bei 8217
     * Kombinationen 8217 Anweisungen zu viel.
     */
    public function set(
        string $productId,
        string $countryIso,
        string $salesChannelId,
        ShippingCalculationResult $result,
        ?string $sourceHash = null,
    ): void {
        try {
            $this->connection->executeStatement(
                'INSERT INTO ' . self::TABLE . '
                    (product_id, country_iso, sales_channel_id, shipping_cost, currency_iso,
                     is_fallback, fallback_reason, source_hash, calculated_at)
                 VALUES (:productId, :countryIso, :salesChannelId, :shippingCost, :currencyIso,
                         :isFallback, :fallbackReason, :sourceHash, NOW())
                 ON DUPLICATE KEY UPDATE
                    shipping_cost = VALUES(shipping_cost),
                    currency_iso = VALUES(currency_iso),
                    is_fallback = VALUES(is_fallback),
                    fallback_reason = VALUES(fallback_reason),
                    source_hash = VALUES(source_hash),
                    calculated_at = VALUES(calculated_at)',
                [
                    'productId' => Uuid::fromHexToBytes($productId),
                    'countryIso' => strtoupper($countryIso),
                    'salesChannelId' => Uuid::fromHexToBytes($salesChannelId),
                    'shippingCost' => $result->shippingCost,
                    'currencyIso' => $result->currencyIso,
                    'isFallback' => $result->isFallback ? 1 : 0,
                    'fallbackReason' => $result->fallbackReason?->value,
                    'sourceHash' => $sourceHash,
                ]
            );
        } catch (\Throwable $error) {
            // Ein Schreibfehler kostet Geschwindigkeit, nicht Richtigkeit: Der nächste Warmup
            // versucht es erneut, und bis dahin trägt der Feed den Ersatzwert mit Warnung.
            $this->logger->warning('Vorberechneten Versandpreis konnte nicht geschrieben werden.', [
                'error' => $error->getMessage(),
                'productId' => substr($productId, 0, 8),
                'countryIso' => $countryIso,
            ]);
        }
    }

    /**
     * Liest für einen Kanal, welcher Prüfwert je Produkt und Land gespeichert ist und wie alt er ist.
     *
     * In **einer** Abfrage für den ganzen Kanal, nicht je Kombination: Der Warmup braucht den Stand
     * aller mehreren tausend Kombinationen, bevor er die erste berechnet. Tausend Einzelabfragen
     * wären genau die Last, die dieser Weg vermeiden soll.
     *
     * @return array<string, array{hash: ?string, age: int}> Schlüssel „<produktId>|<LAND>", Alter in Sekunden
     */
    public function loadState(string $salesChannelId): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT LOWER(HEX(product_id)) AS product_id,
                        country_iso,
                        source_hash,
                        TIMESTAMPDIFF(SECOND, calculated_at, NOW()) AS age
                 FROM ' . self::TABLE . '
                 WHERE sales_channel_id = :salesChannelId',
                ['salesChannelId' => Uuid::fromHexToBytes($salesChannelId)]
            );
        } catch (\Throwable $error) {
            // Ohne Stand wird alles neu gerechnet — langsam, aber richtig.
            $this->logger->warning('Stand der vorberechneten Versandpreise nicht lesbar.', [
                'error' => $error->getMessage(),
            ]);

            return [];
        }

        $state = [];

        foreach ($rows as $row) {
            $key = (string) $row['product_id'] . '|' . strtoupper((string) $row['country_iso']);
            $state[$key] = [
                'hash' => $row['source_hash'] !== null ? (string) $row['source_hash'] : null,
                'age' => (int) $row['age'],
            ];
        }

        return $state;
    }

    /**
     * Frischt den Zeitstempel unveränderter Einträge auf, ohne sie neu zu berechnen.
     *
     * Notwendig, weil ein Eintrag nach {@see self::MAX_AGE_SECONDS} verfällt und der Feed danach den
     * Ersatzwert nennen würde. **Übersprungen heißt nicht ungültig** — es heißt „nachgesehen, hat
     * sich nicht geändert".
     *
     * Ein Aufruf je Stapel statt je Eintrag: Bei mehreren tausend unveränderten Kombinationen ist
     * der Unterschied zwischen einer Anweisung und tausend genau der Punkt der Übung.
     *
     * @param array<int, array{productId: string, countryIso: string}> $entries
     */
    public function touch(array $entries, string $salesChannelId): int
    {
        if ($entries === []) {
            return 0;
        }

        $refreshed = 0;

        foreach (array_chunk($entries, 500) as $chunk) {
            $conditions = [];
            $parameters = ['salesChannelId' => Uuid::fromHexToBytes($salesChannelId)];

            foreach ($chunk as $i => $entry) {
                $conditions[] = '(product_id = :p' . $i . ' AND country_iso = :c' . $i . ')';
                $parameters['p' . $i] = Uuid::fromHexToBytes($entry['productId']);
                $parameters['c' . $i] = strtoupper($entry['countryIso']);
            }

            try {
                $refreshed += (int) $this->connection->executeStatement(
                    'UPDATE ' . self::TABLE . '
                        SET calculated_at = NOW()
                      WHERE sales_channel_id = :salesChannelId
                        AND (' . implode(' OR ', $conditions) . ')',
                    $parameters
                );
            } catch (\Throwable $error) {
                $this->logger->warning('Vorberechnete Versandpreise nicht aufgefrischt.', [
                    'error' => $error->getMessage(),
                    'count' => count($chunk),
                ]);
            }
        }

        return $refreshed;
    }

    /** Räumt den gesamten Bestand ab -- aufgerufen, wenn sich eine Versandart geändert hat. */
    public function invalidateAll(): void
    {
        try {
            $this->connection->executeStatement('DELETE FROM ' . self::TABLE);
        } catch (\Throwable $error) {
            $this->logger->warning('Vorberechnete Versandpreise konnten nicht abgeräumt werden.', [
                'error' => $error->getMessage(),
            ]);
        }
    }

    private function toFallbackReason(mixed $raw): ?FallbackReason
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        // Ein unbekannter Wert kann nur aus einer älteren oder neueren Fassung stammen. Der
        // Preis daneben bleibt gültig; nur die Begründung geht verloren.
        return FallbackReason::tryFrom($raw);
    }
}
