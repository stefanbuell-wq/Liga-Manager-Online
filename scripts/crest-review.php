<?php
/**
 * Pruefseite fuer die Wappen-Zuordnung (nur lokal): php scripts/crest-review.php
 * Erzeugt crest-review.html im Projektroot (gitignored), Aufruf ueber php -S localhost:8000.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$map = json_decode(file_get_contents("$root/data/crests/map.json"), true) ?: [];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

uasort($map, fn($a, $b) => (!empty($a['file']) <=> !empty($b['file'])) ?: ($a['score'] <=> $b['score']));
$rows = '';
$stat = ['ok' => 0, 'check' => 0, 'none' => 0];
foreach ($map as $name => $e) {
    $key = preg_replace('/[^a-zA-Z0-9]/', '', $name);
    $state = empty($e['file']) ? 'none' : ($e['score'] >= 85 ? 'ok' : 'check');
    $stat[$state]++;
    $cands = implode('<br>', array_map(fn($c) => $h("{$c['club']} · {$c['place']} ({$c['score']}) — {$c['id']}"), $e['candidates'] ?? []));
    $rows .= "<tr class='$state'><td>" . $h($name) . "</td>"
        . "<td><img src='img/teams/" . $h($key) . ".gif' onerror=\"this.replaceWith('–')\"></td>"
        . "<td>" . (!empty($e['file']) ? "<img class='hd' src='" . $h($e['file']) . "'>" : '–') . "</td>"
        . "<td>" . $h($e['club'] ?? '') . "<br><small>" . $h($e['place'] ?? '') . "</small></td>"
        . "<td>" . (int) $e['score'] . "</td><td><small>$cands</small></td></tr>\n";
}
$html = <<<HTML
<!doctype html><html lang="de"><meta charset="utf-8"><title>Wappen-Pruefung</title>
<style>body{font-family:system-ui;margin:16px}table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #ddd;padding:6px;vertical-align:middle;text-align:left}
img{height:28px}img.hd{height:72px}tr.none{background:#fde8e8}tr.check{background:#fff6d6}small{color:#666}</style>
<h1>Wappen-Pruefung</h1><p>Sicher (Score ≥ 85): {$stat['ok']} · bitte pruefen (gelb): {$stat['check']} · nicht gefunden (rot): {$stat['none']}</p>
<table><tr><th>Team (DB)</th><th>alt</th><th>neu</th><th>fussball.de</th><th>Score</th><th>Kandidaten</th></tr>
$rows</table>
HTML;
file_put_contents("$root/crest-review.html", $html);
echo "crest-review.html: ok {$stat['ok']}, pruefen {$stat['check']}, fehlt {$stat['none']}\n";
