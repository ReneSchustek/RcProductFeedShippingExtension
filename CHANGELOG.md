# Changelog – RcProductFeedShippingExtension

Alle wesentlichen Änderungen werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [1.4.2] - 2026-08-10 — Die benötigte PHP-Fassung steht jetzt dabei

### Geändert

- **Das Plugin nennt die PHP-Fassung, die es braucht** (ab 8.2). Bisher stand sie als einziges unserer Plugins nicht in den Angaben — eine Installation auf einer zu alten Umgebung wäre erst zur Laufzeit aufgefallen.

## [1.4.1] - 2026-08-10 — Vorbereitet auf die nächste Shopware-Hauptversion

### Geändert

- **Vorbereitung auf die nächste Shopware-Hauptversion.** Der Zugriff auf Suchergebnisse folgt der Schreibweise, die Shopware 6.8 verlangt. Am Verhalten ändert sich nichts.

## [1.4.0] - 2026-08-03 — Fünf Wege zu einem falschen Betrag geschlossen

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear && php bin/console rc:shipping:warmup`. Der Warmup ist diesmal **Pflicht**: Die vorberechneten Preise ändern sich.

### Behoben

- **Artikel, die sich nicht in den Warenkorb legen lassen, bekamen den billigsten Versandtarif als gerechneten Preis.** Shopware entfernt solche Positionen — bei Hauptartikeln mit Varianten grundsätzlich, dazu bei Auslaufware ohne Bestand. Das Plugin rechnete danach mit einem leeren Warenkorb weiter: Gewicht und Preis sind dann 0, und jedes Preisband beginnt bei 0. Ein Artikel, dessen Varianten 238 € Versand kosten, bekam so 4,95 € — ohne Kennzeichnung als Ersatzwert und ohne Meldung. Auf dem Trummer-Bestand betraf das 313 Artikel. Solche Artikel liefern jetzt **gar keinen** Versandblock: Wir kennen den Preis nicht, und jede Zahl wäre erfunden.
- **Der Warmup erneuerte nichts, was noch gültig war.** Er füllte nur Lücken — ein Eintrag wurde erst neu gerechnet, nachdem er verfallen war. Bei einem Takt von sechs Stunden und einer Haltbarkeit von 24 hieß das: Der Lauf nach 24 Stunden traf Einträge, die knapp jünger waren, ließ sie stehen, und Minuten später verfielen sie. Bis zum übernächsten Lauf lief der Feed für **jedes** Produkt auf dem Ersatzwert. Der Warmup rechnet jetzt.
- **Steuerfreie Länder bekamen den Bruttopreis.** Für die Schweiz — ein voreingestelltes Land — rechnet der Shop netto ab. Der Feed nannte 20,83 €, wo die Kasse 17,50 € verlangt. Der Steuerzustand wird jetzt am berechneten Warenkorb abgelesen; im Kontext steht er nach der Berechnung nicht mehr, weil Shopware ihn dort zurücksetzt.
- **Bei mehreren passenden Regelpreisen gewann der billigste statt des höchstpriorisierten.** Die Kasse nimmt die Regel mit der höchsten Priorität. Bei „Sperrgut" (49,90 €) neben „Standardkunde" (5,90 €) verlangte die Kasse 49,90 € und der Feed nannte 5,90 €. Innerhalb einer Regel gewinnt weiterhin der günstigere Preis — auch das entspricht der Kasse.
- **Jedes Speichern einer Versandart warf alle vorberechneten Preise weg.** Bis zum nächsten geplanten Lauf — bis zu sechs Stunden — stand danach für jedes Produkt der Ersatzwert im Feed. Eine gewöhnliche Handlung im Verwaltungsbereich stellte damit genau den Zustand her, den die Vorberechnung verhindern soll. Die Preise bleiben jetzt stehen und der Warmup wird stattdessen vorgezogen.
- **Rein versandkostenfreie Warenkörbe** ergeben jetzt 0,00 € statt des billigsten Tarifs.

### Geändert

- Das mitgelieferte Feed-Template holt den Preis je Land **einmal** und merkt ihn, statt `get()` zweimal aufzurufen. Jeder Aufruf zählt in der Zusammenfassung am Feed-Ende mit; der zweite verdoppelte die gemeldete Zahl. Außerdem kommen die Länder jetzt aus der Einstellung statt aus einer festen Liste im Template.
- Die Zusammenfassung nennt nicht bestellbare Artikel als eigene Zahl.

## [1.3.0] - 2026-08-03 — Die vorberechneten Versandkosten überleben ein Cache-Leeren

> **Deployment:** `plugin:update` legt die neue Tabelle an. Danach einmal
> `bin/console rc:shipping:warmup` ausführen — vorher stehen im Feed Ersatzwerte.

### Behoben

- **Nach jedem Leeren des Caches nannte der Feed für jeden Artikel den Ersatzwert.** Die
  vorberechneten Versandkosten lagen im Objekt-Cache und verschwanden mit ihm. Bis der Warmup
  wieder lief — er läuft alle sechs Stunden — stand für jede Ware derselbe Betrag im Feed, auch
  für Ware, deren Versand über 200 Euro kostet. Zu niedrig angegebene Versandkosten sind bei
  Google ein Richtlinienverstoß.

  Das Leeren des Caches gehört zu jedem Plugin-Update, jeder Theme-Änderung und jedem
  Aufspielen. Der Zustand trat also planmäßig ein, nicht ausnahmsweise.

  Die Werte stehen jetzt in einer eigenen Tabelle. Sie überstehen das Leeren des Caches, einen
  Neustart und das Aufspielen einer neuen Fassung. Eine Änderung an einer Versandart räumt sie
  weiterhin ab, und ein Eintrag, der älter als 24 Stunden ist, gilt als nicht vorhanden — denn
  Gewicht und Maße eines Artikels ändern sich, ohne dass es jemand meldet.

### Geändert

- Beim Deinstallieren wird die neue Tabelle entfernt, sofern nicht ausdrücklich „Daten behalten"
  gewählt wurde. Ihr Inhalt ist jederzeit neu berechenbar.

### Intern

- Der Speicher hat keine Schema-Version mehr im Schlüssel. Sie war nötig, solange ein
  serialisiertes Objekt abgelegt wurde: Kam ein Feld hinzu, fehlte es in den alten Daten. Mit
  ausgeschriebenen Spalten stellt sich die Frage nicht mehr.
- Erste Integrationstests in diesem Plugin (7 Tests gegen eine echte Datenbank). Der Speicher
  lässt sich nicht sinnvoll gegen einen Doppelgänger prüfen — seine einzige Zusage ist, dass er
  etwas überlebt.

## [1.2.0] - 2026-07-31 — Kein erfundener Versandpreis mehr für Ware, die nicht dorthin geht

> **Deployment:** `plugin:update`, Cache leeren, `rc:shipping:warmup` ausführen. Der Zwischenspeicher wird einmalig neu aufgebaut.

### Neu

- **Einstellung „Wenn für ein Land keine Versandart greift":** Für sperrige oder schwere Ware gibt es in manche Länder schlicht keine Versandart — sie geht nur auf Anfrage. Bisher schrieb der Feed dort trotzdem den Ersatzwert und sagte damit einen Versand zu, den es nicht gibt; zu niedrig angegebene Versandkosten sind bei Google ein Richtlinienverstoß. Neben dem bisherigen Verhalten (Standard, ändert sich nichts von selbst) lässt sich jetzt einstellen, dass der `g:shipping`-Block für dieses Land entfällt. Google greift dann auf die Versandeinstellung des Händlerkontos zurück.
- **`rc:shipping:check` nennt den Grund** je Zeile und zählt ihn am Ende: „keine Versandart", „nicht vorberechnet" oder „Berechnung fehlgeschlagen". Damit ist die Liste der Auf-Anfrage-Artikel direkt ablesbar.

### Behoben

- **`rc:shipping:check` sah nur einen Teil des Sortiments.** Varianten, die ihr „aktiv" vom Elternartikel erben, fehlten in der Prüfung — sie meldete dadurch weniger als die Hälfte der tatsächlichen Ersatzwerte. Prüfung und Vorberechnung beziehen ihre Produktliste jetzt aus derselben Quelle.
- **Beschreibungstexte in den Plugin-Einstellungen korrigiert:** Sie behaupteten, es werde nie 0,00 € ausgegeben. Liefert der Shop tatsächlich versandkostenfrei, ist 0,00 € die richtige Angabe und wird auch so ausgeliefert.

## [1.1.4] - 2026-07-20 — Bugfix: Versandkosten respektieren Preis-Staffeln (Gewicht/Preis/Menge)

> **Deployment:** Feed neu generieren / Cache leeren.

### Behoben

- **Feed-Versandkosten entsprechen jetzt dem echten Checkout-Preis für gestaffelte Versandmethoden:** Hat eine Versandmethode mehrere Preis-Staffeln (z. B. 0–5 kg = 4,95 €, 5–30 kg = 19,95 €), wurde bisher fälschlich das **Minimum über alle Staffeln** in den Feed geschrieben — ein 15-kg-Produkt bekam so 4,95 € statt der real berechneten 19,95 €. Das untertrieb die Versandkosten und führte zu Google-Shopping-Beanstandungen (Feed-Preis ≠ Checkout-Preis). Die Staffel-Auswahl bildet jetzt exakt `DeliveryCalculator::matches()` des Shopware-Cores nach: Es wird die Staffel gewählt, deren Band (`quantityStart`..`quantityEnd`) den tatsächlichen Warenkorb-Kennwert je Berechnungsart (Gewicht/Preis/Positionsanzahl/Volumen) enthält bzw. deren `calculationRuleId` matcht. Liegt der Wert über allen Bändern, greift wie im Checkout der reguläre Fallback.

## [1.1.3] - 2026-07-20 — Feed-Robustheit + Performance

> **Deployment:** Feed neu generieren / Cache leeren.

### Behoben

- **Feed bricht nicht mehr ab, wenn das Plugin deaktiviert ist:** Das Feed-Template rief `rcShipping.get(...)` ohne `is defined`-Guard auf. Der Produkt-Export läuft mit `strict_variables=on` — war das Plugin deaktiviert (oder keine Länder konfiguriert), setzte der Subscriber `rcShipping` bewusst nicht, und jede Produktzeile warf eine Exception → der gesamte Feed schlug fehl. Jetzt wird `is defined` geprüft (deaktiviert = keine `g:shipping`-Blöcke, wie dokumentiert).
- **Produkte ohne Titelbild brechen ihre Feed-Zeile nicht mehr:** `product.cover.media.url` wird jetzt geguardet (`{% if product.cover and product.cover.media %}`).

### Performance

- **N+1 im Export/Warmup beseitigt:** Die aktiven Versandmethoden (pro Sales-Channel) und die Länder-Entitäten (pro ISO) werden jetzt im Speicher memoized statt bei jedem Produkt×Land neu geladen (bei N Produkten × Ländern zuvor 3N redundante Queries).

## [1.1.2] - 2026-06-28 — Enterprise-Sweep: Static-Analysis-Härtung

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`

### Behoben (Typsicherheit, verhaltens-neutral)

- **PHPStan Level 8 sauber** (vorher ~14 Befunde): EntityRepository-Generics in `AbstractShippingCommand` und `ShippingCostCalculatorService` ergänzt; `loadActiveSalesChannels()` liefert nun typkorrekt `SalesChannelEntity`; iterable-Wert-Typen in `ShippingWarmupCommand::warmupProducts()`.
- **Country-Lookup typsicher:** Das Ergebnis von `countryRepository->search()->first()` wird per `instanceof CountryEntity` verengt — entfernt die undefinierten `Entity::getId()`/`setCountry()`-Befunde, gleiches Laufzeitverhalten (Nicht-Land → Fallback).
- **Tote Null-/Default-Pfade entfernt:** `getTranslated() ?? []`, `getPrices() === null` und `SHOPWARE_FALLBACK_VERSION ?? 'unknown'` waren nachweislich nicht erreichbar (non-nullable Rückgaben) — entfernt ohne Verhaltensänderung.
- **`ShippingContextProvider`** erbt jetzt von `Struct`, wie es der `ProductExportRenderBodyContextEvent::setContext(array<string, Struct>)`-Vertrag erwartet.
- CS-Fixer-Format-Drift bereinigt (13 Dateien).

> **Hinweis:** Die Versandkosten-**Rechenlogik** wurde bewusst nicht angefasst (geld-relevant) — ausschließlich Typsicherheit und Robustheit.

## [1.1.1] - 2026-05-13

> **Deployment:** Reines Test-/Validierungs-Update. Live-Shop benötigt nur `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.

### Behoben
- **ISO-Code-Validierung war für trailing Newlines löchrig.** `/^[a-zA-Z]{2,3}$/` matchte in PHP-PCRE `"DE\n"`, weil das `$`-Anker standardmäßig einen optionalen Newline am Ende toleriert. Mit `D`-Flag (`/.../D`) ist das jetzt streng — `"DE\n"` wird wie vorgesehen als ungültig erkannt und geloggt. Eine bestehende Datenanbieter-Test-Variante (`newline`) deckte den Bug auf, war aber bisher rot.
- **Test-Helper `ShippingCostCalculatorServiceTest::buildShippingMethod()` rief `setRuleId(null)` auf** — Shopware hat die Signatur in einem 6.7-Update auf `setRuleId(string)` verengt (Getter ist weiter `?string`, asymmetrisch). Helper ruft `setRuleId` jetzt nur noch bei nicht-null auf; semantisch identisch (Default-Regel = ruleId bleibt null).
- **`ProductFeedSubscriberTest` referenzierte die alte Event-Klasse `ProductExportRenderBodyContext`** — Shopware hat sie auf `ProductExportRenderBodyContextEvent` umbenannt (Subscriber im src/ verwendete die neue bereits). Test-Importe und vier Type-Hints/Instanziierungen angepasst.

### Offen

## [1.1.0] - 2026-05-11

> **Deployment:** `php bin/console cache:clear` (Container-Rebuild für neuen Subscriber). Keine Datenbank-Migration. Bestehende Cache-Einträge werden beim ersten Feed-Request neu berechnet.

### Hinzugefügt
- **`ShippingMethodChangeSubscriber`**: invalidiert den 24h-Cache des Plugins automatisch, sobald Admin Versandmethoden oder Versandpreise ändert. Hört auf `EntityWrittenContainerEvent` und prüft auf `shipping_method`/`shipping_method_price`-Einträge. Bisher mussten Admins entweder `bin/console cache:clear` aufrufen oder bis zu 24 Stunden warten, bis Feed-Aktualisierungen Versandänderungen reflektierten. Drei neue Unit-Tests sichern das Verhalten.
- **ISO-Code-Format-Validierung** in `ShippingContextProvider::get()`: Regex `[a-zA-Z]{2,3}` filtert UUIDs, leere Strings, Whitespace und andere Template-Tippfehler. Ungültige Codes werden mit `logger->warning('… ungültiges ISO-Code-Format ignoriert …')` und `metric: invalid_iso_code` geloggt — bisher wurde stillschweigend `null` zurückgegeben. Acht neue Datenanbieter-Tests.
- **Strukturierte Metric-Tags** in Logs an drei Stellen: `metric: invalid_iso_code` (Provider-Validierung), `metric: provider_fallback_used` (unerwartete Exception in Provider), `metric: reference_address_missing` (erwarteter Fall im Calculator), `metric: address_provider_error` (unerwarteter Fall im AddressProvider), `metric: calculation_fallback_used` (Top-Level-Fallback). Ops-Teams können damit Hit-/Fallback-Raten ohne Code-Walkthrough auf einem Dashboard aggregieren.

### Geändert
- **Spezifischere Exception-Behandlung** in `ShippingCostCalculatorService::injectShippingLocation()`: separate Catches für erwartete `CountryNotFoundException` (info-Log, regulärer Fallback-Pfad) und unerwartete `\Throwable` (error-Log mit Exception-Klasse). Bisher landete beides im selben Catch-all ohne Differenzierung.
- **Error-Log im Calculator-Top-Level-Catch** trägt jetzt `exception`-Feld (Klasse), `message`-Feld und `metric: calculation_fallback_used` — bisher nur `error: $e->getMessage()` ohne Klasse.

## [1.0.0] – 2026-04-15

> **Deployment:** `php bin/console cache:clear` (Erstinstallation).

### Hinzugefügt
- Versandkosten-Berechnung pro Produkt und Land
- Google Shopping Feed Integration
- Cache-System: `rc_shipping_{productId}_{countryIso}_{salesChannelId}`
- 11 Feature-Implementierungen abgeschlossen
- Shopware 6.7+ Kompatibilität
