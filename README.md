# Liga Manager Online (LMO)

Moderne PHP 8 Neuimplementierung des klassischen Liga Manager Online - ein webbasiertes System zur Verwaltung von Fußball-Ligen.

## Features

- **SQLite-Datenbank** - Keine MySQL-Installation nötig
- **Modernes Admin-Interface** - Dark Mode Design
- **REST API** - Alle Operationen über JSON-API
- **Sicherheit** - CSRF-Schutz, Rate Limiting, Session-Hardening
- **Benutzerverwaltung** - Rollen (Admin/Editor), Passwort-Policy
- **Team-Verwaltung** - Logos, Kurznamen
- **Punktekorrekturen** - Abzüge/Boni mit Begründung
- **News-System** - Integrierte Nachrichtenverwaltung

## Systemanforderungen

- PHP 8.0+
- SQLite3 Extension
- Webserver (Apache/nginx)

## Installation

1. Repository klonen:
```bash
git clone https://github.com/stefanbuell-wq/Liga-Manager-Online.git
```

2. Auf Webserver kopieren

3. `data/` Ordner beschreibbar machen:
```bash
chmod 755 data/
```

4. Admin-Panel öffnen: `http://your-domain/admin/`

5. Standard-Login:
   - Benutzer: `admin`
   - Passwort: `hafo` (bitte sofort ändern!)

## Struktur

```
Liga Manager Online/
├── admin/           # Admin-Interface
│   ├── index.html   # Hauptverwaltung
│   ├── edit.html    # Spieltag-Editor
│   └── news.html    # News-Editor
├── api/             # REST API Endpoints
├── css/             # Stylesheets
├── data/            # SQLite Datenbank (wird auto-erstellt)
├── img/teams/       # Team-Logos
├── js/              # JavaScript
├── lib/             # PHP-Klassen
└── index.html       # Öffentliche Startseite
```

## API Endpoints

| Endpoint | Methode | Beschreibung |
|----------|---------|--------------|
| `/api/login.php` | POST | Authentifizierung |
| `/api/check-auth.php` | GET | Auth-Status prüfen |
| `/api/list-leagues.php` | GET | Alle Ligen auflisten |
| `/api/get-league-data.php` | GET | Liga-Daten abrufen |
| `/api/save-matchday.php` | POST | Spieltag speichern |
| `/api/teams.php` | GET/POST | Team-Verwaltung |
| `/api/corrections.php` | GET/POST | Punktekorrekturen |
| `/api/users.php` | GET/POST | Benutzerverwaltung |
| `/api/news.php` | GET | News abrufen |
| `/api/save-news.php` | POST | News speichern |

## Sicherheit

- **CSRF-Token** bei allen POST-Requests erforderlich
- **Rate Limiting**: 5 Fehlversuche = 15 Min. Sperre
- **Session-Hardening**: HttpOnly, SameSite=Strict, Secure (HTTPS)
- **Passwort-Policy**: Min. 8 Zeichen, Buchstaben + Zahlen
- **bcrypt** Passwort-Hashing

## Migration von alten LMO-Versionen

Das System kann .l98-Dateien aus älteren LMO-Versionen importieren. Kontaktiere uns für Migrations-Support.

## Lizenz

MIT License

## Historie

Ursprünglich entwickelt 2007 für [hafo.de](https://hafo.de) - Hamburger Fußball Online.
Komplett neu geschrieben 2025 für PHP 8 mit modernem Tech-Stack.
