<?php
/**
 * Verknuepft alte Vorschauen und Spielberichte nachtraeglich mit den Spielen.
 *
 *  - Vorschau: jede fette Zeile "Heim (6.) vs. Gast (16.)" -> Spiel 2 Tage vor bis 10 Tage nach Erscheinen
 *  - Bericht:  fette Ergebniszeile "Heim – Gast 2:1"       -> Spiel 5 Tage vor bis 1 Tag nach Erscheinen
 *
 * Nur ergaenzend (INSERT OR IGNORE), bestehende Verknuepfungen bleiben unveraendert.
 * Zuordnung nur, wenn genau ein Spiel eindeutig am besten passt.
 *
 * Aufruf: php scripts/link-old-news.php            (Probelauf, schreibt nichts)
 *         php scripts/link-old-news.php --apply    (schreibt in match_news)
 *         php scripts/link-old-news.php --verbose  (zeigt jede Zuordnung)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/NewsContent.php';

$apply   = in_array('--apply', $argv, true);
$verbose = in_array('--verbose', $argv, true);
$pdo = LmoDatabase::getInstance();

const STOP = ['sc','fc','sv','tsv','tus','vfb','vfl','fsv','ssv','sg','usc','ev','ev.','e','v','von','der','die','und',
    'ii','iii','iv','u23','u21','u19','hamburg','hamburger','club','verein','sport','sportverein','fussball','team',
    'spvg','spvgg','fsc','tsg','asv','vfr','svs','sportclub','fussballclub','turnerschaft','turn','und'];

function words(string $s): array
{
    $s = mb_strtolower(html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'), 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e']);
    $s = preg_replace('/\([^)]*\)/', ' ', $s);            // "(6.)" Tabellenplatz
    preg_match_all('/[a-z][a-z0-9]*/', $s, $m);
    return $m[0];
}

/** Kennwoerter eines Vereinsnamens (ohne SC/FC/II/Hamburg ...) */
function keyWords(string $name): array
{
    $k = array_values(array_unique(array_filter(words($name), fn($w) => strlen($w) >= 4 && !in_array($w, STOP, true))));
    // Kurznamen wie "ETV Hamburg" -> "etv"
    if (!$k) $k = array_values(array_unique(array_filter(words($name), fn($w) => strlen($w) >= 3 && !in_array($w, STOP, true))));
    return $k;
}

/** Wie gut passt ein Textstueck ("Eintr. Norderstedt (9)") zum Vereinsnamen aus der DB? 0 = gar nicht */
function teamScore(string $side, string $dbName): float
{
    $key = keyWords($dbName);
    if (!$key) return 0;
    $sw = words($side);
    $hit = 0;
    foreach ($key as $k) {
        foreach ($sw as $w) {
            if ($w === $k || (strlen($w) >= 4 && strpos($k, $w) === 0)) { $hit++; break; }
        }
    }
    if (!$hit) return 0;
    $score = $hit / count($key);
    // Reserve (II) im Text muss zur Reserve in der DB passen
    $resText = (bool)preg_match('/\b(ii|iii|2\.|u23)\b/i', $side);
    $resDb   = (bool)preg_match('/\b(ii|iii|2\.|u23)\b/i', $dbName);
    if ($resText !== $resDb) $score -= 0.5;
    return $score;
}

// Spiele nach Tag indexieren
$byDay = []; $byLeague = [];
$rows = $pdo->query("
    SELECT m.id, m.league_id, m.match_date, th.name AS home, tg.name AS guest
    FROM matches m JOIN teams th ON th.id = m.home_team_id JOIN teams tg ON tg.id = m.guest_team_id
    WHERE m.match_date IS NOT NULL AND m.match_date != ''
");
foreach ($rows as $r) {
    if (!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', $r['match_date'], $d)) continue;
    $day = intdiv(gmmktime(0, 0, 0, (int)$d[2], (int)$d[1], (int)$d[3]), 86400);
    $r['day'] = $day;
    $byDay[$day][] = $r;
    $byLeague[$r['league_id']][] = $r;
}

/** Bestes Spiel fuer ein Paar unter den Kandidaten, nur wenn eindeutig */
function bestMatch(iterable $cands, int $day, string $a, string $b): ?array
{
    $best = null; $bestScore = 0; $tie = false;
    foreach ($cands as $m) {
        $sh = teamScore($a, $m['home']); $sg = teamScore($b, $m['guest']);
        if ($sh <= 0 || $sg <= 0) continue;
        $s = $sh + $sg - abs($m['day'] - $day) * 0.001;
        if ($s > $bestScore + 0.0001) { $best = $m; $bestScore = $s; $tie = false; }
        elseif (abs($s - $bestScore) <= 0.0001 && $best && $best['id'] !== $m['id']) { $tie = true; }
    }
    return ($best && !$tie && $bestScore >= 0.9) ? $best : null;
}

function window(array $byDay, int $day, int $from, int $to): array
{
    $out = [];
    for ($o = $from; $o <= $to; $o++) foreach ($byDay[$day + $o] ?? [] as $m) $out[] = $m;
    return $out;
}

$existing = [];
foreach ($pdo->query("SELECT match_id, news_id FROM match_news") as $r) $existing[$r['match_id'] . '-' . $r['news_id']] = true;

$ins = $pdo->prepare("INSERT OR IGNORE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, ?)");
$stats = ['vorschau_news' => 0, 'vorschau_links' => 0, 'bericht_news' => 0, 'bericht_links' => 0, 'paare_ohne_spiel' => 0];
if ($apply) $pdo->beginTransaction();

$news = $pdo->query("SELECT id, title, content, timestamp FROM news WHERE timestamp > 0 ORDER BY id");
foreach ($news as $n) {
    $html = (string)$n['content'];
    $day = intdiv((int)$n['timestamp'], 86400);
    $isPreview = stripos($n['title'], 'vorschau') !== false;

    $pairs = [];
    if ($isPreview) {
        preg_match_all('#<b>\s*([^<>]{3,80}?)\s+vs\.?\s+([^<>]{3,80}?)\s*</b>#iu', $html, $m, PREG_SET_ORDER);
        foreach ($m as $x) $pairs[] = [$x[1], preg_replace('/\*+$/', '', $x[2])];
        [$from, $to, $conf] = [-2, 10, 0.5];
    } else {
        $t = NewsContent::teamsFromScoreLine($html);
        if (!$t && preg_match('#^\s*(.{3,60}?)\s+[–-]\s+(.{3,60}?)\s+\d+\s*:\s*\d+#u', $n['title'], $x)) $t = [$x[1], $x[2]];
        if ($t) $pairs[] = $t;
        [$from, $to, $conf] = [-5, 1, 0.8];
    }
    if (!$pairs) continue;

    $added = 0;
    $found = []; $missing = []; $leagues = [];
    $cands = window($byDay, $day, $from, $to);
    foreach ($pairs as $pair) {
        $m = bestMatch($cands, $day, $pair[0], $pair[1]);
        if ($m) { $found[] = [$pair, $m]; $leagues[$m['league_id']] = true; } else $missing[] = $pair;
    }
    // Verlegte Spiele: in derselben Liga (Saison) suchen, auch wenn das Datum nicht mehr passt
    foreach ($missing as $pair) {
        $m = null;
        foreach (array_keys($leagues) as $lid) {
            $c = array_filter($byLeague[$lid], fn($x) => $x['day'] >= $day - 3 && $x['day'] <= $day + 150);
            if ($m = bestMatch($c, $day, $pair[0], $pair[1])) break;
        }
        if ($m) { $found[] = [$pair, $m]; continue; }
        $stats['paare_ohne_spiel']++;
        if ($verbose) echo "?? #{$n['id']} " . trim($pair[0]) . ' / ' . trim($pair[1]) . ' (' . gmdate('d.m.Y', $day * 86400) . ")
";
    }
    foreach ($found as [[$a, $b], $m]) {
        $k = $m['id'] . '-' . $n['id'];
        if (isset($existing[$k])) continue;
        $existing[$k] = true;
        if ($apply) $ins->execute([$m['id'], $n['id'], $conf]);
        $added++;
        if ($verbose) echo "#{$n['id']} " . trim($a) . ' / ' . trim($b) . "  ->  {$m['home']} - {$m['guest']} ({$m['match_date']})
";
    }
    if ($added) {
        $stats[$isPreview ? 'vorschau_news' : 'bericht_news']++;
        $stats[$isPreview ? 'vorschau_links' : 'bericht_links'] += $added;
    }
}
if ($apply) $pdo->commit();

echo ($apply ? "GESCHRIEBEN" : "PROBELAUF (nichts geschrieben)") . "\n";
foreach ($stats as $k => $v) echo str_pad($k, 18) . $v . "\n";
