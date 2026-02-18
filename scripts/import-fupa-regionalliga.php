<?php
/**
 * FuPa Import: Kader für Regionalliga Nord 25/26
 * Aufruf: php scripts/import-fupa-regionalliga.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/LmoDatabase.php';
$pdo = LmoDatabase::getInstance();

$leagueFile = 'regionalliganord2526.l98';
$fupaLeague = 'regionalliga-nord';

echo "=== FuPa Import - Regionalliga Nord 25/26 ===\n\n";

$context = stream_context_create([
    'http' => ['header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n", 'timeout' => 30],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

echo "Lade Tabelle von fupa.net...\n";
$standingHtml = file_get_contents("https://www.fupa.net/league/$fupaLeague/standing", false, $context);
if (!$standingHtml) die("Konnte Tabelle nicht laden!\n");

$redux = extractReduxData($standingHtml);
if (!$redux) die("Keine REDUX_DATA gefunden!\n");

$standings = $redux['dataHistory'][0]['LeagueStandingPage']['total']['data']['standings'] ?? [];
if (empty($standings)) die("Keine Teams in der Tabelle gefunden!\n");

echo count($standings) . " Teams in der fupa-Tabelle gefunden.\n\n";

$league = $pdo->query("SELECT id FROM leagues WHERE file = '$leagueFile'")->fetch(PDO::FETCH_ASSOC);
if (!$league) die("Liga '$leagueFile' nicht in DB gefunden!\n");

$dbTeams = $pdo->prepare("SELECT id, name FROM teams WHERE league_id = ? ORDER BY name");
$dbTeams->execute([$league['id']]);
$dbTeams = $dbTeams->fetchAll(PDO::FETCH_ASSOC);

$manualMap = [
    'Hamburger SV' => 'Hamburger SV II',
    'Hannover 96' => 'Hannover 96 II',
    'SV Werder Bremen' => 'SV Werder Bremen II',
    'FC St. Pauli' => 'FC St. Pauli II',
    'FC Eintracht Norderstedt' => 'FC Eintracht 03 Norderstedt',
    'TuS Blau-Weiß Lohne' => 'TuS BW Lohne',
    '1. FC Phönix Lübeck' => '1. FC Phönix Lübeck',
];

$mapping = buildTeamMapping($standings, $dbTeams, $manualMap);

echo "Team-Zuordnung:\n";
foreach ($mapping as $fupaSlug => $info) {
    $status = $info['db_id'] ? "-> DB-ID {$info['db_id']} ({$info['db_name']})" : "!! NICHT ZUGEORDNET";
    echo sprintf("  %-45s %s\n", $info['fupa_name'], $status);
}

$unmapped = array_filter($mapping, fn($m) => !$m['db_id']);
if (!empty($unmapped)) {
    echo "\nWARNUNG: " . count($unmapped) . " Teams konnten nicht zugeordnet werden!\n";
    echo "Trotzdem fortfahren? (j/n): ";
    $input = trim(fgets(STDIN));
    if (strtolower($input) !== 'j') { echo "Abgebrochen.\n"; exit(0); }
}

$mappedTeamIds = array_filter(array_column($mapping, 'db_id'));
if (!empty($mappedTeamIds)) {
    $placeholders = implode(',', array_fill(0, count($mappedTeamIds), '?'));
    $existingCount = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id IN ($placeholders)");
    $existingCount->execute(array_values($mappedTeamIds));
    $count = $existingCount->fetchColumn();
    if ($count > 0) {
        echo "\nACHTUNG: Es existieren bereits $count Spieler fuer diese Teams.\n";
        echo "Bestehende Spieler loeschen und neu importieren? (j/n): ";
        $input = trim(fgets(STDIN));
        if (strtolower($input) !== 'j') { echo "Abgebrochen.\n"; exit(0); }
        $pdo->prepare("DELETE FROM players WHERE team_id IN ($placeholders)")->execute(array_values($mappedTeamIds));
        echo "Bestehende Spieler geloescht.\n";
    }
}

$insertStmt = $pdo->prepare("INSERT INTO players (team_id, name, number, position) VALUES (?, ?, ?, ?)");
$totalImported = 0;
$errors = [];
$positionMap = ['Torwart'=>'Torwart','Abwehr'=>'Abwehr','Mittelfeld'=>'Mittelfeld','Sturm'=>'Sturm','Angriff'=>'Sturm'];

foreach ($mapping as $fupaSlug => $info) {
    if (!$info['db_id']) { echo "\nUeberspringe: {$info['fupa_name']} (nicht zugeordnet)\n"; continue; }

    $url = "https://www.fupa.net/team/$fupaSlug";
    echo "\nLade: {$info['fupa_name']} -> {$info['db_name']}...\n";

    $html = @file_get_contents($url, false, $context);
    if (!$html) { $errors[] = "Konnte {$info['fupa_name']} nicht laden"; echo "  FEHLER: " . end($errors) . "\n"; sleep(2); continue; }

    $redux = extractReduxData($html);
    if (!$redux) { $errors[] = "Keine REDUX_DATA fuer {$info['fupa_name']}"; echo "  FEHLER: " . end($errors) . "\n"; sleep(2); continue; }

    $players = $redux['dataHistory'][0]['TeamPlayersPage']['data']['players'] ?? [];
    if (empty($players)) { $errors[] = "Keine Spieler fuer {$info['fupa_name']}"; echo "  WARNUNG: " . end($errors) . "\n"; sleep(2); continue; }

    $count = 0;
    $usedNumbers = [];
    foreach ($players as $p) {
        $name = trim(trim($p['firstName'] ?? '') . ' ' . trim($p['lastName'] ?? ''));
        if (empty($name)) continue;
        $number = $p['jerseyNumber'] ?? null;
        if ($number !== null) { $number = (int)$number; if (isset($usedNumbers[$number])) $number = null; else $usedNumbers[$number] = true; }
        $position = $positionMap[$p['position'] ?? ''] ?? 'Mittelfeld';
        try { $insertStmt->execute([$info['db_id'], $name, $number, $position]); $count++; } catch (Exception $e) { echo "  Fehler bei $name: " . $e->getMessage() . "\n"; }
    }

    echo "  -> $count Spieler importiert\n";
    $totalImported += $count;
    sleep(2);
}

echo "\n=== Import abgeschlossen ===\n";
echo "Gesamt importiert: $totalImported Spieler\n";
if (!empty($errors)) { echo "\nFehler/Warnungen:\n"; foreach ($errors as $e) echo "  - $e\n"; }
foreach (glob(__DIR__ . '/../data/cache/*.json') as $f) unlink($f);
echo "\nCache geloescht.\n";

function extractReduxData($html) {
    $pos = strpos($html, 'window.REDUX_DATA');
    if ($pos === false) return null;
    $start = strpos($html, '{', $pos);
    if ($start === false) return null;
    $depth = 0;
    for ($i = $start; $i < strlen($html); $i++) {
        if ($html[$i] === '{') $depth++;
        if ($html[$i] === '}') $depth--;
        if ($depth === 0) return json_decode(substr($html, $start, $i - $start + 1), true);
    }
    return null;
}

function buildTeamMapping($standings, $dbTeams, $manualMap) {
    $mapping = [];
    foreach ($standings as $s) {
        $team = $s['team'];
        $fupaName = $team['name']['full'];
        $slug = $team['slug'];
        $info = ['fupa_name' => $fupaName, 'db_id' => null, 'db_name' => null];

        if (isset($manualMap[$fupaName])) {
            foreach ($dbTeams as $db) { if ($db['name'] === $manualMap[$fupaName]) { $info['db_id'] = $db['id']; $info['db_name'] = $db['name']; break; } }
        }
        if (!$info['db_id']) {
            foreach ($dbTeams as $db) { if (strtolower($db['name']) === strtolower($fupaName)) { $info['db_id'] = $db['id']; $info['db_name'] = $db['name']; break; } }
        }
        if (!$info['db_id']) {
            $bestMatch = null; $bestScore = 0;
            foreach ($dbTeams as $db) {
                $dbNorm = strtolower(preg_replace('/\s+(II|III|IV|V)$/i', '', trim($db['name'])));
                $fupaNorm = strtolower(trim($fupaName));
                if (strpos($dbNorm, $fupaNorm) !== false || strpos($fupaNorm, $dbNorm) !== false) {
                    $score = similar_text($dbNorm, $fupaNorm);
                    if ($score > $bestScore) { $bestScore = $score; $bestMatch = $db; }
                }
                similar_text($dbNorm, $fupaNorm, $pct);
                if ($pct > 70 && $pct > $bestScore) { $bestScore = $pct; $bestMatch = $db; }
            }
            if ($bestMatch) { $info['db_id'] = $bestMatch['id']; $info['db_name'] = $bestMatch['name']; }
        }
        $mapping[$slug] = $info;
    }
    return $mapping;
}
