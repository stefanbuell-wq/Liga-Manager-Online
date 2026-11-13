# Release Notes – HAFO.de (LMO26)

Live unter **https://hafo.de**. Neueste Version oben.
Jede Version hat zwei Teile: **Für Redaktion und Leser** (was sich sichtbar ändert) und **Technik** (für die Entwicklung, mit Commits).

---

## 13.11.2026 (Nachtrag 13) – Heimrecht tauschen im Ergebnis-Editor

### Für Redaktion und Leser

- Im Ergebnis-Editor gibt es beim Spiel den Knopf **⇄**: Er dreht die Paarung (Heim- und Gastteam), z. B. wenn bei einer Verlegung das Heimrecht getauscht wird. Gespeichert wird mit „Speichern“ nach einer Rückfrage. Verknüpfte Spielberichte bleiben erhalten, das Rückspiel bleibt unverändert.

### Technik

- `save-matchday.php`: Feld `swap` je Spiel tauscht `home_team_id`/`guest_team_id` in derselben Zeile (ID und `match_news` bleiben), Abbruch, wenn die umgekehrte Paarung am Spieltag schon existiert
- `admin/index.html`: Knopf ⇄ (`toggleSwap`), Tore werden mitgedreht, Rückfrage beim Speichern

---

## 13.11.2026 (Nachtrag 12) – Aktueller Spieltag und frische Ergebnisse auf der Startseite

### Für Redaktion und Leser

- Startseite und Spielplan öffnen jetzt den letzten gespielten Spieltag (z. B. 15) statt eines fest hinterlegten (bisher immer 12).
- Der Ergebnis-Editor öffnet den Spieltag, der als nächstes einzutragen ist: den letzten gespielten, solange dort noch Ergebnisse fehlen, sonst den folgenden.
- Neu eingetragene Ergebnisse erscheinen auf der Startseite sofort und nicht erst nach bis zu 5 Minuten.

### Technik

- `LmoRepository::getLeagueDataFull`: `current_round` (= letzter gespielter Spieltag) und `edit_round`; `index.html`, `js/league-viewer.js`, `admin/edit.html`, `admin/index.html` nutzen diese statt `options.Actual`
- `get-league-data.php`: `Cache-Control: no-cache` + `ETag` (304) statt `public, max-age=300`

---

## 13.11.2026 (Nachtrag 11) – Ergebnisse: nach dem Speichern richtig anzeigen

### Für Redaktion und Leser

- Nach dem Speichern von Ergebnissen sah es so aus, als wäre nichts gespeichert. Der Editor sprang auf einen anderen Spieltag und zeigte teilweise den Stand von vor dem Speichern. Jetzt bleibt er auf dem bearbeiteten Spieltag und zeigt die gespeicherten Ergebnisse.
- Der zweite Ergebnis-Editor hätte beim Speichern Datum, Uhrzeit und Notiz der Spiele gelöscht. Das ist behoben. Gelöscht wurde bisher nichts, in den aktuellen Ligen hat jedes Spiel sein Datum.

### Technik

- `admin/index.html` (`openLeagueEditor(file, keepRound)`) und `admin/edit.html` (`loadLeague(keepRound)`): `fetch(..., { cache: 'no-store' })`, weil `get-league-data.php` `Cache-Control: public, max-age=300` sendet; nach dem Speichern derselbe Spieltag
- `api/save-matchday.php`: Datum, Uhrzeit und Notiz werden nur geändert, wenn sie im Request vorkommen (`CASE WHEN :md_set ...`)
- Live seit 13.11.2026, Commit aaa49e0, Sicherung `/hafo.de/backups/hafo-pre-aaa49e0.tar.gz`

---

## 13.11.2026 (Nachtrag 10) – Redakteur wurde immer wieder abgemeldet

### Für Redaktion und Leser

- Ein Redakteur kam beim Anlegen eines Spielberichts immer wieder zur Anmeldung. Sein Konto war noch als „deaktiviert“ eingetragen und ist jetzt wieder aktiv.
- Ein gesperrtes Konto bekommt jetzt gleich beim Anmelden eine klare Meldung. Vorher wurde es erst angemeldet und beim nächsten Klick wieder abgemeldet.

### Technik

- Daten: `users` id 248 `status` von `disabled` auf `active` gesetzt (`active` war 1). Der Status stammte wohl vom Altkonten-Import am 10.11. Sicherung `/hafo.de/backups/database-pre-andi-status.sqlite`
- `api/login.php`: nur `status = 'active'`, gleiche Regel wie `Security::syncAuthFromDb()`. Live seit 13.11.2026, Commit 8830ef2, Sicherung `/hafo.de/backups/hafo-pre-8830ef2.tar.gz`

---

## 13.11.2026 (Nachtrag 9) – Forum: neueste Beiträge zuerst, Sortieren und Suchen

### Für Redaktion und Leser

- In einem Thema stehen die **neuesten Beiträge jetzt oben** (Wunsch aus dem ersten Leser-Feedback). Der Eröffnungsbeitrag bleibt ganz oben, darunter das Antwortfeld. Mit **„Älteste zuerst“** geht es zurück zur alten Reihenfolge. Der Browser merkt sich die Wahl.
- Themenlisten lassen sich sortieren: Letzte Antwort, Neueste Themen, Älteste Themen, Meiste Beiträge.
- **Suchen** geht im ganzen Forum, in einem Bereich oder in einem Thema. Es müssen alle Suchwörter vorkommen, der Wortanfang reicht. Treffer werden gelb markiert.

### Technik

- `lib/ForumRepository.php`: `listTopics()` (Sortierung + Suche, Treffer je Thema per materialisierter CTE), `getPostsByTopic()` mit Sortierung/Suche/`post_nr` (`ROW_NUMBER`), `countPostsByTopic()`, `getFirstPost()`, Suche über `forum_fts` (Rückfall LIKE)
- `api/forum-topics.php`: Parameter `sort` (Thema `new`/`old`, Liste `activity`/`newest`/`oldest`/`replies`) und `q`; ohne Kategorie mit `q` = Suche über alle sichtbaren Kategorien
- `js/forum.js`/`forum.html`: Leiste `#forum-toolbar`, `highlightMatches()`, `escapeAttr()` für Attributwerte; `forum.js?v=20261113`
- Live seit 13.11.2026, Commit 2b900a2, Sicherung `/hafo.de/backups/hafo-pre-2b900a2.tar.gz`

---

## 13.11.2026 (Nachtrag 8) – Editor zeigt Berichte formatiert

### Für Redaktion und Leser

- Im Reiter „HTML (für Profis)“ erscheint der Bericht jetzt so, wie ihn die Leser sehen, und lässt sich direkt bearbeiten. Den HTML-Code zeigt erst der Knopf **„Quelltext anzeigen“**.
- Text aus Word wird dabei ohne Farben und Schriften eingefügt. Fett, kursiv und Absätze bleiben erhalten.

### Technik

- `admin/news.html`: `setHtmlView()`/`syncFormattedView()`; HTML-Reiter öffnet formatiert (`editNews`, `switchTab`); beim Verlassen des Reiters zurück auf Quelltext, damit Template-Inhalt nicht überschrieben wird
- Paste in die formatierte Ansicht über `wordHtmlToMarkup()` + `textToHtml()`
- Sicherheit: `sanitizeForEditor()` filtert gespeichertes HTML vor dem Einsetzen. Die Allowlist ist dieselbe wie bei `NewsContent::sanitize`, geparst wird per DOMParser in einem inaktiven Dokument. Skripte, iframes, svg, `on*`-Attribute und `javascript:`-/`data:`-URLs entfallen.
- Offen (optional): zusätzlich serverseitig beim Speichern filtern
- Live seit 13.11.2026, Commits 22b1806 und e22664d, Sicherung `/hafo.de/backups/hafo-pre-e22664d.tar.gz`

---

## 13.11.2026 (Nachtrag 7) – Fetter Text im Editor wieder lesbar

### Für Redaktion und Leser

- Im Bericht-Editor war fett formatierter Text, zum Beispiel fette Überschriften, in der formatierten Ansicht weiß auf weiß. Jetzt erscheint er in der normalen Farbe.

### Technik

- `admin/news.html`: `#newsContentFormatted b, strong` hatte `color: #fff` (Rest des dunklen Designs), jetzt `inherit`. Live seit 13.11.2026, Commit 077461d.

---

## 13.11.2026 (Nachtrag 6) – Benutzer bearbeiten

### Für Redaktion und Leser

- Admin → Benutzer: Nach einem Klick auf **Bearbeiten** oder **+ Neuer Benutzer** springt die Seite jetzt zum Formular. Bisher öffnete es sich unsichtbar unter der langen Benutzerliste.

### Technik

- `admin/index.html` `openUserEditor()`: `scrollIntoView` auf `#user-editor-panel`. Live seit 13.11.2026, Commit daca0a6.

---

## 13.11.2026 (Nachtrag 5) – Login-Sperre korrigiert

### Für Redaktion und Leser

- Nach 5 falschen Passwörtern gilt weiterhin eine Wartezeit von 15 Minuten. Danach hat man jetzt wirklich wieder 5 Versuche. Bisher sperrte der erste Versuch nach der Wartezeit sofort erneut, im schlimmsten Fall bis zu 2 Stunden lang.

### Technik

- `Security::checkRateLimit($id, $max, $resetAfterLock)`: Mit `true` (nur `api/login.php`) löscht eine abgelaufene Sperre den Zähler. Kontaktformular, Konto-Mails und Banner-Zähler behalten ihr Stundenbudget.
- Aufräumen der `rate_limits` mit PHP-Ortszeit statt SQLite-`datetime('now')`. SQLite rechnet in UTC, deshalb wurden Einträge 1 h zu spät gelöscht.
- Live seit 13.11.2026, Commits e860ce3 und 305cc1d. Sicherung `/hafo.de/backups/hafo-pre-305cc1d.tar.gz` und `security-pre-305cc1d.db`.

---

## 13.11.2026 (Nachtrag 4) – 24 Stunden angemeldet bleiben

### Für Redaktion und Leser

- Wer sich anmeldet, bleibt bis zu **24 Stunden** ohne Aktivität angemeldet (bisher 2 Stunden), auch wenn der Browser zwischendurch geschlossen wird.
- Das ungewollte Abmelden nach etwa 25 Minuten fällt weg.
- An fremden oder gemeinsam genutzten Rechnern deshalb nach der Arbeit immer abmelden.
- Beim Einspielen wurden alle einmalig abgemeldet.

### Technik

- `Security::SESSION_LIFETIME = 86400` für Inaktivitäts-Timeout, `session.gc_maxlifetime` und `session.cookie_lifetime`
- Sitzungsdateien in `data/sessions/` (0700, per `data/.htaccess` gesperrt, gitignored, eigene GC 1/100). Grund: Die Aufräumroutine im Server-Standardordner löschte Sitzungen nach 1440 s.
- Cookie-Ablauf wird bei angemeldeten Nutzern höchstens stündlich nachgezogen
- Live seit 13.11.2026, Commit 23bc5a0, Sicherung `/hafo.de/backups/hafo-pre-23bc5a0.tar.gz`

---

## 13.11.2026 (Nachtrag 3) – Banner in Spielberichten verwaltbar

### Für Redaktion und Leser

- Die Sponsoren-Banner oben in Spielberichten werden jetzt unter **🤝 Sponsoren** gepflegt (neue Position „Spielbericht“): Bild hochladen, Link, Vereine, Laufzeit, Pausieren.
- Ändert ein Admin Bild oder Link oder pausiert einen Banner, gilt das sofort für **alle** Berichte mit diesem Banner, auch für ältere.
- Die bisherigen Banner (St. Pauli Coffee, Stehn & Co., Förderkreis Curslack-Neuengamme, Elbhandwerk, helle idee u. a.) werden beim ersten Aufruf automatisch übernommen.
- Sichtkontakte und Klicks der Bericht-Banner erscheinen in der Sponsoren-Statistik und im CSV-Export.
- Im News-Editor bleibt die Auswahl „Sponsoren-Banner oben im Bericht“ wie gewohnt, inklusive Vorschlag passend zum Verein.

### Technik

- `SponsorRepository`: Position `bericht` (freies Format 200–1600 × 40–800), Spalten `teams`, `legacy_src`, `reportBanners()`, `reportBannerHtml()`, einmaliger Import `importReportBannersOnce()` (Merker `report_banners_imported` in `app_settings`, Bilder nach `img/foerderer/`)
- `NewsContent::applyReportBanners()` nach `sanitize`: `<center class="hafo-banner hafo-banner-ID">` und übernommene Altbanner werden beim Ausliefern durch aktuelles Bild + Klick-Weiterleitung ersetzt bzw. entfernt
- `news-admin.php?action=banners` liefert die verwalteten Banner; Editor speichert Verweis per ID
- `js/foerderer.js`: zählt Sichtkontakte von `.hafo-fd[data-fd-id]` in Berichten
- Admin `sponsoren.html`: Feld „Vereine“, Anzeige „in N Berichten“, Löschwarnung
- Live seit 13.11.2026, Commit 939b9c4. Sicherung `/hafo.de/backups/hafo-pre-939b9c4.tar.gz` + `database-pre-939b9c4.sqlite`. Der Import war beim Deploy noch nicht gelaufen; der Probelauf fand 11 Altbanner (zusätzlich `hebc2010-banner.jpg`, `mybed-banner2018.jpg`).

---

## 13.11.2026 (Nachtrag 2) – Alte Lesezeichen auf main.php

### Für Redaktion und Leser

- Wer noch ein Lesezeichen auf die alte Adresse `hafo.de/main.php` hat, landet jetzt automatisch auf der Startseite statt auf einer Fehlerseite.

### Technik

- `main.php` (301 auf `/`), `.htaccess`: `RedirectMatch 301 (?i)^/main\.php$ /` für alle Schreibweisen (`Main.php`, `MAIN.PHP`); Commit f0de034
- Sicherung der vorherigen `.htaccess`: `/hafo.de/backups/htaccess-pre-f0de034`

---

## 13.11.2026 (Nachtrag) – Mail bei neuen Kontaktanfragen

### Für Redaktion und Leser

- Neu im Admin: **⚙️ Einstellungen**. Dort lassen sich bis zu zwei Kontaktpersonen eintragen, die bei jeder neuen Anfrage über das Kontaktformular eine E-Mail bekommen.
- Mit „Test-Mail senden“ lässt sich prüfen, ob die Mails ankommen.
- Registrierung: Das Feld „Benutzername“ wird nicht mehr vom Browser mit der E-Mail-Adresse vorausgefüllt; ein Hinweis erklärt, was dort hingehört.
- E-Mails (Registrierung, Freischaltung, Passwort vergessen) kommen jetzt an; die gelbe Warnung in der Benutzerverwaltung ist entfallen.

### Technik

- `lib/SettingsRepository.php` (Tabelle `app_settings`), `api/settings.php` (nur Admin, CSRF), `admin/einstellungen.html`
- `ContactService`: Empfänger aus `contact_notify_1/_2`, Rückfall `config/mail.php`; Versand je Empfänger (8a6447b)
- `konto.html`: `autocomplete="nickname"` + Hinweis, `AccountService`: eigene Meldung bei „@“ im Benutzernamen (7e7dec9)
- Admin-Warnung zu fehlender `config/mail.php` entfernt, Versand per `mail()` auf netcup geprüft (9942151)

---

## 13.11.2026 – Umzug auf hafo.de

### Für Redaktion und Leser

- Die Seite läuft jetzt unter **https://hafo.de**. Alle Inhalte, Berichte, Forumsbeiträge und Zugänge sind mitgezogen.
- Die alte Adresse hafo.buell-digital.de leitet automatisch auf hafo.de weiter. Alte Links und Lesezeichen funktionieren also weiter.
- Bitte einmal neu anmelden. Die Anmeldung gilt jetzt für die neue Adresse.

### Technik

- Neues Hosting: netcup Webhosting (Plesk), Webspace `<webspace>`, Pfad `/hafo.de/httpdocs`, PHP 8.5; SSH per Schlüssel (ssh-manager `hafo`)
- Standard-Adresse in Mails/Kontakt auf `https://hafo.de` (d035699)
- DB per `sqlite3 .backup` übertragen, alte Domain per 301 in `.htaccess` umgeleitet; Sicherungen auf vereinspilot `~/backups/hafo-db-umzug-20261113.sqlite`, `~/backups/hafo-htaccess-vor-umzug`, Wartungsseite auf netcup `/hafo.de/backups/`
- Nicht übertragen: `scripts/`, `config/auth copy.php`, Cache

---

## 12.11.2026 (5. Nachtrag) – Saison eines Vereins: Jahr ausgeschrieben

### Für Redaktion und Leser

- Im Fenster „Saison eines Vereins“ steht das Jahr jetzt vollständig, z. B. „24.07.2026 17:15“ statt „24.07.26 17:15“.

### Technik

- `index.html` `showTeamSeason`: Datum TT.MM.JJJJ; Spalte `.srow` 108 → 130 px, `portal.css?v=20261120`

---

## 12.11.2026 (4. Nachtrag) – Vereinswappen in hoher Auflösung

### Für Redaktion und Leser

- **Schärfere Wappen:** Für 214 von 228 Vereinen gibt es jetzt ein neues, scharfes Wappen (offizielle Logos von fussball.de) statt der kleinen, pixeligen Bildchen.
  - Das gilt überall: Tabelle, Spielplan, Spieltag, Saison eines Vereins, Kader und auch in allen alten Spielberichten.
  - Einige Vereine, die bisher gar kein Wappen hatten, haben jetzt eines (z. B. Kickers Emden, Hannover 96, Arminia Hannover).
  - Bei 14 meist aufgelösten oder sehr kleinen Vereinen (z. B. St. Georg/Horn, Inter 2000, GSK Bergedorf) bleibt das alte Wappen bzw. keines.
- In Spielberichten werden Wappen höchstens 72 Pixel groß angezeigt.

### Technik

- `img/teams/hd/<Schluessel>.png` (160 px, aus 350 px verkleinert); `img/teams/.htaccess` liefert bei Anfrage `X.gif` das `hd/X.png`, falls vorhanden (kein Code-Umbau, gilt auch fuer gespeichertes Berichts-HTML)
- Import: `scripts/fetch-crests.php` (fussball.de-Suche, Zuordnung `data/crests/map.json`, Suchbegriffe/Handzuordnung `data/crests/aliases.json`), `scripts/resize-crests.php`, Pruefseite `scripts/crest-review.php`
- `portal.css`: `.news-body img[src*="img/teams/"]` max. 72 px; `portal.css?v=20261119`

---

## 12.11.2026 (3. Nachtrag) – Saison eines Vereins mit Jahreszahl

### Für Redaktion und Leser

- Im Fenster „Saison eines Vereins“ (Klick auf einen Vereinsnamen) steht beim Datum jetzt auch das Jahr, z. B. „16.10.26 17:00“. Eine Saison geht ja über zwei Jahre.

### Technik

- `index.html` `showTeamSeason`: Datum TT.MM.JJ; Spalte `.srow` 92 → 108 px, `portal.css?v=20261118`

---

## 12.11.2026 (2. Nachtrag) – Bericht schreiben: aktuelle Saison zuerst, Datum per Klick

### Für Redaktion und Leser

- **Nur die aktuelle Saison in der Liga-Auswahl:** Beim Spielbericht und bei der Vorschau stehen in der Liste „Liga / Wettbewerb“ nur noch die Ligen der laufenden Saison (z. B. 26/27). Langes Suchen entfällt.
  - Ältere Saisons gibt es weiter: letzter Eintrag „📁 Ältere Saisons anzeigen …“.
  - Welche Saison aktuell ist, ergibt sich von selbst: Sobald eine neue Saison angelegt ist, steht sie oben.
  - Beim Bearbeiten eines alten Berichts wird dessen Liga automatisch gefunden.
- **Spieldatum per Klick:** Ein Klick ins Feld „Spieldatum“ trägt das heutige Datum ein und zeigt den Kalender des Monats. Ein anderer Tag lässt sich dort direkt anklicken.
  - Werden danach Heim- und Gast-Team gewählt, übernimmt das Feld das echte Spieldatum aus dem Spielplan.

### Technik

- `admin/news.html`: `fillLeagueSelect` gruppiert nach Saison (Logik wie `leagueSeason` in index.html), Archiv als Option `__archiv__`; `setLeagueValue` klappt das Archiv beim Laden alter Berichte auf
- `newsMatchDate` ist jetzt `<input type="date">` (ISO wie in der DB), `toIsoDate` wandelt TT.MM.JJJJ aus dem Spielplan um; `newsDateAuto` erlaubt das Ersetzen des automatisch gesetzten Tages

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
- **Saison eines Vereins:** Ein Klick auf einen Vereinsnamen in Tabelle, Spielplan oder Spieltag zeigt alle Partien dieses Vereins in der Saison, z. B. alle 34.
  - Pro Partie stehen Spieltag, Datum, Uhrzeit, Gegner und Ergebnis.
  - Kennzeichnung S/U/N in Grün, Grau und Rot; 📰 bzw. 🔭 führen zu Bericht oder Vorschau.
  - Oben stehen Platz, Bilanz und Punkte, dazu der Knopf „Kader anzeigen“.

### Technik

- Neue Funktion `matchRow()` in `index.html` für `renderMatches()` (Tab „Aktuell“) und die Spieltag-Leiste.
- CSS-Klassen `.mlist`/`.mrow` in `css/portal.css` (jetzt Version 20261115).
- `LmoRepository::getMatchIdsWithNews` liefert `is_preview`. Die Spieldaten haben das neue Feld `news_is_preview`, das Frontend nutzt dafür `newsButton()`.
- Spieltag-Leiste (`#live-section`) entfernt, `loadLiveMatches()` wird nicht mehr aufgerufen.
- `teamLink()` und `showTeamSeason()` in `index.html` (Modal aus `leagueData.matches`); CSS `.srow`/`.team-link` (`portal.css` jetzt Version 20261117). Der Kader ist im Modal per Knopf erreichbar (`showTeamSquad`).
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
