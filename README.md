# Business Calc – Einkaufslisten-Webapp

Eine einfache PHP-Webapp zur Verwaltung von:
- **Zutaten** mit Preis, Einheit, Lager- und Mindestbestand
- **Gerichten/Getränken** mit Ziel- und Ist-Bestand
- **Rezepten** (welche Zutaten wie oft pro Gericht benötigt werden)
- **Automatischer Einkaufsliste**, wenn etwas fehlt

## Features

1. **Zutaten anlegen**
   - Name, Preis pro Einheit, Einheit (z. B. kg, l, Stk), Lagerbestand, Mindestbestand
2. **Gerichte/Getränke anlegen**
   - Typ (`gericht`, `getraenk`), Zielbestand, Ist-Bestand
   - Optionaler Direkt-Einkaufspreis (für fertige Produkte ohne Zutaten)
3. **Rezept je Gericht pflegen**
   - Zutaten und Menge pro Produkt hinterlegen
4. **Automatische Einkaufsliste**
   - Berechnet fehlende Zutaten auf Basis von Zielbestand - Ist-Bestand
   - Berücksichtigt Mindestbestand der Zutaten
   - Führt direkt einzukaufende Produkte (ohne Rezept) separat auf

## Setup lokal

1. PHP 8.1+ und MySQL/MariaDB starten.
2. Konfiguration anlegen:

```bash
cp config/config.example.php config/config.php
```

3. Zugangsdaten in `config/config.php` eintragen.
4. App starten:

```bash
php -S 127.0.0.1:8080 -t public
```

5. Im Browser öffnen: `http://127.0.0.1:8080`

> Die Datenbanktabellen werden beim ersten Aufruf automatisch angelegt.

## Deployment bei All-Inkl

1. Dateien per FTP in den Webspace hochladen.
2. `config/config.php` mit deiner All-Inkl-Datenbank konfigurieren.
3. Stelle sicher, dass der DocumentRoot auf den Ordner `public/` zeigt.
4. Falls kein eigener DocumentRoot möglich ist: `public/index.php` in Webroot legen und die Pfade zu `src/` und `config/` anpassen.

## Hinweis

Für den Produktivbetrieb kannst du als nächsten Schritt ergänzen:
- Login/Benutzerverwaltung
- Export als PDF/CSV
- Einkauf abhaken & Lagerbestand automatisch erhöhen
- Historie (wann was eingekauft wurde)
