# Notizen: Saison-Assistent im Admin (Auftrag 3)

## Neue & geänderte Dateien
- `api/season-admin.php` (neu):
  - Admin-API (Session- & Rollenprüfung auf `admin`, CSRF-Schutz via `Security::requireCsrf()` für `POST`).
  - `GET ?action=init`: Liefert alle verfügbaren Ligen sowie bekannte Teams (inkl. Kurzname und Logo-Zuordnung) für Autocomplete.
  - `GET ?action=source_league&liga=...`: Lädt Quell-Liga, Teams, aktuelle Tabellenplatzierung (inkl. Markierung potenzieller Absteiger über `options.AB`) und schlägt neuen Saisontitel (`26/27` -> `27/28`) sowie Dateinamen (`hhoberliga2627.l98` -> `hhoberliga2728.l98`) vor.
  - `GET ?action=check_file&file=...`: Prüft Dateinamen-Regex (`^[a-z0-9_-]+\.l98$`) und ob die Liga bereits existiert.
  - `POST (action=preview)` & `POST (action=create)`:
    - Validiert Dateinamen (`^[a-z0-9_-]+\.l98$`, noch nicht vorhanden), Teamanzahl (`4..24` Teams, keine Duplikate, gerade/ungerade mit automatischem `spielfrei`-Slot).
    - Übernimmt `logo_file` (und ggf. `short_name`) automatisch vom gleichnamigen Team aus der Datenbank bzw. `img/teams/`.
    - Unterstützt **Option A** (leerer Spielplan) und **Option B** (automatische Doppelrunde per Round-Robin/Berger-Tabelle, Rückrunde gespiegelt mit getauschtem Heim-/Auswärtsrecht, optional Startdatum + Wochen-Abstand im Format `TT.MM.JJJJ` sowie Standard-Uhrzeit `HH:MM`).
    - Legt `leagues` (kopierte `options` als JSON mit angepasstem `Name`, `Teams`, `Rounds`, `Matches`, `Actual = "1"`), `teams` und `matches` in einer Datenbank-Transaktion an und leert anschließend `data/cache/*.json`.
- `admin/season.html` (neu):
  - 4-stufiger Saison-Assistent im Admin-Design (Quell-Liga & Name/Dateiname -> Teams übernehmen/hinzufügen -> Spielplan-Optionen -> Vorschau & Anlegen).
- `admin/index.html` & `api/save-matchday.php`:
  - Verlinkung des Saison-Assistenten in der Admin-Sidebar und im Dashboard (`+ Neue Saison anlegen`).
  - Unterstützung für das nachträgliche Eintragen von Paarungen im Liga-Editor bei leeren Spielplänen (Option A) inkl. Cache-Invalidierung.

## Durchgeführte Tests (`hhoberliga2627.l98` -> `hhoberliga2728.l98`)
- **Sicherheits- & Validierungstests:** Unauthentifizierte Zugriffe sowie `POST` ohne gültiges CSRF-Token werden mit `HTTP 403` abgewiesen; ungültige Dateinamen, bereits existierende Ligen und Teamanzahlen außerhalb `4..24` werden mit `HTTP 400` abgelehnt; ungerade Teamanzahlen berücksichtigen `spielfrei` pro Spieltag.
- **Testlauf „Hamburg-Oberliga 27/28“ (`hhoberliga2728.l98`):**
  - Aus `hhoberliga2627.l98` (18 Teams) mit Auto-Spielplan (Option B) erzeugt.
  - Ergebnis: 34 Spieltage (`Rounds = 34`), 9 Spiele je Spieltag (`Matches = 9`, 306 Spiele gesamt), jedes der 18 Teams exakt 1x pro Spieltag vertreten, 306 eindeutige Paarungen (je 1 Hin- und 1 Rückspiel mit gedrehtem Heim-/Auswärtsrecht), Spieldaten im Format `TT.MM.JJJJ`.
  - Test-Liga anschließend lokal wieder vollständig aus der SQLite-Datenbank gelöscht und `data/cache` geleert.
