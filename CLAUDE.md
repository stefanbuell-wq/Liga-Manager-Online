# Liga Manager Online (LMO26)

## Projektbeschreibung

Webbasiertes Liga-Management-System fuer Amateurfussball mit Spielverwaltung, Tabellen, News/Spielberichten und Forum.

## Instanzen

Der Code ist neutral; alles, was eine Installation ausmacht, steht in `config/instance.php`
(Vorlage `config/instance.example.php`, gelesen ueber `lib/Instance.php`, oeffentlich ueber `api/theme.php` -> `instance`):
Name/Untertitel (Design-Editor hat Vorrang), Basis-URL, Mail-Kuerzel, Tipp-Beschriftung, Region, Altforum-Hinweis,
Alt-Host fuer Bild-Links, Standardliga und Liga-Kurznamen. Seiten fuellen Name/Copyright ueber `js/instance.js`
(`data-site-logo`, `data-site-name`, `data-site-copyright`, `data-site-region`, `data-legacy-forum`, `<title data-page>`).
Nie Instanznamen (z. B. HAFO) fest in Code oder Texte schreiben.

Instanzspezifische Dateien (Wappen in `img/teams/`, Altbilder, Import-/Migrationsskripte, Rechtstexte, Deployment)
liegen nicht in diesem Repository, sondern im jeweiligen Instanz-Repository (hafo.de: privates Repo `hafo-site`).
Interne Kennungen wie die CSS-Klasse `hafo-banner` oder der localStorage-Schluessel `hafo_theme` bleiben aus
Kompatibilitaet mit gespeicherten Inhalten bestehen.

Neue Installation: `php scripts/install.php --name="..." --url=...` (siehe README).

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
│   ├── news.html       # News-Verwaltung (mit Template-System)
│   ├── kader.html      # Kaderverwaltung (Spieler, Profile, Vereinshistorie)
│   ├── season.html     # Saison-Assistent (neue Saison + Spielplan, nur Admin)
│   ├── forum.html      # Forum-Moderation
│   ├── design.html     # Design-Editor (Theme-Farben)
│   ├── sponsoren.html  # Sponsoren-Banner (nur Admin)
│   ├── anfragen.html   # Kontaktanfragen (nur Admin)
│   └── anleitung.html  # Dokumentation
├── api/                # REST API Endpoints
│   ├── login.php       # Authentifizierung (nur DB-Konten)
│   ├── account.php     # Self-Service: register, verify, request_reset, reset, change_password, update_email
│   ├── users.php       # Benutzerverwaltung (approve, reject, send_reset, ...)
│   ├── season-admin.php # Saison-Assistent
│   ├── check-auth.php  # Session-Pruefung
│   ├── get-league-data.php
│   ├── save-matchday.php
│   ├── news-admin.php  # News CRUD + Match-Verknuepfungen
│   ├── get-players.php # Spieler/Kader API (Profile, history_*, copy_previous[_league])
│   ├── get-player-stats.php
│   ├── forum-*.php     # Forum APIs
│   ├── theme.php       # Design-Einstellungen
│   ├── foerderer.php   # Sponsoren-Banner (oeffentlich + Verwaltung)
│   ├── kontakt.php     # Kontaktformular (oeffentlich senden, Admin verwalten)
│   ├── corrections.php # Punktekorrekturen
│   ├── teams.php       # Team-Verwaltung
│   └── ...
├── lib/                # PHP Klassen
│   ├── LmoDatabase.php # DB-Verbindung & Schema
│   ├── LmoRepository.php # Data Access Layer
│   ├── LmoParser.php   # L98-Datei Parser
│   ├── LmoWriter.php   # L98-Datei Writer
│   ├── ForumRepository.php
│   ├── NewsReader.php
│   ├── Security.php    # Auth, CSRF, Rate Limiting
│   ├── AccountService.php # Registrierung, Mail-Bestaetigung, Freigabe, Reset, Inaktivitaet
│   ├── Mailer.php      # SMTP (config/mail.php), Fallback mail()
│   ├── SponsorRepository.php # Sponsoren-Banner (Header/Seiten/Spielbericht), Upload-Pruefung, Statistik
│   ├── ContactService.php # Kontaktanfragen, Mail-Benachrichtigung, Loeschfristen
│   ├── AvatarService.php # Profilbilder: Upload (GD, 160 px), Wappen, Altforum-Import (data/legacy-avatars.json)
│   ├── ForumComfort.php # Forum: Ungelesen (forum_reads/forum_user_state), Abos + Mail (forum_subscriptions), Abmeldelink api/forum-abo.php
│   ├── BriefingService.php # Taegliches Briefing (Empfaenger/Bereiche in app_settings briefing_*)
│   ├── NewsContent.php # Anzeige-Aufbereitung: Altbilder->legacy/, tote Wappen-Hosts, Autor, Vorschau, Banner-Katalog
│   └── PlayerRepository.php # Spielerprofile, Historie, Kader aus Vorsaison
├── cron/               # Nur CLI (per .htaccess gesperrt)
│   ├── create-admin.php
│   ├── deactivate-inactive.php
│   └── daily-briefing.php # Plesk Geplante Aufgabe taeglich 07:05 (--dry-run, --force)
├── deploy/             # deploy.ps1 (SSH, ungenutzt), CHECKLISTE.md
├── kontakt.html        # Kontaktformular (?thema=sponsoring|allgemein|fehler)
├── konto.html          # Anmelden, Registrieren, Passwort vergessen/setzen, Mein Konto
├── config/
│   ├── mail.php        # SMTP-Zugang (NICHT committen!)
│   └── mail.example.php
├── data/
│   ├── database.sqlite # Hauptdatenbank
│   ├── security.db     # Rate Limiting
│   └── cache/          # API Cache (JSON)
├── css/
├── js/
├── icons/              # Team-Logos
├── legacy/             # Altbilder einer frueheren Website (gitignored, Instanz-Repo)
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
users (id, username, password_hash, display_name, email, role, active,
       status, email_verified_at, legacy_claim, status_changed_at, last_login)
       -- status: pending_email | pending_approval | active | rejected | inactive | disabled
auth_tokens (Token nur als SHA-256-Hash; Mail-Bestaetigung 48 h, Reset 1 h)
players (..., birth_date, nationality, joined_at, foot, height_cm, notes, external_url,
         source, source_updated_at, manual_fields, updated_at)
player_history (id, player_id, club, from_date, to_date, note, source, manual, created_at)
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

- Altkonten aus dem SMF-Forum (~1.267) haben `status='disabled'`, `active=0`; Beitraege bleiben erhalten.
  Beim Registrieren kann ein alter Forenname uebernommen werden (Admin-Freigabe kopiert Zugang ins Altkonto).
- Neue `user`-Konten werden nach 365 Tagen ohne Login automatisch `inactive` (taeglich beim Aufruf von users.php bzw. per Cron).

## Sicherheitsregeln (seit Audit 12.11.2026, Bericht: ~/security-audit-skill/lmo26/run-1/REPORT.md)

- `Security::initSession()` gleicht bei jeder Anfrage Rolle/active/status aus `users` ab (`syncAuthFromDb`); herabgestufte, gesperrte oder geloeschte Konten verlieren ihre Rechte sofort. Nie Rollen nur aus der Session vertrauen.
- Passwort-Aenderungen immer mit `Security::markAuthChanged($pdo, $userId)` abschliessen (setzt `users.auth_changed_at`, beendet andere Sitzungen des Kontos).
- `Security::getClientIp()` liefert nur `REMOTE_ADDR` (kein X-Forwarded-For). Login-Drossel: IP+Benutzer (5) und zusaetzlich pro Konto (`acct:<name>`, 20).
- `requireCsrf()` prueft alle Methoden ausser GET/HEAD/OPTIONS; neue schreibende APIs: `initSession()`, Rollenpruefung, `requireCsrf()`.
- Ausgabe im Browser: Werte aus DB/API immer kodieren (`sqEsc`/`esc` in index.html, `leagueEsc`/`uEsc` in admin/index.html, `eHtml` in admin/edit.html, `escapeHtml` in forum.js); in `onclick`-Attributen `escapeJs` (news.html, JS+HTML-kodiert) oder `JSON.stringify` + HTML-Kodierung. CSP erlaubt `'unsafe-inline'` und schuetzt nicht.
- Forum-Suche/Sortierung (`ForumRepository::listTopics`, `getPostsByTopic`): Suchbegriffe nur ueber `searchWords()` + Parameter, nie ins SQL; Suche nur ueber `getCategories($userRole)`-sichtbare Kategorien. Beitraege standardmaessig neueste zuerst, Eroeffnungsbeitrag nicht mehr angeheftet (seit 16.11.2026, Link "Zum Eroeffnungsbeitrag" -> letzte Seite)
- Forum: Gastbeitraege (`user_id` NULL) gehoeren niemandem (strikter Besitzvergleich); Links in Beitraegen nur http/https/mailto (`isSafeUrl` in forum.js).
- Wartungs-/Migrationsskripte beginnen mit `if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }`; `data/`, `config/`, `lib/`, `scripts/`, `cron/` haben je eine versionierte `.htaccess` mit `Require all denied`.
- `api/img-proxy.php` folgt Weiterleitungen nur zu erlaubten Hosts und liefert nur per `getimagesizefromstring` erkannte Rasterbilder.

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
Security::isLoggedIn();            // Redaktion (editor/admin) – NICHT nur "angemeldet"
Security::isMember();              // jedes angemeldete Konto (Forum)
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

Keine Standard-Zugangsdaten. Login nur ueber DB-Konten (`api/login.php`, Seite `konto.html`).
- `lmo26_auth` = jedes angemeldete Konto (`Security::isMember()`), `lmo26_admin` nur Rolle editor/admin (`Security::isLoggedIn()`).
- Forennutzer registrieren sich selbst (E-Mail-Bestaetigung + Freigabe im Admin unter Benutzer).
- Passwort vergessen per Mail: ohne `config/mail.php` per PHP `mail()` (funktioniert bei den meisten Hostern); SMTP optional (Vorlage `config/mail.example.php`).
- Angemeldet bleiben: `Security::SESSION_LIFETIME` = 24 h ohne Aktivitaet, Cookie ueberlebt Browser-Neustart und wird bei Aktivitaet stuendlich verlaengert (seit 13.11.2026, 23bc5a0). Sitzungsdateien in `data/sessions/` (0700, gitignored) – im Server-Standardordner raeumt der Server nach 24 min auf. `session_timeout` in `config/auth.php` wird nicht benutzt.
- Login-Sperre: 5 Fehlversuche → 15 min (`MAX_LOGIN_ATTEMPTS`, `LOCKOUT_TIME`, security.db); danach wieder 5 Versuche (`checkRateLimit(..., true)` nur im Login, andere Aufrufer behalten das Stundenbudget)

## Haeufige Aufgaben

### Cache leeren
```bash
del /q data\cache\*.json
```

### Datenbank-Backup
```bash
copy data\database.sqlite data\backup_%date%.sqlite
```

### Vereinswappen (img/teams/)
- `img/teams/<Name ohne Sonderzeichen>.gif`; `img/teams/.htaccess` liefert `hd/X.png`, wenn vorhanden (Code und Berichts-HTML bleiben bei `.gif`)
- Wappen gehoeren zur Instanz (nicht im Repository, ausser `blank.png`); Bild-Fallbacks immer mit `this.onerror=null`
- Beim Einbinden ohne feste Groesse auf max. Groesse achten (HD = 160 px), z. B. `.news-body img[src*="img/teams/"]`

### Neuen Admin anlegen / Admin-Passwort zuruecksetzen
Admin-Konto anlegen oder Passwort neu setzen (nur per SSH/hPanel-Terminal, im Ordner `public_html`):
```bash
php -d disable_functions= cron/create-admin.php "Benutzername"
```
Das Passwort wird verdeckt abgefragt (beim Tippen erscheint nichts). `-d disable_functions=` ist noetig, wenn der Hoster `system()` gesperrt ist.
Vorher DB sichern: `php -r '$p=new PDO("sqlite:data/database.sqlite");$p->exec("VACUUM INTO ".$p->quote(getenv("HOME")."/backups/database-pre-x.sqlite"));'`

### Spiele und Berichte verknuepfen
- Spielplan-📰 (`LmoRepository::getMatchIdsWithNews`): Spielbericht hat Vorrang vor Vorschau, dann hoechste Confidence, dann neuester

## News-Anzeige (api/get-news.php, lib/NewsContent.php)

- Inhalte in der DB bleiben unveraendert; Aufbereitung nur bei der Ausgabe (`NewsContent::prepare`)
- Altbilder (`../images/`, `<legacy_host>/news/uploads/` ...) -> `legacy/<alter Pfad>`; fehlt die Datei, wird das Bild weggelassen
- Wappen von static.v-manager.de / dfd-systems -> vereinswappen.de ueber `api/img-proxy.php` (Dateicache `data/cache/img/`, 30 Tage)
- Wappen werden nur ergaenzt, wenn ein Spielbericht im Kopf gar kein Bild hat (lokale `img/teams/*.gif`)
- Autor "Redakteur"/"Redaktion": Name aus der fett zentrierten ersten Zeile (Sammelkonto im Altsystem, z. B. Stefan Knauss)
- Liste: `?list=1&limit&offset&league=<datei>&q=<suche>&kind=bericht|vorschau|news&year=<JJJJ>` liefert `total`, `kind` (Spielbericht/Vorschau/News), `preview`, `logos`, `league_name`; Liga-Filter = ueber `match_news` verknuepfte Spiele
- `email` wird nie ausgegeben; Artikel-HTML laeuft durch `NewsContent::sanitize` (Allowlist, keine Skripte/Event-Handler, Bilder nur lokal/Proxy)
- Startseite: alle Artikel chronologisch unter dem Aufmacher (10er-Schritte), Direktlink `/?news=ID`, Ligaknoepfe fuer die aktuelle Saison, Archiv nach Saison gruppiert (`?liga=<datei>`)
- Spiele im Tab "Aktuell" als kompakte Zeile `matchRow(m)` in index.html: Heim [Wappen] 5:2 [Wappen] Gast · Datum kurz · 📰/🔭; CSS `.mlist`/`.mrow` in portal.css, lange Namen zweizeilig, mobil Datum unter der Zeile (Feedback 12.11.: alles auf einen Blick wie im Spielplan). Die fruehere Spieltag-Leiste oben (`#live-section`) wurde entfernt (doppelt); Menue "Spiele" -> Spielplan
- Tabelle neben "Aktuell" (`renderTable`, `#league-table.compact`): alle Plaetze, kompakt, Namen einzeilig mit Tooltip
- Vereinsnamen (Tabelle, Spielplan, Spieltag) sind `teamLink(name)` -> `showTeamSeason(name)`: Modal mit allen Partien der Saison (Spieltag, Datum, Ergebnis, S/U/N, 📰/🔭), Kopf mit Platz/Bilanz, Knopf "Kader anzeigen" (`showTeamSquad`)
- `newsButton(m)`: 📰 nur bei Spielbericht, 🔭 wenn das Spiel nur mit einer Vorschau verknuepft ist (`news_is_preview` aus `LmoRepository::getMatchIdsWithNews`); gilt auch im Spielplan
- Artikel-Modal: `resetModalScroll()` nach dem Einblenden (neuer Artikel beginnt oben), Sticky-X, Knopf "Schliessen" am Ende
- Suche/Filter ueber "Alle Berichte" (`initNewsFilter`, `loadNews` in index.html): Suchfeld (500 ms nach Tippen), Knoepfe Alle/Spielberichte/Vorschauen/News, Jahr 2003–heute, Trefferzahl + "Filter zuruecksetzen"; Aufmacher (Hero) nur ungefiltert
  - `q`: FTS5 `news_fts` (Titel, Kurztext, Text, Autor), jedes Wort (>= 2 Zeichen, max. 6) muss vorkommen, Wortanfang genuegt
  - `kind`: per `sqliteCreateFunction('news_kind')` dieselbe Regel wie das Etikett (`NewsContent::kind`): Titel "Vorschau…" -> Vorschau; verknuepft oder Ergebniszeile -> Spielbericht; sonst News
  - `year`: nach `timestamp` (Erscheinungsjahr)
- Vorschauen: ab 2 Paarungen `<b>A vs. B</b>` setzt `NewsContent::separateMatches` vor jede weitere Paarung `<hr class="match-sep">` und markiert die Zeile `.match-head` (CSS in portal.css); der Editor erzeugt dasselbe fuer neue Vorschauen

## Release Notes / Neuigkeiten (RELEASE-NOTES.md, admin/release-notes.html)

- Quelle ist `RELEASE-NOTES.md` im Projektroot (per .htaccess nicht direkt abrufbar); `api/release-notes.php` liefert sie nur an Redaktion/Admin (`isLoggedIn`)
- `admin/release-notes.html` (Menue "🆕 Neuigkeiten") rendert sie mit kleinem Markdown-Renderer: `## <Datum> – <Titel>` = Karte, `> ` = gelber Hinweiskasten, `- ` / `  - ` = Listen (2 Ebenen), `### Technik` bis Eintragsende wird eingeklappt
- Nach jedem Deploy oben einen Eintrag ergaenzen und `RELEASE-NOTES.md` mit hochladen

## Gesamtsuche (suche.html, api/search.php)

- Seite `suche.html?q=...` (Navigation "🔍 Suche" in index/forum/kontakt/konto); Ergebnisse nach Bereichen, je "Weitere … anzeigen"
- API: `api/search.php?q=` (alle Bereiche, je Vorschau-Anzahl) bzw. `&type=vereine|spiele|ligen|spieler|berichte|forum&offset&limit`
- Vereine/Ligen/Spieler werden in PHP verglichen (`norm()`: klein, Umlaute ausgeschrieben); Vereinswort = Wortanfang im Teamnamen
- Spiele: jedes Vereinswort muss Heim oder Gast treffen ("Paloma Dassendorf" = alle Duelle); Jahr filtert Datum, Saison "15/16" bzw. Liga-Woerter die Liga; gespielte zuerst (neueste), dann kommende; `news_id` bevorzugt Spielbericht
- Berichte: `news_fts`; Forum: `forum_fts` (Beitraege) + Thementitel, **nur `view_permission = 'public'`** (oeffentliche Bereiche)
- `forum_fts` + Trigger einmalig anlegen: `php scripts/build-search-index.php` (~7 s, baut bei erneutem Aufruf neu auf); fehlt der Index, bleibt der Forum-Bereich leer

## News Template-System (admin/news.html)

Der News-Editor hat 4 Tabs (neuer Bericht startet im Spielbericht-Tab, Autor = angemeldeter Name):

| Tab | Zweck |
|-----|-------|
| Spielbericht | Strukturierte Felder; Teamwahl verknuepft das Spiel und fuellt Spieltag/Datum/Ergebnis |
| Vorschau | Spieltags-Vorschau; alle Spiele werden beim Speichern verknuepft |
| Freier Text (z. B. aus Word) | Einfacher Text mit *fett*, _kursiv_, ~unterstrichen~, [Link](url) |
| HTML (fuer Profis) | Direktes HTML; aeltere Berichte oeffnen hier |

- Einfuegen aus Word (alle Textfelder ausser HTML/Kurztext): Absaetze bleiben, Fett/Kursiv -> `*...*`/`_..._` (`wordHtmlToMarkup`)
- Sponsoren-Banner: Auswahl aus den Bannern der letzten 3 Jahre (`news-admin.php?action=banners`), Vorschlag nach Verein; eingesetzt als `<center class="hafo-banner">`
- Speichern leert `data/cache/*.json`, damit 📰 im Spielplan sofort erscheint
- Redakteure sehen im Admin nur ihre Menuepunkte (`data-admin-only` in admin/index.html)
- Kurzanleitung fuer Redakteure: `admin/anleitung.html#kurzanleitung`

### Spielbericht-Tab Felder
Liga, Spieltag, Heim/Gast-Team (Dropdowns), Ergebnis, Halbzeit, Aufstellungen (mit Spieler-Checkboxen aus Kader), Tore, Gelb-Rot, Schiedsrichter, Beste Spieler, Zuschauer, Berichtstext, Stimmen zum Spiel (Repeater), Statistik.

### Reverse-Parser (parseMatchHTML)
Beim Editieren einer bestehenden News wird automatisch geprueft, ob der Content ein Spielbericht ist (Score-Zeile `<span><b>Team – Team X:Y</b></span>`). Falls ja:
- Felder werden aus dem HTML extrahiert und im Spielbericht-Tab angezeigt
- Liga/Teams werden automatisch erkannt (ueber Match-Verknuepfung oder Team-Suche)
- Tab-Wechsel-Warnung wird unterdrueckt

Spieltagsvorschauen (`<b class="match-head">`) liest `parsePreviewHTML()` zurueck in den Vorschau-Tab (Gegenstueck zu `generatePreviewHTML()`, Round-Trip identisch); sonst wuerde Speichern aus dem Vorschau-Tab vorhandene Spiele ueberschreiben.

### HTML-Generierung (generateMatchHTML)
Erzeugt aus den Spielbericht-Feldern ein festes HTML-Schema:
1. Logo-Block (`<center><img> vs. <img></center>`)
2. Score-Zeile (`<span><b>Home – Guest X:Y (HX:HY)</b></span>`)
3. Aufstellungen (`<b>TeamName:</b> Spielerliste`)
4. Labeled Fields (`<b>Tore:</b>`, `<b>Schiedsrichter:</b>`, etc.)
5. Berichtstext (Freitext mit `<br>` fuer Zeilenumbrueche)
6. Stimmen (`<b>Stimmen zum Spiel:</b>` + `<u>Name:</u>` Bloecke)
7. Statistik (Freitext)

## Kaderverwaltung (admin/kader.html)

Spieler pro Team verwalten:
- Liga waehlen → Team-Karten mit Logos und Spieleranzahl
- Spieler hinzufuegen: Trikotnummer, Name, Position (TW/AB/MF/ST)
- Spieler bearbeiten/loeschen
- Kader wird fuer Aufstellungs-Checkboxen im Spielbericht-Tab genutzt
- Profil: Geburtsdatum, Nationalitaet, im Verein seit, Fuss, Groesse, Link, Notiz + Vereinshistorie
- Oeffentlich (Startseite, Klick auf Team) nur Alter/Geburtsjahr, keine Notizen
- Manuell gepflegte Felder werden von Importen (`importProfile`) nicht ueberschrieben
- "Aus Vorsaison uebernehmen" pro Team oder ganze Liga (Namensvergleich, keine Dubletten)

## Sponsoren-Banner (admin/sponsoren.html)

- Positionen: Header 728x90 (+ optional Mobil 320x50), links/rechts 160x600 (+ optional Kompakt 300x250); jeweils auch 2x erlaubt, andere Groessen werden abgelehnt
- Mehrere aktive Banner je Position rotieren alle 9 s (Gewichtung 1-10); Laufzeit von/bis, Pausieren
- Seitenbanner nur ab 1780x720 px Viewport, sonst im Inhalts-Slot (`data-fd-slot="inline"` in index.html, sonst vor dem Footer)
- Einbindung: `<script src="js/foerderer.js" defer></script>` (index, forum, konto)
- Zaehlung in `sponsor_stats` (Tag/Banner): Sichtkontakt = 1 s zu 50 % sichtbar bei aktivem Tab; Klick ueber `api/foerderer.php?go=ID` (Ziel nur aus DB); Bots per User-Agent gefiltert; CSV-Export
- Tabellen `sponsor_banners`, `sponsor_stats` werden automatisch angelegt; Bilder in `img/foerderer/` (gitignored, .htaccess sperrt Skripte)
- Oeffentliche Namen bewusst "foerderer" statt banner/ad/sponsor (Werbeblocker-Filterlisten)
- Beim Deploy `img/foerderer/` auf dem Server nie ueberschreiben/loeschen
- Live seit 11.11.2026 (Commit 5ff2fcb); drei Platzhalter-Banner (IDs 1-3, "Platzhalter ...") verlinken auf `kontakt.html?thema=sponsoring`
- Position `bericht` (Spielbericht-Banner, freies Format, live seit 13.11.2026, 939b9c4): Berichte verweisen per `<center class="hafo-banner hafo-banner-ID">`, `NewsContent::applyReportBanners()` setzt beim Ausliefern Bild + `api/foerderer.php?go=ID` ein; Altbanner werden ueber `legacy_src` erkannt und ersetzt
- Einmaliger Import der Altbanner (`importReportBannersOnce()`, Merker `report_banners_imported` in `app_settings`) lief live am 13.11.2026 11:29: 11 Banner, IDs 5-15. Nicht erneut ausloesen; geloeschte bleiben geloescht
- Admin-Doku: `admin/anleitung.html` Kapitel 12

## Bekannte Eigenheiten

1. **API-Response Format**: Login/Check-Auth liefern User-Daten in `data.user.role`, nicht `data.role`

2. **Rollen-Werte**: In der DB als String ('admin', 'editor', 'user'), nicht als Integer

3. **Legacy .l98 Dateien**: Urspruengliches Format, jetzt in SQLite migriert

4. **FusionNews**: Alte News waren in `/news/news/*.php`, jetzt in SQLite `news` Tabelle

5. **Encoding**: Alle Texte sind UTF-8, Legacy-Daten wurden von ISO-8859-1 konvertiert

6. **Datumsformat**: DB speichert Spieltagsdaten als `TT.MM.JJJJ`; der Admin wandelt fuer `<input type=date>` um.

7. **SQLite-Strings**: immer einfache Anfuehrungszeichen (`'active'`), doppelte werden als Spaltenname gelesen.

8. **Standardliga**: Startseite/API nehmen ohne Parameter `Instance::defaultLeagueFile()` (config/instance.php: `default_league`, sonst zuletzt angelegte Liga).

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

