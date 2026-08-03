# Sicherheitsrichtlinie – RcProductFeedShippingExtension

## Unterstützte Versionen

| Version | Shopware | Sicherheits-Updates |
|---------|----------|---------------------|
| 1.x (aktuell) | 6.7, 6.8 | ✓ Ja |
| < 1.0 | – | ✗ Nein |

## Sicherheitslücken melden

Sicherheitslücken bitte **nicht** als öffentliches GitHub-Issue melden.

**Kontakt:** Über das Repository-Kontaktformular oder direkt per E-Mail an ruhrcoder.de.

**Erwartete Reaktionszeit:** Innerhalb von 72 Stunden.

## Was gemeldet werden soll

- Unsichere Verarbeitung von Produktdaten im Feed
- Cache-Poisoning-Anfälligkeiten
- Unberechtigter Zugriff auf Versandkostenberechnungen
- Exponierte sensible Shop-Konfigurationsdaten
- Injection-Anfälligkeiten in Feed-Output

## Was nicht gemeldet werden soll

- Theoretische Angriffe ohne praktische Auswirkung
- Sicherheitslücken in Shopware Core (bitte direkt an Shopware melden)
- Denial-of-Service-Angriffe auf Infrastruktur
- Probleme mit nicht unterstützten Shopware-Versionen

## Reaktionsprozess

1. Bestätigung innerhalb von 72 Stunden
2. Analyse und Bewertung der Schwere
3. Entwicklung eines Fixes
4. Koordinierter Disclosure nach Fix-Deployment

## Shopware Core-Lücken

Sicherheitslücken in Shopware Core bitte direkt an Shopware melden:
https://www.shopware.com/en/news/security/
