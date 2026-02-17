# HAFO Liga-Manager Online (LMO26)

## Projektbeschreibung

Webbasiertes Liga-Management-System fuer Amateurfussball mit Spielverwaltung, Tabellen, News/Spielberichten und Forum.

## Technologie-Stack

| Komponente | Technologie |
|------------|-------------|
| Backend | PHP 8.x |
| Datenbank | SQLite (`/data/database.sqlite`) |
| Frontend | Vanilla JavaScript, CSS3 |
| Auth | Session-basiert mit CSRF-Schutz |

## Verzeichnisstruktur

```
lmo26/
├── admin/              # Admin-Oberflaeche
│   ├── index.html      # Dashboard (Teams, User, Korrekturen)
│   ├── edit.html       # Liga-Editor (Ergebnisse)
│   ├── news.html       # News-Verwaltung
│   ├── forum.html      # Forum-Moderation
│   ├── design.html     # Design-Editor
│   └── anleitung.html  # Dokumentation
├── api/                # REST API Endpoints
│   ├── login.php       # Authentifizierung
│   ├── check-auth.php  # Session-Pruefung
│   ├── get-league-data.php
│   ├── save-matchday.php
│   ├── news-admin.php  # News CRUD
│   ├── forum-*.php     # Forum APIs
│   └── ...
├── lib/                # PHP Klassen
│   ├── LmoDatabase.php # DB-Verbindung & Schema
│   ├── LmoRepository.php # Data Access Layer
│   ├── LmoParser.php   # L98-Datei Parser
│   ├── LmoWriter.php   # L98-Datei Writer
│   ├── ForumRepository.php
│   ├── NewsReader.php
│   └── Security.php    # Auth, CSRF, Rate Limiting
├── config/
│   ├── auth.php        # Admin-Credentials (NICHT committen!)
│   └── auth.example.php
├── data/
│   ├── database.sqlite # Hauptdatenbank
│   ├── security.db     # Rate Limiting
│   └── cache/          # API Cache (JSON)
├── css/
├── js/
├── icons/              # Team-Logos
└── index.html          # Oeffentliche Startseite
```

## Datenbank-Schema

### Haupttabellen

```sql
leagues (id, file, name, options)
teams (id, league_id, original_id, name, short_name, logo_file)
matches (id, league_id, round_nr, home_team_id, guest_team_id,
         home_goals, guest_goals, match_date, match_time, match_note, report_url)
news (id, title, short_content, content, author, email, timestamp, match_date)
match_news (match_id, news_id, confidence)
users (id, username, password_hash, display_name, email, role, active)
point_corrections (id, league_id, team_id, points, reason)
```

### Forum-Tabellen

```sql
forum_categories (id, name, description, sort_order, read_permission, write_permission, league_id)
forum_topics (id, category_id, user_id, title, is_sticky, is_locked, view_count,
              post_count, league_id, round_nr, match_id, topic_type)
forum_posts (id, topic_id, user_id, guest_name, content, is_first_post)
match_forum_links (id, forum_topic_id, league_id, round_nr, match_id, link_type)
```

## Benutzerrollen

| Rolle | Level | Berechtigung |
|-------|-------|--------------|
| guest | 0 | Nur lesen |
| user | 1 | Forum schreiben |
| editor | 2 | News, Ergebnisse bearbeiten |
| admin | 3 | Vollzugriff |

## API-Konventionen

### Authentifizierung
- Session-Cookie: `PHPSESSID`
- CSRF-Token in jedem POST-Request: `csrf_token`
- Session-Variablen: `$_SESSION['lmo26_admin']`, `lmo26_user_role`, etc.

### Response-Format
```json
{
  "success": true,
  "data": { ... },
  "csrf_token": "..."
}
```

### Fehler
```json
{
  "success": false,
  "error": "Fehlermeldung"
}
```

## Wichtige Dateien

### Security.php - Authentifizierung
```php
Security::initSession();           // Session starten
Security::isLoggedIn();            // Login-Status
Security::requirePermission('editor'); // Berechtigung pruefen
Security::verifyCsrfToken($token); // CSRF validieren
```

### LmoDatabase.php - Datenbank
```php
$pdo = LmoDatabase::getInstance(); // Singleton PDO
LmoDatabase::migrateSchema();      // Schema-Updates
```

### LmoRepository.php - Daten
```php
$repo = new LmoRepository();
$repo->getLeagueDataFull($file);   // Komplette Liga-Daten
$repo->getNewsForMatch($matchId);  // News zu Spiel
```

## Login-Credentials

Standard-Admin (in `config/auth.php`):
- Username: `admin`
- Passwort: `hafo`

## Haeufige Aufgaben

### Cache leeren
```bash
del /q data\cache\*.json
```

### Datenbank-Backup
```bash
copy data\database.sqlite data\backup_%date%.sqlite
```

### Neuen Admin anlegen
```php
$pdo = LmoDatabase::getInstance();
$hash = password_hash('neuesPasswort', PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username, password_hash, display_name, role, active)
               VALUES (?, ?, ?, 'admin', 1)")
    ->execute(['benutzername', $hash, 'Anzeigename']);
```

### News-Bereinigung (Sonderzeichen)
```bash
php cleanup-news.php
```

### Match-News Verknuepfung
```bash
php link-match-news.php
```

## Bekannte Eigenheiten

1. **API-Response Format**: Login/Check-Auth liefern User-Daten in `data.user.role`, nicht `data.role`

2. **Rollen-Werte**: In der DB als String ('admin', 'editor', 'user'), nicht als Integer

3. **Legacy .l98 Dateien**: Urspruengliches Format, jetzt in SQLite migriert

4. **FusionNews**: Alte News waren in `/news/news/*.php`, jetzt in SQLite `news` Tabelle

5. **Encoding**: Alle Texte sind UTF-8, Legacy-Daten wurden von ISO-8859-1 konvertiert

## Development

### Lokaler Server
```bash
php -S localhost:8000
```

### PHP-Fehler anzeigen
In der jeweiligen PHP-Datei:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

## Migrations-Scripts

| Script | Funktion |
|--------|----------|
| `migrate-news.php` | FusionNews → SQLite |
| `link-match-news.php` | Spiel-News Verknuepfungen |
| `cleanup-news.php` | Sonderzeichen bereinigen |
| `migrate-forum.php` | SMF Forum → SQLite (noch nicht implementiert) |

## Kontakt

Admin-Anleitung: `/admin/anleitung.html`
