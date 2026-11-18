<?php
/**
 * Historie-Altbestand importieren (nur Kommandozeile)
 *
 *   php scripts/history-import.php <datei.json> [slug]
 *
 * JSON-Format:
 * {
 *   "competition": "Oberliga Musterstadt",
 *   "league_pattern": "^(oberliga|verbandsliga)",   // regulärer Ausdruck auf den Liga-Dateinamen
 *   "baseline_until": 2026,                           // letzte enthaltene Saison (Endjahr: 2026 = 2025/26)
 *   "source": "Quelle/Autor",
 *   "notes": ["Anmerkung", ...],
 *   "clubs": [{"name": "FC Muster", "played": 120, "won": 50, "drawn": 30, "lost": 40, "gf": 200, "ga": 180,
 *              "points": 180, "years": [2001, 2002], "placements": {"2001": "5", "2002": "hoeher"}}],
 *   "titles": [{"season": 2002, "champion": "FC Muster", "cup": "SV Beispiel", "note": null,
 *               "record": {"won": 20, "drawn": 5, "lost": 3, "points": 65, "gf": 70, "ga": 20}}],
 *   "team_map": {"Name in den Ligen": "Name in clubs"},
 *   "title_counts": {"champions": [{"name": "FC Muster", "count": 3}], "cups": [...],      // optional: eigene Zaehlung
 *                    "doubles": [{"name": "FC Muster", "count": 1, "seasons": ["2001/02"]}]}
 * }
 * Erneuter Import ersetzt den Altbestand; übernommene Saisons bleiben erhalten.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/HistoryService.php';

$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Aufruf: php scripts/history-import.php <datei.json> [slug]\n"); exit(1); }
$data = json_decode((string)file_get_contents($file), true);
if (!is_array($data)) { fwrite(STDERR, "Ungültiges JSON.\n"); exit(1); }
$slug = $argv[2] ?? trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($data['competition'] ?? 'historie', ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']))), '-');
try {
    $r = (new HistoryService())->import($data, $slug);
    echo "Importiert ($slug): {$r['clubs']} Vereine, {$r['titles']} Titel-Einträge, {$r['team_map']} Namenszuordnungen.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
