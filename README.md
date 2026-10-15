# RcProductFeedShippingExtension

Shopware-6-Plugin, das Versandkosten je Produkt und Land berechnet und sie im Produktfeed im Format von Google Shopping bereitstellt.

---

## Was das Plugin macht

Google Shopping erwartet im Feed für jedes Land einen eigenen `g:shipping`-Block. Feste Preise dort einzutragen macht Arbeit, und spätestens wenn sich Versandzonen oder Preise ändern, stimmen sie nicht mehr.

Das Plugin geht deshalb einen anderen Weg. Für jedes Produkt im Feed baut es einen virtuellen Warenkorb und lässt Shopwares eigene Versandkostenberechnung darüber laufen. Die Regeln des Shops (Versandzonen, Gewichtsgrenzen, Produkteigenschaften) werden so tatsächlich ausgewertet und nicht nachgebaut. Die Ergebnisse liegen vorberechnet in einer eigenen Tabelle. Wiederholte Exporte bleiben dadurch schnell, und ein geleerter Cache nimmt die Werte nicht mit.

---

## Voraussetzungen

- Shopware 6.7 oder 6.8
- PHP 8.2+

---

## Installation

```bash
# Plugin ins Verzeichnis custom/plugins kopieren, dann im Shopware-Root:
php bin/console plugin:refresh
php bin/console plugin:install --activate RcProductFeedShippingExtension
php bin/console cache:clear
php bin/console rc:shipping:warmup
```

---

## Konfiguration

Im Admin unter **Einstellungen → Plugins → Produktfeed Versandkostenerweiterung**.

Alle Einstellungen lassen sich global oder pro Verkaufskanal setzen.

| Feld | Beschreibung | Beispiel |
|---|---|---|
| Plugin aktivieren | An/Aus pro Verkaufskanal | |
| Verkaufskanal für Versandberechnung | UUID eines Storefront-Kanals; nötig wenn der Feed über einen Produktvergleichs-Kanal läuft | `abc123...` |
| Versandländer | ISO-Codes, kommasepariert | `DE,AT,CH` |
| Fallback-Versandkosten | Preis wenn Berechnung fehlschlägt | `4.95` |
| Fallback pro Land | Länderspezifische Fallbacks | `DE:4.95,AT:9.90,CH:14.90` |
| Wenn für ein Land keine Versandart greift | Ersatzwert eintragen (Standard) oder Land weglassen | |
| Ausgeschlossene Versandarten (Auswahl) | Versandarten aus der Liste wählen; maßgeblich, wenn gesetzt | |
| Ausgeschlossene Versandarten (Wörter) | Rückfall: Teile des Namens, kommasepariert | `Selbstabholung,Pickup` |
| Größeres Bild verwenden | Ist das Titelbild kleiner als 500 × 500, verlinkt der Feed das erste ausreichend große Bild desselben Artikels | |

**Unterstützte Länder:** DE, AT, CH, BE, BG, CY, CZ, DK, EE, ES, FI, FR, GR, HR, HU, IE, IT, LT, LU, LV, MT, NL, PL, PT, RO, SE, SI, SK, GB, NO, IS, LI, US, CA, AU

**Hinweis zu ausgeschlossenen Versandarten:** Das Plugin wählt immer die günstigste Versandart. Ohne Ausschluss würde Selbstabholung (0,00 €) gewinnen und Google würde den Feed ablehnen. Am sichersten ist die Auswahl der Versandarten aus der Liste; sie hängt an der Kennung und überlebt jede Umbenennung. Die Wörter sind der Rückfall, Standard sind `Selbstabholung`, `Abholung` und `Pickup`.

**Hinweis zu Produktvergleichs-Kanälen:** Google Shopping läuft in Shopware üblicherweise als eigener Verkaufskanal vom Typ "Produktvergleich". Dieser Kanaltyp hat keine eigenen Versandmethoden. In dem Fall muss hier die UUID eines Storefront-Kanals eingetragen werden, der die gewünschten Versandmethoden enthält.

---

## Feed-Template

Das Plugin stellt die berechneten Versandkosten über eine Twig-Variable `rcShipping` zur Verfügung. Der Zugriff erfolgt mit `rcShipping.get(product.id, 'DE')`.

Das Bild für `image_link` liefert `rcFeedImage.url(product)`: das Titelbild, oder bei eingeschalteter Einstellung „Größeres Bild verwenden" ein ausreichend großes Bild desselben Artikels (bei Varianten auch des Hauptartikels). Ohne Bild kommt `null`.

Preise und Versandkosten tragen die Währung des Verkaufskanals: `{{ context.currency.isoCode }}`. Eine feste Angabe wie `EUR` wäre in einem Kanal mit anderer Währung falsch.

```twig
{% for country in rcShipping.getCountries() %}
{% set shippingCost = product.shippingFree ? 0.0 : rcShipping.get(product.id, country) %}
{% if shippingCost is not null %}
<g:shipping>
    <g:country>{{ country }}</g:country>
    <g:service>Standard</g:service>
    <g:price>{{ shippingCost | number_format(2, '.', '') }} EUR</g:price>
</g:shipping>
{% endif %}
{% endfor %}
```

Drei Dinge sind daran wichtig:

- **`get()` wird je Land genau einmal aufgerufen** und das Ergebnis gemerkt. Jeder Aufruf zählt im Plugin mit, und die Zusammenfassung am Feed-Ende meldet, wie viele Produkte den Ersatzwert bekommen haben. Ein zweiter Aufruf in der Bedingung verdoppelt diese Zahl, und wer ihr nicht trauen kann, übersieht einen stillen Ausfall.
- **`shippingFree` hat Vorrang.** Kostenloser Versand ist eine Eigenschaft des Artikels, keine Rechenfrage.
- **`is not null` bleibt Pflicht.** `null` heißt: kein Block ausgeben. Das ist ausdrücklich nicht dasselbe wie 0,00 €, denn den gibt es als echtes Ergebnis.

Die Länder kommen aus `rcShipping.getCountries()` und damit aus der Einstellung. Steht stattdessen eine feste Liste im Template, trägt jede Änderung an den konfigurierten Ländern nur halb, und niemand sieht, welche Hälfte fehlt.

Ein vollständiges Referenz-Template liegt im Plugin unter:

```
src/Resources/views/product-export/template.xml.twig
```

Es holt die Länder ebenfalls über `rcShipping.getCountries()`. Ein neu eingetragenes Land braucht deshalb keine Änderung am Template.

---

## Vorberechnete Versandkosten

Die berechneten Werte stehen in der Tabelle `rc_product_feed_shipping_price`, je Produkt, Land
und Verkaufskanal.

**Sie liegen bewusst nicht im Cache.** Bis Fassung 1.2.0 taten sie das, und ein
`bin/console cache:clear` leerte sie mit. Bis zum nächsten Warmup nannte der Feed danach für
jeden Artikel den Ersatzwert. Da der Warmup alle sechs Stunden läuft, konnte dieser Zustand
sechs Stunden anhalten. Cache leeren gehört zu jedem Update und jedem Aufspielen, die Falle schnappte
also planmäßig zu und nicht nur ausnahmsweise.

**Befüllen:**
```bash
php bin/console rc:shipping:warmup
```

**Der Lauf rechnet nur, was sich geändert hat.** Je Produkt entsteht ein Prüfwert über Gewicht,
Abmessungen, Preis, Steuersatz, Versandkostenfreiheit und Aktiv-Merkmal, Bestellbarkeit,
Kategorien, Tags, Eigenschaften und Hersteller, dazu über den Stand der Versandarten, ihrer Namen,
Preismatrizen und der Regeln. Ist er unverändert, bleibt der gespeicherte Wert
stehen; spätestens nach sieben Tagen wird trotzdem neu gerechnet. `--full` erzwingt eine
vollständige Neuberechnung:

```bash
php bin/console rc:shipping:warmup --full
```

Der Warmup berechnet die Versandkosten für alle aktiven Produkte und Länder aller aktivierten
Verkaufskanäle. Ohne ihn trägt der Feed den Ersatzwert und schreibt für jeden betroffenen
Artikel eine Warnung ins Protokoll.

**Wann Einträge verfallen:**

- Eine Änderung an einer Versandart oder einem ihrer Preise löscht nichts, sie zieht den nächsten
  Warmup vor (Subscriber). Bis dahin bleibt der bisherige, echte Preis stehen.
- Ein Eintrag, der älter als 24 Stunden ist, gilt als nicht vorhanden. Das ist die
  Rückfalllinie, falls Warmup-Läufe ausfallen; jeder Lauf erneuert alle Einträge, auch die
  unveränderten.
- Ein `cache:clear` berührt die Tabelle **nicht** mehr.

**Von Hand abräumen** (erzwingt eine vollständige Neuberechnung beim nächsten Warmup):
```sql
DELETE FROM rc_product_feed_shipping_price;
```

Beim Deinstallieren wird die Tabelle entfernt, sofern nicht „Daten behalten" gewählt wurde.

### Bildmaße prüfen

Ab dem 31.01.2027 verlangt Google für `image_link` mindestens 500 × 500 Pixel, beide Kanten.
Was der Bestand hergibt, sagt:

```bash
php bin/console rc:feed:image-check            # die drei Zahlen
php bin/console rc:feed:image-check --liste    # die Artikel ohne ausreichendes Bild
php bin/console rc:feed:image-check --nachmessen   # Stichprobe gegen die echten Dateien
```

Der Befehl misst und meldet nur, am Feed ändert er nichts. Varianten zählen mit dem Titelbild, das sie im Feed
tatsächlich tragen, also bei geerbten Bildern mit dem des Hauptartikels.

`rc:shipping:check` listet die Kombinationen, die den Ersatzwert tragen. Fehlende Werte rechnet die Prüfung und legt
sie wie der Warmup ab.

---

## Referenzadressen

Für die Berechnung braucht das Plugin je Land eine konkrete Lieferadresse, sonst wertet Shopware Regeln wie die Zuordnung zu Versandzonen oder Preise nach Postleitzahl nicht korrekt aus. Das Plugin nutzt dafür intern fest hinterlegte Referenzadressen.

Für Deutschland steht dort Kassel (34117) statt einer Großstadt. Kassel liegt geographisch zentral und wird typischerweise in Versandzone 2 eingestuft. Berlin oder München landen je nach Spediteur in Zone 1 (günstiger), was zu einem zu niedrigen Feed-Preis führen würde.

---

## Was im Feed steht

| Situation | Ergebnis |
|---|---|
| Versandart gefunden | Ihr Preis, **auch 0,00 €**, wenn der Shop tatsächlich versandkostenfrei liefert |
| Keine Versandart greift für dieses Land | Ersatzwert oder gar kein `g:shipping`-Block, je nach Einstellung „Wenn für ein Land keine Versandart greift" |
| Nichts vorberechnet (Zwischenspeicher kalt) | Ersatzwert, unabhängig von der Einstellung |
| Fehler bei der Berechnung | Ersatzwert, mit Eintrag im Protokoll |

**Warum 0,00 € stehen bleibt:** Liefert der Shop ab einem bestimmten Warenwert kostenlos, ist 0,00 € die richtige Angabe und nicht der Ersatzwert. Selbstabholung dagegen wäre eine falsche 0,00 €, deshalb gibt es die Ausschlussliste.

**Warum ein kalter Speicher nie zum Weglassen führt:** „Keine Versandart" ist eine Aussage über das Sortiment, „nichts vorberechnet" ein Betriebszustand. Würde der zweite Fall den Block ebenfalls weglassen, verlöre der Feed nach jedem `cache:clear` schlagartig alle Versandangaben.

Ersatzwerte werden ebenfalls zwischengespeichert, damit fehlerhafte Berechnungen nicht bei jedem Export wiederholt werden.

---

## Update

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcProductFeedShippingExtension
php bin/console cache:clear
php bin/console rc:shipping:warmup
```

---

## Bekannte Einschränkungen

**Sprachen-Konfiguration:** Der Verkaufskanal, der für die Berechnung verwendet wird, muss mindestens eine Sprache zugewiesen haben. Ohne Sprache schlägt die Context-Erstellung fehl und alle Berechnungen greifen auf den Fallback zurück. Prüfen unter **Verkaufskanäle → [Kanal] → Sprachen**.

**Versandarten ohne Liefergebiet:** Greift für ein Land keine Versandart, etwa weil sperrige oder schwere Ware dorthin nur auf Anfrage verschickt wird, entscheidet die Einstellung „Wenn für ein Land keine Versandart greift", ob der Ersatzwert erscheint oder der Block entfällt. Ein Feed kann „auf Anfrage" nicht ausdrücken; das Weglassen kommt dem am nächsten, weil Google dann die Versandeinstellung des Händlerkontos heranzieht.

**Nicht bestellbare Artikel:** Elternartikel mit Varianten und ausverkaufte Abverkaufsartikel lassen sich nicht in einen Warenkorb legen und bekommen deshalb nie einen berechneten Preis. Der Produkt-Export liefert Elternartikel ohnehin nicht aus; in der Zählung von `rc:shipping:check` tauchen sie dennoch auf.

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)

