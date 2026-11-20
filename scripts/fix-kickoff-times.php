<?php
/**
 * Anstosszeiten reparieren: Bei der Uebernahme aus den .l98-Dateien wurden die Zeiten in UTC statt in
 * deutscher Zeit gespeichert (im Winter 1 Stunde, im Sommer 2 Stunden zu frueh).
 *
 * Vergleicht jedes Spiel mit dem Zeitstempel in ligen/<datei>.l98:
 *   steht in der Datenbank noch der UTC-Wert  -> wird auf deutsche Zeit korrigiert
 *   steht dort etwas anderes (spaeter von Hand geaendert) -> bleibt unveraendert
 *
 * Aufruf (im Webroot):  php scripts/fix-kickoff-times.php [--dir=<ordner mit .l98>]            (nur anzeigen)
 *                       php scripts/fix-kickoff-times.php [--dir=<ordner mit .l98>] --apply    (schreiben)
 * Standardordner: ligen/
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/LmoParser.php';

$apply = in_array('--apply', $argv, true);
$ligaDir = __DIR__ . '/../ligen';
foreach ($argv as $a) if (str_starts_with($a, '--dir=')) $ligaDir = rtrim(substr($a, 6), '/');
if (!is_dir($ligaDir)) { fwrite(STDERR, "Ordner nicht gefunden: $ligaDir\n"); exit(1); }
$pdo = LmoDatabase::getInstance();
$utc = new DateTimeZone('UTC');
$de = new DateTimeZone('Europe/Berlin');

$leagues = $pdo->query("SELECT id, file FROM leagues ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$find = $pdo->prepare("SELECT m.id, m.match_date, m.match_time FROM matches m
    JOIN teams th ON th.id = m.home_team_id JOIN teams tg ON tg.id = m.guest_team_id
    WHERE m.league_id = ? AND m.round_nr = ? AND th.original_id = ? AND tg.original_id = ?");
$upd = $pdo->prepare("UPDATE matches SET match_date = ?, match_time = ? WHERE id = ?");

$total = ['fixed' => 0, 'ok' => 0, 'changed_by_hand' => 0, 'not_found' => 0, 'no_file' => 0];
$examples = [];
if ($apply) $pdo->beginTransaction();
foreach ($leagues as $l) {
    if (!is_file("$ligaDir/" . basename($l['file']))) { $total['no_file']++; continue; }
    try {
        $data = (new LmoParser($ligaDir))->load($l['file']);
    } catch (Throwable $e) {
        echo "FEHLER {$l['file']}: {$e->getMessage()}\n";
        continue;
    }
    $fixed = 0;
    foreach ($data['matches'] as $round => $ms) {
        foreach ($ms as $m) {
            if (empty($m['timestamp'])) continue;
            $find->execute([$l['id'], $round, $m['home_id'], $m['guest_id']]);
            $row = $find->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $total['not_found']++; continue; }
            $u = (new DateTimeImmutable('@' . $m['timestamp']))->setTimezone($utc);
            $d = (new DateTimeImmutable('@' . $m['timestamp']))->setTimezone($de);
            if ($row['match_date'] === $d->format('d.m.Y') && $row['match_time'] === $d->format('H:i')) { $total['ok']++; continue; }
            if ($row['match_date'] === $u->format('d.m.Y') && $row['match_time'] === $u->format('H:i')) {
                if ($apply) $upd->execute([$d->format('d.m.Y'), $d->format('H:i'), $row['id']]);
                $total['fixed']++; $fixed++;
                if (count($examples) < 8) $examples[] = "{$l['file']} Sp.$round {$m['home']} – {$m['guest']}: {$row['match_date']} {$row['match_time']} -> " . $d->format('d.m.Y H:i');
                continue;
            }
            $total['changed_by_hand']++;
        }
    }
    if ($fixed) echo str_pad($l['file'], 32) . " $fixed Zeiten\n";
}
if ($apply) {
    $pdo->commit();
    foreach (glob(__DIR__ . '/../data/cache/*.json') ?: [] as $f) @unlink($f);
}
echo "\nBeispiele:\n  " . implode("\n  ", $examples) . "\n\n";
printf("%s: %d korrigiert, %d schon richtig, %d von Hand geaendert (unveraendert), %d nicht gefunden, %d Ligen ohne .l98-Datei\n",
    $apply ? 'GESCHRIEBEN' : 'PROBELAUF (nichts geschrieben, mit --apply schreiben)',
    $total['fixed'], $total['ok'], $total['changed_by_hand'], $total['not_found'], $total['no_file']);
