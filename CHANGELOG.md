# Changelog – RcProductFeedShippingExtension

Alle wesentlichen Änderungen werden in dieser Datei dokumentiert.
Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).


## [1.9.0] - 2026-10-15 — Währung, Prüfwert und Bildprüfung

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.
> Der erste Warmup danach rechnet alle Einträge neu, weil der Prüfwert mehr Bestandteile hat. Wer die mitgelieferte
> Vorlage in den Produktexport kopiert hat, übernimmt die drei Zeilen mit `g:price` neu.

### Behoben

- Die mitgelieferte Feed-Vorlage schreibt die Währung des Verkaufskanals statt fest `EUR`.
- Der Prüfwert erfasst jetzt auch Bestellbarkeit, Kategorien, Tags, Eigenschaften, Hersteller und die Namen der
  Versandarten. Eine Versandregel, die darauf schaut, oder eine umbenannte Versandart wirkt damit beim nächsten Warmup,
  nicht erst nach einem Lauf mit `--full`.
- Die Bildprüfung zählt Varianten mit dem geerbten Titelbild des Hauptartikels und sucht ihr Ersatzbild auch dort, wie
  der Feed selbst. Vorher standen rund 570 Varianten fälschlich als „ohne Titelbild" da. Die Liste nennt jeden Artikel
  einmal, mit Namen in der Standardsprache.
- `rc:shipping:check` räumt den Warenkorb weg, den es für die Berechnung anlegt.
- Scheitert der Kontext eines Kanals, nennen Prüfung und Warmup den Grund.

### Entfernt

- Ungenutzter Code: `GoogleShippingFormatterService`, `ShippingPriceStore::invalidateAll()`,
  `ShippingAddressProviderService::hasCountry()` und `getSupportedCountryCodes()`.

## [1.8.1] - 2026-10-15 — Worker rechnet nicht mit alten Werten

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.

### Behoben

- Die Zwischenspeicher für Versandarten, Länder, Einstellungen, Prüfwert und Feedbilder werden zwischen zwei Nachrichten
  eines langlebigen Workers geleert. Ein Warmup rechnete sonst unter Umständen mit Werten aus dem Lauf davor.
- Die Bildprüfung (`rc:feed:image-check`) zählt eine beschädigte Datei als unlesbar, ohne Warnungen zu unterdrücken.

## [1.8.0] - 2026-10-14: Versandarten über die Kennung ausschließen

> **Ausrollen:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.
> Keine Migration. Danach in der Plugin-Konfiguration „Versandarten, die nie in den Feed dürfen" wählen
> (Selbstabholung, Platzhalter-Versandart) und `rc:shipping:warmup` laufen lassen; die Änderung erkennt
> der Lauf selbst, `--full` ist nicht nötig.

### Neu

- **Auswahl „Versandarten, die nie in den Feed dürfen"**: Mehrfachauswahl der Versandarten, gespeichert
  werden die Kennungen. Eine Umbenennung hebt den Ausschluss nicht auf. Auf Live hieß die Abholung
  „Selbstabholer"; keiner der voreingestellten Namensteile steckte darin, und fast der ganze Feed trug
  0,00 € Versand.
- **Die Vorberechnung erkennt eine geänderte Auswahl selbst**: Auswahl und Namensteile stehen im
  Stempel; nach einer Änderung rechnet der nächste Lauf alles neu.
- **Gelöschte Versandart in der Auswahl**: Der Lauf rechnet weiter und protokolliert die Kennung einmal
  je Kanal als Warnung.

### Geändert

- Das Feld mit Namensteilen heißt jetzt „Ausgeschlossene Versandarten nach Namensteil (veraltet)" und
  wirkt vorerst zusätzlich. Es entfällt in einer späteren Fassung.

## [1.7.0] - 2026-10-13: Ein größeres Bild für Google, wenn das Titelbild zu klein ist

> **Ausrollen:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.
> Keine Migration. Der Feed ändert sich erst, wenn die neue Einstellung eingeschaltet ist **und** die
> Export-Vorlage `rcFeedImage.url(product)` statt `product.cover.media.url` verwendet.

### Neu

- **`rcFeedImage.url(product)` für die Export-Vorlage**. Liegt das Titelbild unter 500 × 500
  Pixeln, liefert es das erste ausreichend große Bild desselben Artikels; eine Variante ohne eigene Bilder bekommt
  die des Hauptartikels. Maßzeichnungen werden übersprungen, sie sind kein Hauptbild für Google. Gibt es kein
  passendes Bild, bleibt das Titelbild, und der Artikel steht auf der Liste von `rc:feed:image-check --liste`.
  Hochgerechnet wird nie. Dateinamen mit Leerzeichen und Komma werden in der Adresse kodiert.
- **Einstellung „Größeres Bild verwenden, wenn das Titelbild zu klein ist"** (Karte „Bilder für Google"),
  Vorgabe aus. Ausgeschaltet liefert `rcFeedImage.url(product)` genau das Titelbild.
- Die Referenzvorlage (`src/Resources/views/product-export/template.xml.twig`) verwendet `rcFeedImage.url(product)`.

### Gemessen auf der Testkopie (13.10.2026)

| | Artikel |
|---|--:|
| Titelbild zu klein | 426 |
| davon mit einem anderen ausreichenden Foto (ohne Zeichnungen) | 18 |
| davon ohne jedes ausreichende Foto | **408** |

Mit Zeichnungen wären es 326 Ersatzbilder gewesen, 306 davon Maßzeichnungen.

## [1.6.0] - 2026-09-09: Der Warenstrom sagt, welche Bilder Google ab 2027 nicht mehr annimmt

> **Ausrollen:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.
> Keine Migration, kein Eingriff in den Feed. Der neue Befehl misst und meldet, mehr nicht.

### Neu

- **Befehl `rc:feed:image-check`.** Ab dem 31.01.2027 verlangt das Merchant Center für
  `image_link` mindestens **500 × 500 Pixel**, beide Kanten; kleinere Bilder werden nicht mehr
  genehmigt und das Produkt erscheint nicht mehr bei Google. Der Befehl nennt, wie viele Artikel
  betroffen sind, und trennt dabei die beiden Fälle, die verschiedene Lösungen brauchen:

  | | am Bestand vom 09.09.2026 |
  |---|--:|
  | Titelbild groß genug | 1 681 |
  | **Titelbild zu klein** | **413** |
  | davon mit einem anderen ausreichenden Bild | **313** |
  | davon ohne jedes ausreichende Bild | **100** |
  | ohne Titelbild | 586 |

- **`--liste`** nennt die Artikel der letzten Gruppe mit Nummer, Bezeichnung und heutigem Maß.
  Das ist die Liste zum Abarbeiten. **`--nachmessen`** lädt die Bilddateien wirklich und hält ihre Maße
  gegen die Angaben der Mediathek; standardmäßig an einer Stichprobe von 25.

### Was dabei herauskam

- **Die vermutete Ursache war nicht die richtige.** Vermutet war eine zu klein gewählte
  Vorschaufassung. Alle drei Export-Vorlagen des Shops verlinken jedoch
  `product.cover.media.url`, also das **Original**. Eine andere Vorschaufassung zu wählen hilft
  deshalb nicht. Was hilft, ist ein **anderes Bild desselben Artikels**, und für 313 der 413
  Artikel gibt es eines.
- Die häufigsten zu kleinen Maße sind 400 × 400, 500 × 250, 500 × 333 und 480 × 400, also knapp
  daneben und nicht winzig. **Hochskalieren bleibt ausgeschlossen:** Google nähme das Bild an, im Shop
  stünde es unscharf.

## [1.5.0] - 2026-09-09: Die Vorberechnung rechnet nur noch, was sich geändert hat

> **Ausrollen (die Migration ist Pflicht, nicht Beiwerk):**
> `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.
>
> **Ohne sie schreibt der Speicher gar nichts mehr.** Die neue Spalte `source_hash` steht im
> `INSERT`; fehlt sie, scheitert jeder Schreibvorgang. Der Speicher fängt Schreibfehler
> bewusst ab und schreibt nur eine Warnung ins Protokoll. Der Feed nennte danach für **jeden**
> Artikel den Ersatzwert, ohne dass etwas sichtbar bricht. (Beim Prüflauf am 09.09. genau so
> passiert: In der Testdatenbank fehlte die Spalte, und vier Tests fielen mit „null ist nicht
> null" durch, statt mit einer Fehlermeldung.)
>
> Der erste Lauf danach rechnet noch einmal alles durch, denn die vorhandenen Einträge tragen keinen
> Prüfwert, und ein erfundener wäre eine Lüge.

### Geändert

- **Der Lauf rechnet nur noch, was sich geändert hat.** Bisher wurde bei jedem Lauf jede
  Kombination aus Produkt und Land neu berechnet, dreimal am Tag mit demselben Ergebnis. Auf dem
  produktiven Shop waren das **21 172 Berechnungen an einem Tag bei einer einzigen Bestellung**.
  Jetzt bildet die Erweiterung je Produkt einen Prüfwert über alles, was das Ergebnis bestimmt:
  Gewicht, Abmessungen, **Preis** (wegen „versandkostenfrei ab X"), Steuersatz, Kennzeichen für
  Versandkostenfreiheit und Aktiv-Merkmal, dazu den Stand der Versandarten, ihrer Preismatrizen
  und der Regeln. Ist er unverändert, bleibt der gespeicherte Wert stehen und wird nur aufgefrischt.
- **Ändert sich die Versandkonfiguration, ist der Prüfwert aller Produkte hinfällig.** Der Stand
  der Konfiguration steckt in jedem einzelnen Prüfwert, statt als Sonderweg daneben zu liegen. In
  ihn geht auch die **Anzahl** der Zeilen ein: Eine gelöschte Versandart hinterlässt keinen
  neueren Zeitstempel, wohl aber eine Zeile weniger.
- **Sicherheitsnetz:** Jeder Eintrag wird spätestens nach **sieben Tagen** neu gerechnet, auch bei
  gleichem Prüfwert. Damit fällt eine Änderung, die der Prüfwert nicht erfasst, von selbst auf.
  Die Frist gilt je Eintrag und verteilt die Last, statt sie an einem Wochentag zu bündeln.
- **Neuer Schalter `--full`** für `rc:shipping:warmup`: rechnet alles neu, auch nachweislich
  Unverändertes. Der Weg von Hand, wenn man dem Prüfwert im Einzelfall nicht traut.

### Behoben

- **Jede Berechnung ließ eine Warenkorbzeile in der Datenbank zurück.** Shopware schreibt einen
  berechneten Warenkorb weg, und jeder trug bisher ein eigenes Token: rund **ein Gigabyte
  Rohdaten am Tag**, dreißig Tage Aufbewahrung, entsprechend gewachsene nächtliche Sicherungen
  und Warenkorb-Kennzahlen, die zu 99,99 Prozent diesen Lauf maßen (die Abbruchquote im Dashboard
  stand dauerhaft auf 100 Prozent und musste abgeschaltet werden). Jetzt teilen sich alle
  Warenkörbe eines Laufs **ein** Token, und der Lauf räumt diese eine Zeile am Ende selbst weg.
  Von außen ginge das nicht sicher: In der Tabelle `cart` sind alle Merkmalsspalten leer, es
  bliebe die Uhrzeit, und wer sich in der Minute irrt, löscht einen echten Kundenwarenkorb.

## [1.4.6] - 2026-08-19: Der Warenstrom rechnet nur noch für Kanäle, die ihn lesen

### Behoben

- **Die länderspezifischen Ersatzwerte griffen nur für das erste Land.** Die Einträge wurden
  ausschließlich am Komma getrennt; stand in der Einstellung `DE:7.95;AT:14.95;CH:21.95`, war die
  ganze Zeichenkette ein einziger Eintrag. DE traf zufällig zu, AT und CH fielen still auf den
  globalen Wert zurück. **1040 Kombinationen Speditionsware standen dadurch mit 7,95 € statt
  14,95 € bzw. 21,95 € im Warenstrom**. Zu niedrig angegebene Versandkosten sind bei Google ein
  Richtlinienverstoß. Komma und Semikolon trennen jetzt gleichermaßen.
- **Ein unlesbarer Eintrag wird gemeldet statt zu 0,00 € zu werden.** `(float) 'frei'` ergibt
  null, also kostenlosen Versand im Warenstrom, entstanden aus einem Tippfehler. Die Meldung kommt
  einmal je Verkaufskanal, nicht je Kombination.

### Geändert

- **Warmup und `rc:shipping:check` arbeiten nur noch die Kanäle ab, die ein Produktexport
  ausliest.** Bisher war jeder aktive Kanal mit eingeschaltetem Plugin dabei, und eingeschaltet ist es
  standardmäßig überall. Auf der Testkopie traf das den Kanal „Headless" mit 20 statt 206
  Versandarten: **5223 Einträge „keine Versandart" für einen Kanal, den kein Warenstrom liest.**
  Sie waren nicht nur nutzlos, sie stellten fünf Sechstel aller Meldungen und haben die
  Fehlersuche zweimal in die falsche Richtung geschickt. Maßgeblich ist der Storefront-Kanal des
  Produktexports, denn den bekommt auch der Subscriber zur Laufzeit zu sehen.
- Ein übersprungener Kanal wird mit Grund gemeldet, in der Konsole und im Protokoll.

## [1.4.5] - 2026-08-19: „Keine Versandart" sagt jetzt, woran es lag

### Neu

- **Der Protokolleintrag nennt die Kennwerte, an denen die Auswahl gescheitert ist**: Menge,
  Warenwert, Gewicht, Volumen und die Zahl der zutreffenden Regeln. Bisher stand dort nur „keine
  Versandart", und die Suche begann bei null.
- **Dazu die Maße der Position:** Länge, Breite, Höhe, Gewicht, so wie sie im Warenkorb ankommen.
  Die Regeln der Versandarten prüfen die Länge, nicht das Volumen; ein Volumen von 0 sagt nur, dass
  Breite oder Höhe fehlen. Genau diese Angabe hat den Fehler sichtbar gemacht: Die Länge kommt an
  der Position gar nicht an.
- **Und die Spur der Auswahl**, also an welcher der drei Hürden die Versandarten hängengeblieben sind:
  Ausschlussliste, Verfügbarkeitsregel oder Preisstaffel. Ohne sie sieht der Aufrufer nur, dass
  nichts übrig blieb, und die Suche beginnt bei null.
- Der Anlass: 1373 Artikel in DE tragen im Warenstrom den Ersatzwert, obwohl der Shop sie im
  echten Warenkorb versendet. Die Verfügbarkeitsregeln hängen fast alle an Gewicht, Länge und
  Warenwert der Position. Ohne diese Zahlen im Protokoll lässt sich nicht unterscheiden, ob die
  Regel zu eng ist oder die Position ihre Lieferangaben gar nicht mitbringt.

## [1.4.4] - 2026-08-19: Ohne Hersteller bleibt das Markenfeld weg

### Behoben

- **35 Artikel trugen ein leeres `<g:brand></g:brand>`.** Es sind Artikel ohne Hersteller. Ein
  leeres Pflichtfeld ist für den Empfänger schlechter als ein fehlendes: Es sieht nach gepflegter
  Angabe aus und ist keine. Jetzt bleibt das Feld weg, wenn es keinen Hersteller gibt, so wie es
  Shopwares mitgelieferte Vorlage hält.

## [1.4.3] - 2026-08-19: Titel, Beschreibung und Marke kommen aus der Übersetzung

### Behoben

- **Der Warenstrom las an drei Stellen das Rohfeld statt der Übersetzung**, nämlich für Titel, Beschreibung
  und Marke. Das Rohfeld trägt nur dann etwas, wenn die Sprache des ausliefernden Verkaufskanals
  eine eigene Übersetzungszeile mit Inhalt hat; der Rückfall über die Sprachkette liegt allein in
  `translated`. Shopwares mitgelieferte Google-Vorlage macht es genauso, unsere ist daraus
  entstanden und hat das `translated` unterwegs verloren.
- Solange der ausliefernde Verkaufskanal in derselben Sprache läuft wie die Artikelpflege, fiel
  nichts aus. Mit einem zweiten Verkaufskanal oder einer zweiten Sprache läuft der Warenstrom
  weiter, liefert die richtige Zahl an Artikeln, und jeder davon trägt einen leeren Titel. Google weist
  solche Artikel zurück, im Shop selbst ist nichts zu sehen.

## [1.4.2] - 2026-08-10: Die benötigte PHP-Fassung steht jetzt dabei

### Geändert

- **Das Plugin nennt die PHP-Fassung, die es braucht** (ab 8.2). Bisher stand sie als einziges unserer Plugins nicht in den Angaben. Eine Installation auf einer zu alten Umgebung wäre erst zur Laufzeit aufgefallen.

## [1.4.1] - 2026-08-10: Vorbereitet auf die nächste Shopware-Hauptversion

### Geändert

- **Vorbereitung auf die nächste Shopware-Hauptversion.** Der Zugriff auf Suchergebnisse folgt der Schreibweise, die Shopware 6.8 verlangt. Am Verhalten ändert sich nichts.

## [1.4.0] - 2026-08-03: Fünf Wege zu einem falschen Betrag geschlossen

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear && php bin/console rc:shipping:warmup`. Der Warmup ist diesmal **Pflicht**: Die vorberechneten Preise ändern sich.

### Behoben

- **Artikel, die sich nicht in den Warenkorb legen lassen, bekamen den billigsten Versandtarif als gerechneten Preis.** Shopware entfernt solche Positionen, bei Hauptartikeln mit Varianten grundsätzlich, dazu bei Auslaufware ohne Bestand. Das Plugin rechnete danach mit einem leeren Warenkorb weiter: Gewicht und Preis sind dann 0, und jedes Preisband beginnt bei 0. Ein Artikel, dessen Varianten 238 € Versand kosten, bekam so 4,95 €, ohne Kennzeichnung als Ersatzwert und ohne Meldung. Im Bestand des Produktivshops betraf das 313 Artikel. Solche Artikel liefern jetzt **gar keinen** Versandblock: Wir kennen den Preis nicht, und jede Zahl wäre erfunden.
- **Der Warmup erneuerte nichts, was noch gültig war.** Er füllte nur Lücken: Ein Eintrag wurde erst neu gerechnet, nachdem er verfallen war. Bei einem Takt von sechs Stunden und einer Haltbarkeit von 24 hieß das: Der Lauf nach 24 Stunden traf Einträge, die knapp jünger waren, ließ sie stehen, und Minuten später verfielen sie. Bis zum übernächsten Lauf lief der Feed für **jedes** Produkt auf dem Ersatzwert. Der Warmup rechnet jetzt.
- **Steuerfreie Länder bekamen den Bruttopreis.** Für die Schweiz, ein voreingestelltes Land, rechnet der Shop netto ab. Der Feed nannte 20,83 €, wo die Kasse 17,50 € verlangt. Der Steuerzustand wird jetzt am berechneten Warenkorb abgelesen; im Kontext steht er nach der Berechnung nicht mehr, weil Shopware ihn dort zurücksetzt.
- **Bei mehreren passenden Regelpreisen gewann der billigste statt des höchstpriorisierten.** Die Kasse nimmt die Regel mit der höchsten Priorität. Bei „Sperrgut" (49,90 €) neben „Standardkunde" (5,90 €) verlangte die Kasse 49,90 € und der Feed nannte 5,90 €. Innerhalb einer Regel gewinnt weiterhin der günstigere Preis, auch das entspricht der Kasse.
- **Jedes Speichern einer Versandart warf alle vorberechneten Preise weg.** Bis zum nächsten geplanten Lauf (bis zu sechs Stunden) stand danach für jedes Produkt der Ersatzwert im Feed. Eine gewöhnliche Handlung im Verwaltungsbereich stellte damit genau den Zustand her, den die Vorberechnung verhindern soll. Die Preise bleiben jetzt stehen und der Warmup wird stattdessen vorgezogen.
- **Rein versandkostenfreie Warenkörbe** ergeben jetzt 0,00 € statt des billigsten Tarifs.

### Geändert

- Das mitgelieferte Feed-Template holt den Preis je Land **einmal** und merkt ihn, statt `get()` zweimal aufzurufen. Jeder Aufruf zählt in der Zusammenfassung am Feed-Ende mit; der zweite verdoppelte die gemeldete Zahl. Außerdem kommen die Länder jetzt aus der Einstellung statt aus einer festen Liste im Template.
- Die Zusammenfassung nennt nicht bestellbare Artikel als eigene Zahl.

## [1.3.0] - 2026-08-03: Die vorberechneten Versandkosten überleben ein Cache-Leeren

> **Deployment:** `plugin:update` legt die neue Tabelle an. Danach einmal
> `bin/console rc:shipping:warmup` ausführen, vorher stehen im Feed Ersatzwerte.

### Behoben

- **Nach jedem Leeren des Caches nannte der Feed für jeden Artikel den Ersatzwert.** Die
  vorberechneten Versandkosten lagen im Objekt-Cache und verschwanden mit ihm. Bis der Warmup
  wieder lief (er läuft alle sechs Stunden), stand für jede Ware derselbe Betrag im Feed, auch
  für Ware, deren Versand über 200 Euro kostet. Zu niedrig angegebene Versandkosten sind bei
  Google ein Richtlinienverstoß.

  Das Leeren des Caches gehört zu jedem Plugin-Update, jeder Theme-Änderung und jedem
  Aufspielen. Der Zustand trat also planmäßig ein, nicht ausnahmsweise.

  Die Werte stehen jetzt in einer eigenen Tabelle. Sie überstehen das Leeren des Caches, einen
  Neustart und das Aufspielen einer neuen Fassung. Eine Änderung an einer Versandart räumt sie
  weiterhin ab, und ein Eintrag, der älter als 24 Stunden ist, gilt als nicht vorhanden, denn
  Gewicht und Maße eines Artikels ändern sich, ohne dass es jemand meldet.

### Geändert

- Beim Deinstallieren wird die neue Tabelle entfernt, sofern nicht ausdrücklich „Daten behalten"
  gewählt wurde. Ihr Inhalt ist jederzeit neu berechenbar.

### Intern

- Der Speicher hat keine Schema-Version mehr im Schlüssel. Sie war nötig, solange ein
  serialisiertes Objekt abgelegt wurde: Kam ein Feld hinzu, fehlte es in den alten Daten. Mit
  ausgeschriebenen Spalten stellt sich die Frage nicht mehr.
- Erste Integrationstests in diesem Plugin (7 Tests gegen eine echte Datenbank). Der Speicher
  lässt sich nicht sinnvoll gegen einen Doppelgänger prüfen. Seine einzige Zusage ist, dass er
  etwas überlebt.

## [1.2.0] - 2026-07-31: Kein erfundener Versandpreis mehr für Ware, die nicht dorthin geht

> **Deployment:** `plugin:update`, Cache leeren, `rc:shipping:warmup` ausführen. Der Zwischenspeicher wird einmalig neu aufgebaut.

### Neu

- **Einstellung „Wenn für ein Land keine Versandart greift":** Für sperrige oder schwere Ware gibt es in manche Länder schlicht keine Versandart, sie geht nur auf Anfrage. Bisher schrieb der Feed dort trotzdem den Ersatzwert und sagte damit einen Versand zu, den es nicht gibt; zu niedrig angegebene Versandkosten sind bei Google ein Richtlinienverstoß. Neben dem bisherigen Verhalten (Standard, ändert sich nichts von selbst) lässt sich jetzt einstellen, dass der `g:shipping`-Block für dieses Land entfällt. Google greift dann auf die Versandeinstellung des Händlerkontos zurück.
- **`rc:shipping:check` nennt den Grund** je Zeile und zählt ihn am Ende: „keine Versandart", „nicht vorberechnet" oder „Berechnung fehlgeschlagen". Damit ist die Liste der Auf-Anfrage-Artikel direkt ablesbar.

### Behoben

- **`rc:shipping:check` sah nur einen Teil des Sortiments.** Varianten, die ihr „aktiv" vom Elternartikel erben, fehlten in der Prüfung. Sie meldete dadurch weniger als die Hälfte der tatsächlichen Ersatzwerte. Prüfung und Vorberechnung beziehen ihre Produktliste jetzt aus derselben Quelle.
- **Beschreibungstexte in den Plugin-Einstellungen korrigiert:** Sie behaupteten, es werde nie 0,00 € ausgegeben. Liefert der Shop tatsächlich versandkostenfrei, ist 0,00 € die richtige Angabe und wird auch so ausgeliefert.

## [1.1.4] - 2026-07-20: Bugfix: Versandkosten respektieren Preis-Staffeln (Gewicht/Preis/Menge)

> **Deployment:** Feed neu generieren / Cache leeren.

### Behoben

- **Feed-Versandkosten entsprechen jetzt dem echten Checkout-Preis für gestaffelte Versandmethoden:** Hat eine Versandmethode mehrere Preis-Staffeln (z. B. 0–5 kg = 4,95 €, 5–30 kg = 19,95 €), wurde bisher fälschlich das **Minimum über alle Staffeln** in den Feed geschrieben. Ein 15-kg-Produkt bekam so 4,95 € statt der real berechneten 19,95 €. Das untertrieb die Versandkosten und führte zu Google-Shopping-Beanstandungen (Feed-Preis ≠ Checkout-Preis). Die Staffel-Auswahl bildet jetzt exakt `DeliveryCalculator::matches()` des Shopware-Cores nach: Es wird die Staffel gewählt, deren Band (`quantityStart`..`quantityEnd`) den tatsächlichen Warenkorb-Kennwert je Berechnungsart (Gewicht/Preis/Positionsanzahl/Volumen) enthält bzw. deren `calculationRuleId` matcht. Liegt der Wert über allen Bändern, greift wie im Checkout der reguläre Fallback.

## [1.1.3] - 2026-07-20: Feed-Robustheit + Performance

> **Deployment:** Feed neu generieren / Cache leeren.

### Behoben

- **Feed bricht nicht mehr ab, wenn das Plugin deaktiviert ist:** Das Feed-Template rief `rcShipping.get(...)` ohne `is defined`-Guard auf. Der Produkt-Export läuft mit `strict_variables=on`. War das Plugin deaktiviert (oder keine Länder konfiguriert), setzte der Subscriber `rcShipping` bewusst nicht, und jede Produktzeile warf eine Exception → der gesamte Feed schlug fehl. Jetzt wird `is defined` geprüft (deaktiviert = keine `g:shipping`-Blöcke, wie dokumentiert).
- **Produkte ohne Titelbild brechen ihre Feed-Zeile nicht mehr:** `product.cover.media.url` wird jetzt geguardet (`{% if product.cover and product.cover.media %}`).

### Performance

- **N+1 im Export/Warmup beseitigt:** Die aktiven Versandmethoden (pro Sales-Channel) und die Länder-Entitäten (pro ISO) werden jetzt im Speicher memoized statt bei jedem Produkt×Land neu geladen (bei N Produkten × Ländern zuvor 3N redundante Queries).

## [1.1.2] - 2026-06-28: Statische Analyse gehärtet

> **Deployment:** `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`

### Behoben (Typsicherheit, verhaltens-neutral)

- **PHPStan Level 8 sauber** (vorher ~14 Befunde): EntityRepository-Generics in `AbstractShippingCommand` und `ShippingCostCalculatorService` ergänzt; `loadActiveSalesChannels()` liefert nun typkorrekt `SalesChannelEntity`; iterable-Wert-Typen in `ShippingWarmupCommand::warmupProducts()`.
- **Country-Lookup typsicher:** Das Ergebnis von `countryRepository->search()->first()` wird per `instanceof CountryEntity` verengt. Das entfernt die undefinierten `Entity::getId()`/`setCountry()`-Befunde, gleiches Laufzeitverhalten (Nicht-Land → Fallback).
- **Tote Null-/Default-Pfade entfernt:** `getTranslated() ?? []`, `getPrices() === null` und `SHOPWARE_FALLBACK_VERSION ?? 'unknown'` waren nachweislich nicht erreichbar (non-nullable Rückgaben) und sind ohne Verhaltensänderung entfernt.
- **`ShippingContextProvider`** erbt jetzt von `Struct`, wie es der `ProductExportRenderBodyContextEvent::setContext(array<string, Struct>)`-Vertrag erwartet.
- CS-Fixer-Format-Drift bereinigt (13 Dateien).

> **Hinweis:** Die Versandkosten-**Rechenlogik** wurde bewusst nicht angefasst (geld-relevant), es ging ausschließlich um Typsicherheit und Robustheit.

## [1.1.1] - 2026-05-13

> **Deployment:** Reines Test-/Validierungs-Update. Live-Shop benötigt nur `php bin/console plugin:update RcProductFeedShippingExtension && php bin/console cache:clear`.

### Behoben
- **ISO-Code-Validierung war für trailing Newlines löchrig.** `/^[a-zA-Z]{2,3}$/` matchte in PHP-PCRE `"DE\n"`, weil das `$`-Anker standardmäßig einen optionalen Newline am Ende toleriert. Mit `D`-Flag (`/.../D`) ist das jetzt streng: `"DE\n"` wird wie vorgesehen als ungültig erkannt und geloggt. Eine bestehende Datenanbieter-Test-Variante (`newline`) deckte den Bug auf, war aber bisher rot.
- **Test-Helper `ShippingCostCalculatorServiceTest::buildShippingMethod()` rief `setRuleId(null)` auf.** Shopware hat die Signatur in einem 6.7-Update auf `setRuleId(string)` verengt (Getter ist weiter `?string`, asymmetrisch). Helper ruft `setRuleId` jetzt nur noch bei nicht-null auf; semantisch identisch (Default-Regel = ruleId bleibt null).
- **`ProductFeedSubscriberTest` referenzierte die alte Event-Klasse `ProductExportRenderBodyContext`.** Shopware hat sie auf `ProductExportRenderBodyContextEvent` umbenannt (Subscriber im src/ verwendete die neue bereits). Test-Importe und vier Type-Hints/Instanziierungen angepasst.

## [1.1.0] - 2026-05-11

> **Deployment:** `php bin/console cache:clear` (Container-Rebuild für neuen Subscriber). Keine Datenbank-Migration. Bestehende Cache-Einträge werden beim ersten Feed-Request neu berechnet.

### Hinzugefügt
- **`ShippingMethodChangeSubscriber`**: invalidiert den 24h-Cache des Plugins automatisch, sobald Admin Versandmethoden oder Versandpreise ändert. Hört auf `EntityWrittenContainerEvent` und prüft auf `shipping_method`/`shipping_method_price`-Einträge. Bisher mussten Admins entweder `bin/console cache:clear` aufrufen oder bis zu 24 Stunden warten, bis Feed-Aktualisierungen Versandänderungen reflektierten. Drei neue Unit-Tests sichern das Verhalten.
- **ISO-Code-Format-Validierung** in `ShippingContextProvider::get()`: Regex `[a-zA-Z]{2,3}` filtert UUIDs, leere Strings, Whitespace und andere Template-Tippfehler. Ungültige Codes werden mit `logger->warning('… ungültiges ISO-Code-Format ignoriert …')` und `metric: invalid_iso_code` geloggt. Bisher wurde stillschweigend `null` zurückgegeben. Acht neue Datenanbieter-Tests.
- **Strukturierte Metric-Tags** in Logs an drei Stellen: `metric: invalid_iso_code` (Provider-Validierung), `metric: provider_fallback_used` (unerwartete Exception in Provider), `metric: reference_address_missing` (erwarteter Fall im Calculator), `metric: address_provider_error` (unerwarteter Fall im AddressProvider), `metric: calculation_fallback_used` (Top-Level-Fallback). Ops-Teams können damit Hit-/Fallback-Raten ohne Code-Walkthrough auf einem Dashboard aggregieren.

### Geändert
- **Spezifischere Exception-Behandlung** in `ShippingCostCalculatorService::injectShippingLocation()`: separate Catches für erwartete `CountryNotFoundException` (info-Log, regulärer Fallback-Pfad) und unerwartete `\Throwable` (error-Log mit Exception-Klasse). Bisher landete beides im selben Catch-all ohne Differenzierung.
- **Error-Log im Calculator-Top-Level-Catch** trägt jetzt `exception`-Feld (Klasse), `message`-Feld und `metric: calculation_fallback_used`, bisher nur `error: $e->getMessage()` ohne Klasse.

## [1.0.0] – 2026-04-15

> **Deployment:** `php bin/console cache:clear` (Erstinstallation).

### Hinzugefügt
- Versandkosten-Berechnung pro Produkt und Land
- Google Shopping Feed Integration
- Cache-System: `rc_shipping_{productId}_{countryIso}_{salesChannelId}`
- 11 Feature-Implementierungen abgeschlossen
- Shopware 6.7+ Kompatibilität
