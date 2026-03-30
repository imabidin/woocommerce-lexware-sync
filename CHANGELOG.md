# Changelog

## [1.4.0] - 2026-03-30

### Added
- **Migration Cutoff-Datum**: Neues Setting unter "Migration (Germanized)" — Bestellungen nach diesem Datum werden nicht mehr via Lexware erstellt (Germanized Pro zustaendig)
- **Cutoff-Check fuer alle Dokument-Typen**: Rechnungen, Gutschriften und Auftragsbestaetigungen pruefen das Cutoff-Datum (Basis: Bestelldatum/date_created)
- **Germanized Bridge-Filter**: Integrierter `storeabill_woo_auto_sync_order_invoices` Filter verhindert doppelte Rechnungen — blockiert Germanized fuer Pre-Cutoff-Bestellungen und bei bereits vorhandenen Lexware-Dokumenten
- **Detailliertes Migration-Logging**: INFO-Level Logs fuer uebersprungene Bestellungen und Bridge-Entscheidungen

### Technical
- Neue private Methode `is_past_cutoff()` — zentraler Cutoff-Check fuer alle 4 Entry Points
- Neue public Methode `bridge_block_germanized()` — StoreaBill Filter Callback
- Bridge wird nur registriert wenn Cutoff-Datum gesetzt ist (zero overhead ohne Migration)

## [1.1.2] - 2025-12-13

### Added
- 🐛 **Erweiterte Debug-Details**: Action Scheduler ID wird jetzt in Response angezeigt
- 📊 **Status-Logs**: Detaillierte Console Logs für Action Scheduler Status
- ⏰ **Auto-Reload**: Seite lädt nach 5 Sekunden automatisch neu um Fortschritt zu zeigen
- 📝 **Benutzer-Tracking**: Order Notes zeigen welcher Admin die Rechnung erstellt hat

### Changed
- ✅ **Verbesserte Success-Message**: Zeigt Action Scheduler ID und Zeitschätzung
- 🔄 **Smart Reload**: 5 Sekunden Countdown bevor Seite neu lädt
- 📋 **Mehr Logging**: Backend loggt jetzt Action Scheduler Method und Task-ID

### Technical
- Action Scheduler Task-ID wird gespeichert und geloggt
- Scheduler-Methode (async/scheduled) wird getrackt
- User-Login wird in Order Notes gespeichert
- Timestamp für alle Scheduler-Aktionen

## [1.1.1] - 2025-12-13

### Changed
- 🐛 **Debugging**: Auto-Reload nach manueller Rechnungserstellung deaktiviert für besseres Debugging
- ✅ Zusätzlicher Console Log: "✅ SUCCESS - Rechnung wird erstellt!" bei erfolgreicher Erstellung

## [1.1.0] - 2025-12-13

### Added
- ✨ **Manuelle Rechnungserstellung**: Neuer Button "📝 Rechnung jetzt erstellen" für Bestellungen im richtigen Status ohne Rechnung
- 🐛 **Console Debugging**: Umfassende Browser Console Logs für alle AJAX-Aktionen
- 📊 **Status-Prüfung**: Automatische Validierung ob Bestellung im konfigurierten Invoice Trigger Status ist
- 🔒 **Sicherheit**: Vollständige Nonce-Validierung und Capability-Checks
- ℹ️ **Info-Boxen**: Hilfreiche UI-Hinweise zeigen aktuellen Status

---

**Semantic Versioning**: MAJOR.MINOR.PATCH
