<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Shopware\Core\Framework\Struct\Struct;

/**
 * Wird einmalig in den Feed-Vorlagen-Kontext gelegt und liefert dort die Versandkosten je Produkt
 * und Land. Die Vorlage ruft `rcShipping.get(product.id, 'DE')`.
 *
 * **Dieser Provider rechnet nicht.** Er liest ausschließlich vorberechnete Werte.
 *
 * Warum: Shopware sperrt während des Twig-Renderns interne Entity-Felder. Der Schalter dafür
 * (`FieldVisibility::$isInTwigRenderingContext`) wird von `SwTwigFunction::getAttribute()` bei jedem
 * Attributzugriff auf ein `Struct` gesetzt — und dieser Provider ist ein `Struct`. Alles, was
 * innerhalb von `get()` passiert, läuft also unter dieser Sperre. Die Warenkorb-Regelauswertung
 * braucht mit `RuleEntity::payload` genau so ein gesperrtes Feld. Das Rechnen aus der Vorlage heraus
 * schlug deshalb bei **jedem** Produkt fehl und landete still im Ersatzwert: 6336 Ausnahmen je
 * Feed-Lauf, ohne dass am Feed etwas auffiel.
 *
 * Gefüllt wird der Speicher außerhalb von Twig — durch `rc:shipping:warmup` und die geplante
 * Aufgabe `rc_product_feed_shipping.warmup`.
 */
final class ShippingContextProvider extends Struct
{
    /** ISO-3166-1 alpha-2 (DE, AT, CH) oder alpha-3 (DEU, AUT, CHE). `D`-Flag verhindert, dass `$` einen Newline am Ende toleriert. */
    private const ISO_PATTERN = '/^[a-zA-Z]{2,3}$/D';
    private const LOG_CONTEXT = 'ruhrcoder_product_feed_shipping.context_provider';

    private int $match = 0;

    private int $missing = 0;

    private int $withoutShippingMethod = 0;

    private int $notPurchasable = 0;

    /** @var array<string, true> Länder, für die bereits gewarnt wurde */
    private array $warned = [];

    /**
     * @param array<int, string> $countries
     * @param bool               $omitCountryWithoutShippingMethod Wenn der Shop eine Ware nicht in dieses
     *                                                        Land versendet: keinen Preis nennen,
     *                                                        sondern den Block der Vorlage ausfallen
     *                                                        lassen.
     */
    public function __construct(
        private readonly ShippingCostCalculatorService $calculator,
        private readonly ShippingFallbackService $fallbackService,
        private readonly array $countries,
        private readonly string $salesChannelId,
        private readonly string $currencyIso,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly bool $omitCountryWithoutShippingMethod = false,
    ) {
    }

    /**
     * Gibt die Versandkosten für ein Produkt in das angegebene Land zurück.
     *
     * `null` bedeutet: Die Vorlage lässt den Block weg. Das passiert in drei Fällen — das Land ist
     * nicht konfiguriert, der ISO-Code hat ein ungültiges Format (etwa eine UUID durch einen
     * Vorlagen-Tippfehler), oder der Shop versendet diese Ware nicht in dieses Land und die
     * Einstellung verlangt Schweigen statt Ersatzwert.
     *
     * Sonst kommt immer ein Preis — der vorberechnete oder, wenn keiner vorliegt, der eingestellte
     * Ersatzwert. Letzteres wird gemeldet und nicht stillschweigend geliefert.
     */
    public function get(string $productId, string $countryIso): ?float
    {
        if (preg_match(self::ISO_PATTERN, $countryIso) !== 1) {
            // Schutz gegen Vorlagen-Tippfehler wie rcShipping.get(product.id, product.manufacturerId).
            // Ohne Prüfung lieferte der Provider stillschweigend null und das Feed-Element bliebe leer.
            $this->logger->warning('RcProductFeedShipping: ungültiges ISO-Code-Format ignoriert.', [
                'context' => self::LOG_CONTEXT,
                'countryIso' => substr($countryIso, 0, 36),
                'metric' => 'invalid_iso_code',
            ]);

            return null;
        }

        $country = strtoupper($countryIso);

        if (!\in_array($country, $this->countries, true)) {
            return null;
        }

        $precomputed = $this->calculator->lookupCached($productId, $country, $this->salesChannelId);

        if ($precomputed !== null) {
            // „Nicht bestellbar" heißt: Für diesen Artikel gibt es keinen Versandpreis, weil er
            // sich nicht in einen Warenkorb legen lässt — bei Trummer sind das die Hauptartikel
            // mit Varianten. Jede Zahl, die hier stünde, wäre erfunden, und der Ersatzwert von
            // 4,95 Euro wäre bei einem Artikel mit 238 Euro Versand die schlechteste davon.
            //
            // Anders als das Weglassen bei „keine Versandart" hängt das an **keiner** Einstellung:
            // Dort entscheidet der Betreiber, ob Schweigen oder Ersatzwert die bessere Aussage
            // über sein Sortiment ist. Hier gibt es nichts zu entscheiden — wir wissen es nicht.
            if ($precomputed->fallbackReason === FallbackReason::NotPurchasable) {
                ++$this->notPurchasable;

                return null;
            }

            if ($this->shouldOmit($precomputed)) {
                ++$this->withoutShippingMethod;

                return null;
            }

            ++$this->match;

            return $precomputed->shippingCost;
        }

        return $this->fallbackFor($productId, $country);
    }

    /** @return array<int, string> Konfigurierte Länder (für Vorlagen-Schleifen) */
    public function getCountries(): array
    {
        return $this->countries;
    }

    /**
     * Fasst am Ende eines Feed-Laufs zusammen, wie viele Produkte den Ersatzwert bekommen haben.
     *
     * Die Einzelmeldungen in `fallbackFor()` sind je Land auf eine begrenzt, damit ein kalter
     * Speicher nicht tausende gleichlautende Zeilen erzeugt. Ohne diese Zusammenfassung bliebe
     * unklar, ob drei Produkte betroffen sind oder alle.
     */
    public function logSummary(): void
    {
        if ($this->missing === 0 && $this->withoutShippingMethod === 0 && $this->notPurchasable === 0) {
            return;
        }

        $this->logger->warning('RcProductFeedShipping: Feed mit Ersatzwerten ausgeliefert.', [
            'context' => self::LOG_CONTEXT,
            'precomputed' => $this->match,
            // Die Zahlen bleiben getrennt: `fallback` ist ein Betriebszustand (Speicher kalt,
            // Warmup prüfen), `withoutShippingMethod` ein Ergebnis (der Shop versendet dorthin
            // nicht), `notPurchasable` eine Eigenschaft des Artikels (Hauptartikel mit Varianten).
            // Eine gemeinsame Zahl verschleiert alle drei.
            'fallback' => $this->missing,
            'withoutShippingMethod' => $this->withoutShippingMethod,
            'notPurchasable' => $this->notPurchasable,
            'calculationChannel' => substr($this->salesChannelId, 0, 8),
            'hint' => 'rc:shipping:warmup ausführen oder die geplante Aufgabe rc_product_feed_shipping.warmup prüfen.',
            'metric' => 'feed_fallback_summary',
        ]);
    }

    /**
     * Entscheidet, ob der Feed für dieses Ergebnis lieber nichts sagt.
     *
     * Nur „keine Versandart" ist eine Aussage über das Sortiment. Ein kalter Speicher und eine
     * fehlgeschlagene Berechnung sind Betriebszustände — sie als „wird dorthin nicht versendet"
     * auszugeben, wäre eine Falschaussage in die andere Richtung.
     */
    private function shouldOmit(ShippingCalculationResult $result): bool
    {
        return $this->omitCountryWithoutShippingMethod
            && $result->isFallback
            && $result->fallbackReason === FallbackReason::NoShippingMethod;
    }

    private function fallbackFor(string $productId, string $country): float
    {
        ++$this->missing;

        if (!isset($this->warned[$country])) {
            $this->warned[$country] = true;

            $this->logger->warning('RcProductFeedShipping: kein vorberechneter Versandpreis, Ersatzwert wird ausgeliefert.', [
                'context' => self::LOG_CONTEXT,
                'productId' => substr($productId, 0, 8),
                'countryIso' => $country,
                // Der Berechnungs-Kanal gehört in die Meldung: Der Speicher ist je Kanal
                // geschlüsselt. Wärmt jemand einen anderen Kanal vor als der Feed liest, ist
                // alles einzeln richtig und zusammen wirkungslos -- ohne diese Angabe sucht man lange.
                'calculationChannel' => substr($this->salesChannelId, 0, 8),
                'hint' => 'rc:shipping:warmup ausführen oder die geplante Aufgabe rc_product_feed_shipping.warmup prüfen.',
                'metric' => 'feed_fallback_used',
            ]);
        }

        return $this->fallbackService
            ->getFallbackResult($productId, $country, $this->salesChannelId, $this->currencyIso, FallbackReason::NotPrecomputed)
            ->shippingCost;
    }
}
