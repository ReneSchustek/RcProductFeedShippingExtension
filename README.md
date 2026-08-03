# RcProductFeedShippingExtension

Shopware 6 Plugin — berechnet Versandkosten pro Produkt und Land und stellt sie im Produktfeed im Google Shopping Format bereit.

---

## Was das Plugin macht

Google Shopping erwartet für korrekte Versandkostenangaben einen `g:shipping`-Block pro Land im Feed. Statische Preise sind wartungsaufwändig und werden schnell falsch — spätestens wenn sich Versandzonen oder Preise ändern.

Dieses Plugin löst das Problem, indem es für jedes Produkt im Feed einen virtuellen Warenkorb aufbaut und Shopwares eigene Versandkostenkalkulation durchlaufen lässt. Dabei werden Shopware-Regeln (Versandzonen, Gewichtsgrenzen, Produkteigenschaften) vollständig ausgewertet, nicht nachgebaut. Die Ergebnisse werden vorberechnet in einer eigenen Tabelle gehalten, sodass wiederholte Feed-Exports performant bleiben und ein Cache-Leeren sie nicht mitnimmt.

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
| Plugin aktivieren | An/Aus pro Verkaufskanal | — |
| Verkaufskanal für Versandberechnung | UUID eines Storefront-Kanals; nötig wenn der Feed über einen Produktvergleichs-Kanal läuft | `abc123...` |
| Versandländer | ISO-Codes, kommasepariert | `DE,AT,CH` |
| Fallback-Versandkosten | Preis wenn Berechnung fehlschlägt | `4.95` |
| Fallback pro Land | Länderspezifische Fallbacks | `DE:4.95,AT:9.90,CH:14.90` |
| Wenn für ein Land keine Versandart greift | Ersatzwert eintragen (Standard) oder Land weglassen | — |
| Ausgeschlossene Versandarten | Keywords, kommasepariert | `Selbstabholung,Pickup` |

**Unterstützte Länder:** DE, AT, CH, BE, BG, CY, CZ, DK, EE, ES, FI, FR, GR, HR, HU, IE, IT, LT, LU, LV, MT, NL, PL, PT, RO, SE, SI, SK, GB, NO, IS, LI, US, CA, AU

**Hinweis zu ausgeschlossenen Versandarten:** Das Plugin wählt immer die günstigste Versandart. Ohne dieses Feld würde Selbstabholung (0,00 €) gewinnen und Google würde den Feed ablehnen. Standard-Keywords sind `Selbstabholung`, `Abholung` und `Pickup`.

**Hinweis zu Produktvergleichs-Kanälen:** Google Shopping läuft in Shopware üblicherweise als eigener Verkaufskanal vom Typ "Produktvergleich". Dieser Kanaltyp hat keine eigenen Versandmethoden. In dem Fall muss hier die UUID eines Storefront-Kanals eingetragen werden, der die gewünschten Versandmethoden enthält.

---

## Feed-Template

Das Plugin stellt die berechneten Versandkosten über eine Twig-Variable `rcShipping` zur Verfügung. Der Zugriff erfolgt mit `rcShipping.get(product.id, 'DE')`.

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

- **`get()` wird je Land genau einmal aufgerufen** und das Ergebnis gemerkt. Jeder Aufruf zählt im Plugin mit, und die Zusammenfassung am Feed-Ende meldet, wie viele Produkte den Ersatzwert bekommen haben. Ein zweiter Aufruf in der Bedingung verdoppelt diese Zahl — und wer ihr nicht trauen kann, sieht einen stillen Ausfall nicht.
- **`shippingFree` hat Vorrang.** Kostenloser Versand ist eine Eigenschaft des Artikels, keine Rechenfrage.
- **`is not null` bleibt Pflicht.** `null` heißt: kein Block ausgeben. Es ist ausdrücklich nicht dasselbe wie 0,00 € — den gibt es als echtes Ergebnis.

Die Länder kommen aus `rcShipping.getCountries()` und damit aus der Einstellung. Steht stattdessen eine feste Liste im Template, trägt jede Änderung an den konfigurierten Ländern nur halb, und niemand sieht, welche Hälfte fehlt.

Ein vollständiges Referenz-Template mit DE, AT und CH liegt im Plugin unter:

```
src/Resources/views/product-export/template.xml.twig
```

Wichtig: Das Template muss für jedes konfigurierte Land einen eigenen `g:shipping`-Block enthalten. Wird ein Land in der Plugin-Konfiguration hinzugefügt, muss es auch im Template ergänzt werden.

---

## Vorberechnete Versandkosten

Die berechneten Werte stehen in der Tabelle `rc_product_feed_shipping_price`, je Produkt, Land
und Verkaufskanal.

**Sie liegen bewusst nicht im Cache.** Bis Fassung 1.2.0 taten sie das, und ein
`bin/console cache:clear` leerte sie mit — bis zum nächsten Warmup nannte der Feed danach für
jeden Artikel den Ersatzwert. Da der Warmup alle sechs Stunden läuft, konnte dieser Zustand
sechs Stunden anhalten. Cache leeren gehört zu jedem Update und jedem Aufspielen; die Falle ging
also planmäßig zu, nicht ausnahmsweise.

**Befüllen:**
```bash
php bin/console rc:shipping:warmup
```

Der Warmup berechnet die Versandkosten für alle aktiven Produkte und Länder aller aktivierten
Verkaufskanäle. Ohne ihn trägt der Feed den Ersatzwert — und schreibt für jeden betroffenen
Artikel eine Warnung ins Protokoll.

**Wann Einträge verfallen:**

- Eine Änderung an einer Versandart räumt den Bestand ab (Subscriber).
- Ein Eintrag, der älter als 24 Stunden ist, gilt als nicht vorhanden. Das ist die
  Rückfalllinie für Änderungen, die niemand meldet: Gewicht und Maße eines Artikels bestimmen
  die Versandkosten mit.
- Ein `cache:clear` berührt die Tabelle **nicht** mehr.

**Von Hand abräumen** (erzwingt eine vollständige Neuberechnung beim nächsten Warmup):
```sql
DELETE FROM rc_product_feed_shipping_price;
```

Beim Deinstallieren wird die Tabelle entfernt, sofern nicht „Daten behalten" gewählt wurde.

---

## Referenzadressen

Für die Berechnung braucht das Plugin eine konkrete Lieferadresse pro Land — Shopware-Regeln wie Versandzonenzuordnung oder PLZ-basierte Preisregeln werden sonst nicht korrekt ausgewertet. Das Plugin nutzt dafür intern fest hinterlegte Referenzadressen.

Deutschland verwendet Kassel (34117) statt einer großstädtischen Adresse. Der Grund: Kassel liegt geographisch zentral und wird typischerweise in Versandzone 2 eingestuft. Berlin oder München landen je nach Spediteur in Zone 1 (günstiger), was zu einem zu niedrigen Feed-Preis führen würde.

---

## Was im Feed steht

| Situation | Ergebnis |
|---|---|
| Versandart gefunden | Ihr Preis — **auch 0,00 €**, wenn der Shop tatsächlich versandkostenfrei liefert |
| Keine Versandart greift für dieses Land | Ersatzwert, oder gar kein `g:shipping`-Block — je nach Einstellung „Wenn für ein Land keine Versandart greift" |
| Nichts vorberechnet (Zwischenspeicher kalt) | Ersatzwert, unabhängig von der Einstellung |
| Fehler bei der Berechnung | Ersatzwert, mit Eintrag im Protokoll |

**Warum 0,00 € stehen bleibt:** Liefert der Shop ab einem bestimmten Warenwert kostenlos, ist 0,00 € die richtige Angabe — nicht der Ersatzwert. Selbstabholung dagegen wäre eine falsche 0,00 €, deshalb gibt es die Ausschlussliste.

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

**Versandarten ohne Liefergebiet:** Greift für ein Land keine Versandart — etwa weil sperrige oder schwere Ware dorthin nur auf Anfrage verschickt wird —, entscheidet die Einstellung „Wenn für ein Land keine Versandart greift", ob der Ersatzwert erscheint oder der Block entfällt. Ein Feed kann „auf Anfrage" nicht ausdrücken; das Weglassen kommt dem am nächsten, weil Google dann die Versandeinstellung des Händlerkontos heranzieht.

**Nicht bestellbare Artikel:** Elternartikel mit Varianten und ausverkaufte Abverkaufsartikel lassen sich nicht in einen Warenkorb legen und bekommen deshalb nie einen berechneten Preis. Der Produkt-Export liefert Elternartikel ohnehin nicht aus; in der Zählung von `rc:shipping:check` tauchen sie dennoch auf.

---

## Projektdokumentation

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)

<!-- TRIAGE-WORKFLOW: auto-managed by triage-deploy.ps1 -->
