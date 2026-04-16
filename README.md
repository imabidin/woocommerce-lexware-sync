# WooCommerce Lexware Sync

Automatische Rechnungs- und Gutschrifterstellung für WooCommerce mit der Lexware Office (lexoffice) API.

> **Hinweis:** Dieses Plugin ist als MVP (Minimum Viable Product) gestartet und befindet sich in aktiver Weiterentwicklung. Die Kernfunktionen sind produktionsreif und werden bereits im Live-Betrieb eingesetzt. Ziel ist es, das Plugin zu einer vollständigen, community-getriebenen WooCommerce-Lexware-Integration auszubauen. Contributions sind herzlich willkommen!

## Features

- Automatische Rechnungserstellung bei konfigurierbaren Order-Status
- Automatische Gutschriften bei Refunds (Teil- und Voll-Erstattungen)
- Auftragsbestätigungen mit konfigurierbarem Trigger
- PDF-Download für Kunden im "Mein Konto"-Bereich
- E-Mail-Versand von Rechnungen, Gutschriften und Auftragsbestätigungen
- Rate Limiting (Token Bucket) für API-Compliance
- Circuit Breaker Pattern für Ausfallsicherheit
- Idempotency Keys zur Vermeidung von Duplikaten
- HPOS (High-Performance Order Storage) kompatibel
- Staging-Erkennung: Automatische Deaktivierung auf Nicht-Produktionsumgebungen
- Migration-Support: Cutoff-Datum für Umstellung auf Germanized Pro
- REST API Endpoint für Dokument-Abfragen

## Voraussetzungen

- WordPress 6.0+
- WooCommerce 8.0+
- PHP 7.4+
- Lexware Office API Token

## Installation

1. Plugin als ZIP herunterladen
2. WordPress Admin > Plugins > Installieren > Plugin hochladen
3. Plugin aktivieren
4. WooCommerce > Einstellungen > Lexware MVP > API-Token eintragen

## Konfiguration

### Rechnungen
- Auslöser-Status konfigurierbar (Standard: "Abgeschlossen")
- Automatische Finalisierung optional
- E-Mail-Versand optional

### Gutschriften
- Automatisch bei WooCommerce Refunds
- Unterstützt Teil- und Voll-Gutschriften
- Voll-Gutschrift auch ohne WC-Refund (bei Status "Erstattet")

### Auftragsbestätigungen
- Separater Trigger-Status konfigurierbar
- Unabhängig von Rechnungserstellung

### Payment Mapping
- WooCommerce Zahlungsmethoden auf Lexware Zahlungsbedingungen mappen
- Automatischer Abruf der verfügbaren Lexware Zahlungsbedingungen

## Architektur

```
WooCommerce Order Status Change
        |
        v
    Order Sync (Hook)
        |
        v
    Duplikat-Prüfung (DB)
        |
        v
    Rate Limiter (Token Bucket)
        |
        v
    Circuit Breaker
        |
        v
    Lexware Office API
        |
        v
    Dokument-Eintrag (DB)
        |
        v
    E-Mail + PDF
```

## REST API

```
GET /wp-json/wc-lexware-mvp/v1/documents
GET /wp-json/wc-lexware-mvp/v1/documents?order_id=123
GET /wp-json/wc-lexware-mvp/v1/documents?document_type=invoice
```

Authentifizierung über WooCommerce Consumer Key/Secret (Basic Auth).

## Datenbank

Das Plugin erstellt eine eigene Tabelle `{prefix}_lexware_mvp_documents` zur Nachverfolgung aller erstellten Dokumente.

## Entwicklung

### Staging-Erkennung

Auf Staging-Umgebungen wird die Dokumentenerstellung automatisch deaktiviert. Erkannt werden:
- `wp_get_environment_type()` = staging / development / local
- Kinsta Staging URLs (`stg-*.kinsta.cloud`)
- Lokale Domains (`.local`, `.test`, `.dev`)

### Migration (Germanized Pro)

Für die Umstellung auf Germanized Pro gibt es ein Cutoff-Datum Setting. Bestellungen nach dem Cutoff werden übersprungen. Ein integrierter Bridge-Filter verhindert doppelte Rechnungen während der Übergangsphase.

## Mitmachen

Contributions sind willkommen! Siehe [CONTRIBUTING.md](CONTRIBUTING.md) für Details.

## Autor & Copyright

Entwickelt von Abidin Alkilinc für [badspiegel.de](https://www.badspiegel.de) — Badspiegel und Spiegelschränke nach Maß.

Copyright (c) 2025-2026 Abidin Alkilinc. Alle Rechte vorbehalten.

## Lizenz

Dieses Plugin ist unter der GPL-2.0+ Lizenz veröffentlicht. Du darfst es frei verwenden und einsetzen. Wenn du Verbesserungen vornimmst, trage sie bitte per Pull Request zum Original-Projekt bei, anstatt einen eigenen Fork zu pflegen — so profitiert die gesamte Community. Siehe [LICENSE](LICENSE) für Details.
