# Notizen: Tabelle pro Spieltag (Auftrag 2)

## Geänderte Dateien
- `api/get-league-data.php`: Optionaler Parameter `round` (strikte Validierung auf Integer $\ge 1$, HTTP 400 bei ungültiger Eingabe) und Einbindung von `round` in den Cache-Key (`{$ligaFile}_round_{$round}`).
- `lib/LmoRepository.php`: Erweiterung von `getLeagueDataFull($file, $round = null)` und `calculateTable(..., $maxRound = null)` um Spieltagsfilterung (`round_nr <= round`), Bereichsvalidierung (`1..Rounds`), Beibehaltung der Punktabzüge (`point_corrections`) sowie Berechnung der Platzierungsveränderung gegenüber dem Vorspieltag (`prev_rank`, `rank_change`).
- `index.html`: Auswahlfeld „Stand nach Spieltag“ (`1..letzter gespielter Spieltag`, Standard = letzter gespielter Spieltag) über der vollen Tabelle (`#tab-table`) sowie in der kleinen Übersichtstabelle (`#tab-overview`), Nachladen per API und Anzeige der Platzierungsveränderung (`▲`/`▼`/`•`).
- `css/portal.css`: Styles für die Spieltagsauswahl über der Tabelle und die Platzierungstrends ausschließlich mit bestehenden CSS-Variablen des hellen Themes.

## Testergebnis (`hhoberliga2627.l98`)
- **Stand nach Spieltag 12 (Standard / letzter gespielter Spieltag):** Entspricht exakt der aktuellen Tabelle (1. USC Paloma, 12 Spiele, 26 Punkte).
- **Stand nach Spieltag 1:** 9 gespielte Partien berücksichtigt (1. TSV Buchholz 08, 1 Spiel, 3 Punkte, 4:0 Tore).
- **Validierung & Cache:** Ungültige Werte (`round=0`, `round=35`, `round=abc`) werden mit HTTP 400 abgewiesen; gültige Abfragen erzeugen getrennte Cache-Dateien pro `(liga, round)`.
