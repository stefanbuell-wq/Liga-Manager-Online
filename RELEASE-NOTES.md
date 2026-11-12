# Release Notes – HAFO.de (LMO26)

Live unter **https://hafo.buell-digital.de**. Neueste Version oben.
Jede Version hat zwei Teile: **Für Redaktion und Leser** (was sich sichtbar ändert) und **Technik** (für die Entwicklung, mit Commits).

---

## 12.11.2026 (Nachtrag) – Spiele auf einen Blick

### Für Redaktion und Leser

- **Spieltag kompakt:** Auf der Startseite steht jedes Spiel jetzt in einer Zeile: „Heim 5:2 Gast“, mit Wappen, Datum und 📰 für den Spielbericht.
  - Das gilt oben bei „Spieltag …“ und im Bereich „Aktuell“.
  - Der ganze Spieltag ist auf einen Blick zu sehen, wie im Spielplan.
- Sieger und Siegtore sind hervorgehoben. Noch nicht gespielte Partien zeigen „–:–“.
- Auf dem Handy stehen Datum und Notiz klein unter der Zeile.
- **📰 und 🔭:** 📰 steht nur noch bei einem echten Spielbericht. Gibt es zu einem Spiel nur die Spieltags-Vorschau, steht dort 🔭. Das gilt auch im Spielplan.
- **Keine doppelte Anzeige mehr:** Die Spieltag-Leiste oben auf der Startseite ist entfallen, derselbe Spieltag steht im Bereich „Aktuell“. Der Menüpunkt „Spiele“ führt zum Spielplan.
- Lange Vereinsnamen werden umbrochen statt abgeschnitten. Das Datum steht kurz da, z. B. „16.10. 17:00“.
- **Ganze Tabelle:** Neben den Spielen steht jetzt die komplette Tabelle mit allen Plätzen statt nur 1–8.
  - Die Tabelle ist kompakter dargestellt.
  - Lange Namen sind gekürzt; wer mit der Maus darüberfährt, sieht den vollen Namen.
  - Der Link „Vollständig“ entfällt, weil die Tabelle jetzt ohnehin komplett ist.

### Technik

- Neue Funktion `matchRow()` in `index.html` für `renderMatches()` (Tab „Aktuell“) und die Spieltag-Leiste.
- CSS-Klassen `.mlist`/`.mrow` in `css/portal.css` (jetzt Version 20261115).
- `LmoRepository::getMatchIdsWithNews` liefert `is_preview`. Die Spieldaten haben das neue Feld `news_is_preview`, das Frontend nutzt dafür `newsButton()`.
- Spieltag-Leiste (`#live-section`) entfernt, `loadLiveMatches()` wird nicht mehr aufgerufen.
- `renderTable()` zeigt alle Plätze. Neue CSS-Klasse `.league-table.compact` in `portal.css` (jetzt Version 20261116).

---

## 12.11.2026 – Suche, verknüpfte Berichte, Sicherheits-Update

> **Wichtig:** Nach diesem Update müssen sich alle einmal neu anmelden (Redaktion, Admins, Forennutzer).

### Für Redaktion und Leser

**Neu: Suche über alles**
- Oben im Menü gibt es „🔍 Suche“. Eine Eingabe durchsucht Vereine, Spiele und Ergebnisse, Ligen, Spieler, Berichte und das Forum.
- Beispiele:
  - „Paloma Dassendorf“ zeigt alle Duelle mit Ergebnis und Link zum Bericht.
  - „Oberliga 15/16“ öffnet die Liga.
  - „Knebel“ findet den Spieler.
- Im Forum wird nur der öffentliche Bereich durchsucht.

**Neu: Suche und Filter in „Alle Berichte“ (Startseite)**
- Ein Suchfeld sowie Knöpfe für Spielberichte, Vorschauen und News.
- Eine Jahresauswahl von 2003 bis heute.

**Ältere Berichte und Vorschauen sind jetzt mit den Spielen verknüpft**
- 613 von 644 Vorschauen und 53 weitere Spielberichte hängen jetzt an ihren Spielen, auch an verlegten.
- Im Spielplan öffnet 📰 den Spielbericht. Gibt es keinen Bericht, öffnet es die Vorschau.

**Vorschauen besser lesbar**
- Zwischen den einzelnen Partien steht jetzt eine Trennlinie.
- Die Zeile „Team A vs. Team B“ ist hervorgehoben.

**Artikel-Fenster**
- Ein neu geöffneter Artikel beginnt immer oben. Bisher stand er manchmal noch dort, wo man im vorherigen Artikel aufgehört hatte.
- Das ✕ bleibt beim Scrollen sichtbar.
- Am Ende jedes Artikels gibt es einen großen Knopf „✕ Schließen“.

**Forum**
- Forennutzer können jetzt in allen normalen Bereichen schreiben. Ein Fehler in der Rechteprüfung hatte das bisher verhindert.
- Links in Beiträgen funktionieren weiter. Unsichere Link-Arten werden entfernt.

**Anleitung:** neuer Abschnitt „Alte Berichte finden“ in der Kurzanleitung für Redakteure.

**Sicherheit (spürbar für die Redaktion)**
- Wird ein Konto herabgestuft oder gesperrt, gilt das sofort, nicht erst nach dem Abmelden.
- Wer sein Passwort ändert, meldet damit alle anderen Sitzungen ab, zum Beispiel auf einem anderen Gerät.
- Nur noch Admins sehen die Benutzerliste mit E-Mail-Adressen und dürfen das Design ändern.
- Viele falsche Passwörter für ein Konto führen zu einer kurzen Wartezeit (höchstens 30 Sekunden), nicht zu einer Sperre.

### Technik

**Neue Funktionen**
- `suche.html` und `api/search.php` mit Bereichen und Seitenweise-Nachladen.
- Der Forum-Volltextindex `forum_fts` wird per `scripts/build-search-index.php` angelegt. Gezählt werden nur öffentliche Kategorien. (13f8d93)
- `api/get-news.php?list=1` kennt die Parameter `q` (FTS), `kind` (gleiche Regel wie `NewsContent::kind`) und `year`. (a5147a7)
- `scripts/link-old-news.php` verknüpft Vorschauen mit Spielen über „A vs. B“-Zeilen, Berichte über die Ergebniszeile. Verlegte Spiele werden in derselben Liga gesucht. Das Skript ergänzt nur, es löscht keine Verknüpfungen.
  - Live ausgeführt: 4.904 → 10.174 Verknüpfungen.
  - Im Spielplan hat der Bericht Vorrang vor der Vorschau. (737d8ad)
- `NewsContent::separateMatches()` setzt Trennlinien in Vorschauen; der Editor erzeugt sie ebenfalls. (1bea55c)
- Artikel-Modal: `resetModalScroll()`, ein mitlaufendes Sticky-✕ und ein Schließen-Knopf. (d15a285)

**Sicherheit (Audit vom 12.11.2026, Bericht unter `~/security-audit-skill/lmo26/run-1/`)**
- **Forum:**
  - Gastbeiträge (`user_id` NULL) können nicht mehr anonym bearbeitet oder gelöscht werden: strikter Besitzvergleich statt `null == null`.
  - Links in Beiträgen werden per Allowlist geprüft (`isSafeUrl`).
  - Rolle `user` in `PERMISSION_LEVELS`, nur die nötigen Spalten in den Lesepfaden, Limits begrenzt, `match-forum.php` filtert nach Sichtrecht. (902b1f8, 9acf890)
- **Ausgabe kodiert:** Forentitel, Teamnamen, Logo-Dateien, Spielnotizen und Tore in `index.html`, `admin/index.html`, `admin/edit.html` und `admin/news.html`. `escapeJs` kodiert jetzt auch HTML. (902b1f8, 9acf890)
- **Sitzungen:**
  - `Security::initSession()` gleicht Rolle, `active` und Status bei jeder Anfrage aus der Datenbank ab.
  - `Security::markAuthChanged()` (Spalte `users.auth_changed_at`) beendet nach einem Passwortwechsel die anderen Sitzungen. (9acf890)
- **Login:**
  - `getClientIp()` liefert nur `REMOTE_ADDR`.
  - Login-CSRF wird über eine Origin-Prüfung abgewehrt.
  - Für unbekannte Benutzer wird ein Dummy-Hash geprüft, damit es kein Timing-Orakel gibt.
  - Konto-Bremse ab 10 Fehlversuchen: Mindestpause mit HTTP 429 statt Sperre oder `sleep()`. (9acf890, 4da43bf, 27e6010)
- **Admin-Rechte:**
  - `users.php`: Liste und Einzelabruf nur für Admins, `requireAdmin` prüft `active` und Status, der letzte Admin ist geschützt.
  - `theme.php`: nur Admins mit CSRF-Token, CORS nur für GET.
  - `requireCsrf()` gilt für alle schreibenden HTTP-Methoden. (9acf890)
- **Bild-Proxy:** Weiterleitungen nur zu erlaubten Hosts, der Inhalt wird per `getimagesizefromstring` geprüft. (9acf890)
- **Altlasten:**
  - Entfernt: `api/get-news-item.php` und `api/server-check.php` (hartkodiertes Token).
  - `api/news.php` und `get-news-list.php` sind nur noch für die Redaktion abrufbar, ohne E-Mail-Feld.
  - Alle Wartungs- und Import-Skripte haben einen CLI-Guard.
  - Versionierte `.htaccess` für `data/`, `config/`, `lib/` und `scripts/`; die unwirksame `DirectoryMatch`-Sperre ist ersetzt; `.md`/`.zip`/`.sqlite` und Ähnliches werden gesperrt. (9acf890)
- `save-match-events.php` repariert (Anmeldung und CSRF), `Secure`-Cookie auch auf Port 443, Drossel für Sponsoren-Sichtkontakte. (9acf890)
- Sicherheitsregeln für künftige Änderungen stehen in `CLAUDE.md` unter „Sicherheitsregeln“.

**Backlog:** „Fotos in Spielberichten“ aufgenommen. (f3bf3e3)

---

## 11.11.2026 – Feedback der Redaktion, Sponsoren, Kontakt

### Für Redaktion und Leser

**Berichte**
- Autor, Vereinswappen und Sponsoren-Banner werden wieder angezeigt, auch bei alten Berichten. Dafür sind 2.016 Altbilder übernommen.

**Startseite**
- „Alle Berichte“ listet chronologisch, mit dem Knopf „Ältere Berichte anzeigen“.

**Spielberichte und Tabellen**
- Der Liga-Filter bei den Spielberichten funktioniert.
- Die Ligen der aktuellen Saison sind große Knöpfe, ältere Jahre stehen im „Archiv“.

**Bericht schreiben, vereinfacht**
- Nach Auswahl von Liga, Heim und Gast füllt der Editor Wappen, Spieltag, Datum, Ergebnis, Banner und die Verknüpfung mit dem Spiel selbst aus.
- Texte aus Word lassen sich einfügen.
- Admin-Bereiche sind für Redakteure ausgeblendet.
- Eine Kurzanleitung steht in der Anleitung ganz oben.

**Sponsoren-Banner**
- Verwaltung, Rotation und Statistik der Sichtkontakte und Klicks.

**Kontaktformular**
- Mit Sponsoring-Anfrage, dazu eine Admin-Ansicht der Anfragen.

### Technik
- `lib/NewsContent.php`:
  - `prepare`, `sanitize` (Allowlist), Umschreiben der Altbilder nach `legacy/`.
  - Wappen über `api/img-proxy.php`.
- `get-news.php` mit Liste, Art und Liga-Filter; `email` wird nicht ausgegeben. (e0fc370, 53df3bf)
- Die CSP erlaubt `blob:` für die Bildvorschau beim Banner-Upload. (96314de)
- Sponsoren-Banner (5ff2fcb), Kontaktformular (d4d808e), Dokumentation (27e7142, dcc5abe).

---

## 10.11.2026 – Start auf hafo.buell-digital.de

### Für Redaktion und Leser

**Konten für Forennutzer**
- Registrierung mit E-Mail-Bestätigung und Freigabe durch einen Admin.
- Passwort zurücksetzen.
- Übernahme eines alten Forennamens.
- Automatische Deaktivierung nach langer Inaktivität.

**Tabelle**
- Stand nach jedem Spieltag mit Trendpfeil.

**Saison-Assistent im Admin**
- Neue Saison anlegen, Teams übernehmen, Auf- und Absteiger.

**Spielerprofile**
- Stammdaten, Vereinshistorie und öffentlicher Kader.

**Admin**
- Ligalisten lassen sich durchsuchen, filtern und sortieren.
- Die Benutzerverwaltung hat Suche und Filter; Altkonten sind als deaktiviert gekennzeichnet.

**Startseite und Design**
- Echte Daten statt Demo-Inhalten.
- Automatisch die neueste Saison der Hamburg-Oberliga.
- Helles Design.
- Schriften lokal eingebunden (DSGVO, keine Anfragen an Google).

### Technik
- Konten (811ec61), Tabelle pro Spieltag (588e1a8), Saison-Assistent (3bf0754), Spielerprofile (5df5cb0).
- Login nur noch über Datenbank-Konten, Ersteinrichtung per `cron/create-admin.php` (0ecab88).
- Sicheres Deployment mit Sperre für Platzhalter in Impressum und Datenschutz (0175fcc, 713846e).
- Legacy-Export: Konverter, JSON-Export und SQLite-Re-Import (c68ea9b).

---

## Februar 2026 – Forum, Kader, Statistiken

- Forum, Spielerstatistiken, News-Verwaltung, Design-Editor und Migrationsskripte (c1276dd).
- Kaderverwaltung und Aufstellungsauswahl in Spielberichten (5b9f59b); FuPa-Import der Kader (0fe8ce2, 4a23f29).
- Neues Frontend, Sicherheitsgrundlagen, Ablösung des alten News-Systems (f53c27b).

## Januar 2026 – Erste Version

- Liga Manager Online (LMO26) mit Ligen, Spielplan und Tabellen (77ec6cb, 21e8011).

---

### Pflege dieser Datei

- Bei jedem Deploy oben einen Eintrag mit Datum ergänzen oder den Eintrag des Tages erweitern.
- Den Teil „Für Redaktion und Leser“ ohne Fachbegriffe schreiben. Den Teil „Technik“ mit Dateien und Commit-Kürzeln.
- Hinweise, die alle betreffen (z. B. „neu anmelden“), als Kasten ganz oben in den Eintrag.
