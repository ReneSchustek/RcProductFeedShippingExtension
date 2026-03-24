# RcProductFeedShippingExtension

Shopware 6 Plugin — berechnet Versandkosten pro Produkt und Land und stellt sie im Produktfeed im Google Shopping Format bereit.

---

## Was das Plugin macht

Google Shopping erwartet für korrekte Versandkostenangaben einen `g:shipping`-Block pro Land im Feed. Statische Preise sind wartungsaufwändig und werden schnell falsch — spätestens wenn sich Versandzonen oder Preise ändern.

Dieses Plugin löst das Problem, indem es für jedes Produkt im Feed einen virtuellen Warenkorb aufbaut und Shopwares eigene Versandkostenkalkulation durchlaufen lässt. Dabei werden Shopware-Regeln (Versandzonen, Gewichtsgrenzen, Produkteigenschaften) vollständig ausgewertet, nicht nachgebaut. Die Ergebnisse werden 24 Stunden gecacht, sodass wiederholte Feed-Exports performant bleiben.

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
| Ausgeschlossene Versandarten | Keywords, kommasepariert | `Selbstabholung,Pickup` |

**Unterstützte Länder:** DE, AT, CH, BE, BG, CY, CZ, DK, EE, ES, FI, FR, GR, HR, HU, IE, IT, LT, LU, LV, MT, NL, PL, PT, RO, SE, SI, SK, GB, NO, IS, LI, US, CA, AU

**Hinweis zu ausgeschlossenen Versandarten:** Das Plugin wählt immer die günstigste Versandart. Ohne dieses Feld würde Selbstabholung (0,00 €) gewinnen und Google würde den Feed ablehnen. Standard-Keywords sind `Selbstabholung`, `Abholung` und `Pickup`.

**Hinweis zu Produktvergleichs-Kanälen:** Google Shopping läuft in Shopware üblicherweise als eigener Verkaufskanal vom Typ "Produktvergleich". Dieser Kanaltyp hat keine eigenen Versandmethoden. In dem Fall muss hier die UUID eines Storefront-Kanals eingetragen werden, der die gewünschten Versandmethoden enthält.

---

## Feed-Template

Das Plugin stellt die berechneten Versandkosten über eine Twig-Variable `rcShipping` zur Verfügung. Der Zugriff erfolgt mit `rcShipping.get(product.id, 'DE')`.

```twig
{% if product.shippingFree %}
<g:shipping>
    <g:country>DE</g:country>
    <g:service>Standard</g:service>
    <g:price>0.00 EUR</g:price>
</g:shipping>
{% elseif rcShipping.get(product.id, 'DE') is not null %}
<g:shipping>
    <g:country>DE</g:country>
    <g:service>Standard</g:service>
    <g:price>{{ rcShipping.get(product.id, 'DE') | number_format(2, '.', '') }} EUR</g:price>
</g:shipping>
{% endif %}
```

Der `shippingFree`-Check kommt zuerst — kostenloser Versand hat immer Vorrang. Der `is not null`-Check danach verhindert leere `g:shipping`-Nodes, wenn für ein Land kein Ergebnis vorliegt.

Ein vollständiges Referenz-Template mit DE, AT und CH liegt im Plugin unter:

```
src/Resources/views/product-export/template.xml.twig
```

Wichtig: Das Template muss für jedes konfigurierte Land einen eigenen `g:shipping`-Block enthalten. Wird ein Land in der Plugin-Konfiguration hinzugefügt, muss es auch im Template ergänzt werden.

---

## Cache

Berechnungsergebnisse werden 24 Stunden gecacht. Cache-Key je Eintrag: `rc_shipping_{productId}_{countryIso}_{salesChannelId}`.

**Gesamten Cache leeren:**
```bash
php bin/console cache:clear
```

**Nur Versandkosten-Cache leeren** (alle Einträge tragen den Tag `rc_shipping`):
```bash
php bin/console cache:pool:invalidate-tags cache.object rc_shipping
```

**Cache vorab befüllen:**
```bash
php bin/console rc:shipping:warmup
```

Der Warmup berechnet Versandkosten für alle aktiven Produkte und Länder aller aktivierten Verkaufskanäle und legt die Ergebnisse im Cache ab. Ohne Warmup werden fehlende Einträge beim ersten Feed-Export live berechnet, was die Exportdauer spürbar erhöhen kann.

---

## Referenzadressen

Für die Berechnung braucht das Plugin eine konkrete Lieferadresse pro Land — Shopware-Regeln wie Versandzonenzuordnung oder PLZ-basierte Preisregeln werden sonst nicht korrekt ausgewertet. Das Plugin nutzt dafür intern fest hinterlegte Referenzadressen.

Deutschland verwendet Kassel (34117) statt einer großstädtischen Adresse. Der Grund: Kassel liegt geographisch zentral und wird typischerweise in Versandzone 2 eingestuft. Berlin oder München landen je nach Spediteur in Zone 1 (günstiger), was zu einem zu niedrigen Feed-Preis führen würde.

---

## Fallback-Verhalten

| Situation | Ergebnis |
|---|---|
| Berechnung erfolgreich | Berechneter Preis, `isFallback = false` |
| Keine Versandart greift | 0,00 €, `isFallback = false` |
| Alle Versandarten ausgeschlossen | 0,00 €, `isFallback = false` |
| Fehler bei der Berechnung | Konfigurierter Fallback-Preis, `isFallback = true` |
| Land nicht in Shopware konfiguriert | Konfigurierter Fallback-Preis, `isFallback = true` |

Fallback-Ergebnisse werden ebenfalls gecacht, damit fehlerhafte Berechnungen nicht bei jedem Export wiederholt werden.

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

**Versandarten ohne Liefergebiet:** Greift für ein Land keine Versandart (z.B. weil das Land nicht im konfigurierten Liefergebiet liegt), gibt das Plugin den konfigurierten Fallback-Preis zurück.

---

## Projektdokumentation

- Projektstatus und offene Aufgaben: [.ai/status.md](.ai/status.md)
- Entwicklungsregeln: [CLAUDE.md](CLAUDE.md)
- Architektur: [.ai/context/architecture.md](.ai/context/architecture.md)
- Deployment: `F:\Entwicklung\_Anleitungen\shopware\DEPLOYMENT-RcProductFeedShippingExtension.md`

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)
