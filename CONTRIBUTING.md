# Mitmachen bei WooCommerce Lexware Sync

Danke für dein Interesse an diesem Projekt!

## Wie du beitragen kannst

### Bug Reports

1. Prüfe ob das Issue bereits existiert
2. Erstelle ein neues Issue mit:
   - WordPress + WooCommerce Version
   - PHP Version
   - Fehlerbeschreibung + Schritte zur Reproduktion
   - Relevante Log-Einträge (`WooCommerce > Status > Logs > wc-lexware-mvp`)

### Feature Requests

1. Erstelle ein Issue mit dem Label "enhancement"
2. Beschreibe den Use Case und warum das Feature hilfreich wäre

### Pull Requests

1. Forke das Repository
2. Erstelle einen Feature Branch (`git checkout -b feature/mein-feature`)
3. Committe deine Änderungen (`git commit -m 'Add mein feature'`)
4. Push zum Branch (`git push origin feature/mein-feature`)
5. Erstelle einen Pull Request

### Code Style

- PHP: WordPress Coding Standards
- Kommentare auf Englisch
- DocBlocks für alle public/protected Methoden
- Keine Core-Dateien von WordPress/WooCommerce ändern

### Testen

- Teste auf einer Staging-Umgebung (das Plugin deaktiviert sich automatisch auf Staging)
- Prüfe die WooCommerce Logs nach Fehlern
- Teste mit verschiedenen Order-Status-Kombinationen

## Code of Conduct

Sei respektvoll und konstruktiv. Wir sind alle hier um voneinander zu lernen.
