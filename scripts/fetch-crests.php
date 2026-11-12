<?php
/**
 * Vereinswappen in hoher Aufloesung von fussball.de holen (nur lokal per CLI).
 *
 *   php scripts/fetch-crests.php              # alle Vereine ohne Eintrag in der Zuordnung
 *   php scripts/fetch-crests.php --redo       # alle neu suchen (manuelle Eintraege bleiben)
 *   php scripts/fetch-crests.php --download   # nur Bilder laut Zuordnung (neu) laden
 *
 * Zuordnung: data/crests/map.json  { "<Teamname>": {"id": "<fussball.de-ID>", "club": "...", "place": "...",
 *                                                    "score": 0-100, "manual": true|false, "candidates": [...] } }
 * Bilder:    img/teams/hd/<Schluessel>.png (350x350), Schluessel = Name ohne Sonderzeichen wie bei img/teams/*.gif
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const FORMAT = 16; // 350x350 PNG (9 = 200, 14 = 261, 19 = 890)
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) hafo-crest-import';

$root = dirname(__DIR__);
$mapFile = "$root/data/crests/map.json";
$outDir = "$root/img/teams/hd";
@mkdir(dirname($mapFile), 0775, true);
@mkdir($outDir, 0775, true);

$redo = in_array('--redo', $argv, true);
$onlyDownload = in_array('--download', $argv, true);

$map = is_file($mapFile) ? json_decode(file_get_contents($mapFile), true) : [];
$pdo = new PDO("sqlite:$root/data/database.sqlite");
$names = $pdo->query("SELECT DISTINCT name FROM teams WHERE name <> '' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

function http_get(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => UA]);
    // Windows: Zertifikate aus dem System-Speicher (lokaler Virenscanner/Proxy prueft TLS)
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    usleep(700000); // hoeflich bleiben
    return ($body !== false && $code === 200) ? $body : null;
}

function norm(string $s): string {
    $s = mb_strtolower($s);
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e']);
    $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Zweite Mannschaften, Jahreszahlen-Zusaetze usw. entfernen -> Vereinsname */
function clubName(string $team): string {
    $s = preg_replace('/\s+(II|III|IV|2|3|U\s?2[0-3]|Amat\.?|Amateure|\(A\)|A)\s*$/u', '', trim($team));
    return trim($s);
}

/** Kennwoerter fuer den Vergleich (ohne Vereinskuerzel und Ortsfuellwoerter) */
function keyWords(string $s): array {
    static $stop = ['sc','fc','sv','tsv','tus','vfl','vfb','sg','fsv','tv','tsg','ssv','hsv','usc','etsv','sv.',
        'hamburg','hh','von','e','v','ev','der','und','de','1','93','05','08','09','1893','1905','1908'];
    $w = array_filter(explode(' ', norm($s)), fn($x) => $x !== '' && !in_array($x, $stop, true));
    return array_values(array_unique($w));
}

function searchClubs(string $q): array {
    $html = http_get('https://www.fussball.de/suche/-/text/' . rawurlencode($q) . '/restriction/CLUB_AND_TEAM');
    if (!$html) return [];
    $i = strpos($html, 'id="clublist"');
    if ($i === false) return [];
    $html = substr($html, $i, 200000);
    preg_match_all('~<a href="https://www\.fussball\.de/verein/[^"]*/-/id/([A-Z0-9]+)"[^>]*>.*?<p class="name">(.*?)<span.*?<p class="sub">(.*?)</p>~s', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $r) {
        $out[] = ['id' => $r[1], 'club' => html_entity_decode(trim(strip_tags($r[2])), ENT_QUOTES, 'UTF-8'),
                  'place' => trim(html_entity_decode(strip_tags($r[3]), ENT_QUOTES, 'UTF-8'), " \xC2\xA0")];
    }
    return $out;
}

/** Wort passt, wenn gleich oder eines der Anfang des anderen ist (Ahrensburger ~ Ahrensburg) */
function wordMatch(string $x, string $y): bool {
    if ($x === $y) return true;
    $min = min(strlen($x), strlen($y));
    return $min >= 4 && (str_starts_with($x, $y) || str_starts_with($y, $x));
}

function scoreCandidate(string $team, array $c): int {
    $a = keyWords(clubName($team));
    $b = keyWords($c['club']);
    if (!$a || !$b) {
        $s = norm(clubName($team)) === norm($c['club']) ? 70 : 20;
    } else {
        $hitA = count(array_filter($a, fn($x) => (bool) array_filter($b, fn($y) => wordMatch($x, $y))));
        $hitB = count(array_filter($b, fn($y) => (bool) array_filter($a, fn($x) => wordMatch($x, $y))));
        // fussball.de kuerzt oft auf den Ort ("Ahrensburg"): alle Woerter des Kandidaten gefunden zaehlt stark
        $s = (int) round(80 * max($hitA / count($a), $hitB === count($b) ? 0.9 : $hitB / count($b)) * min(1, ($hitA + $hitB) / 2));
        if (norm(clubName($team)) === norm($c['club'])) $s = 85;
    }
    // Abkuerzung: "BU" = Barmbek-Uhlenhorst
    $initials = implode('', array_map(fn($w) => $w[0], array_merge(...array_map(fn($w) => explode(' ', str_replace('-', ' ', $w)), keyWords(str_replace('-', ' ', clubName($team)))))));
    if (preg_match('/^[A-Z]{2,4}$/', trim($c['club'])) && strtolower(trim($c['club'])) === $initials) $s = max($s, 75);
    // Hamburger Raum bevorzugen (PLZ 20000-25999, 21xxx Niedersachsen-Umland)
    if (preg_match('/^(\d{5})/', $c['place'], $p)) {
        $plz = (int) $p[1];
        if ($plz >= 20000 && $plz <= 22999) $s += 15;
        elseif ($plz >= 23000 && $plz <= 29999) $s += 8;
    }
    return min(100, $s);
}

// --rescore: gespeicherte Kandidaten neu bewerten (keine Suche)
if (in_array('--rescore', $argv, true)) {
    foreach ($map as $name => &$e) {
        if (!empty($e['manual'])) continue;
        foreach ($e['candidates'] as &$c) $c['score'] = scoreCandidate($name, $c);
        unset($c);
        usort($e['candidates'], fn($x, $y) => $y['score'] <=> $x['score']);
        $b = $e['candidates'][0] ?? null;
        $e['id'] = $b && $b['score'] >= 50 ? $b['id'] : null;
        $e['club'] = $b['club'] ?? null; $e['place'] = $b['place'] ?? null; $e['score'] = $b['score'] ?? 0;
        printf("%-40s -> %-35s %-22s %3d\n", $name, $e['club'] ?? '-', $e['place'] ?? '', $e['score']);
    }
    unset($e);
    $onlyDownload = true;
}

// Zusatz-Suchbegriffe fuer Namen, die fussball.de anders fuehrt: data/crests/aliases.json {"Team": "Suchbegriff"}
$aliases = is_file(dirname($mapFile) . '/aliases.json') ? json_decode(file_get_contents(dirname($mapFile) . '/aliases.json'), true) : [];

$todo = 0;
foreach ($names as $name) {
    if ($onlyDownload) break;
    $alias = $aliases[$name] ?? null;
    if (is_array($alias) && !empty($alias['none'])) {   // bewusst kein Wappen (z. B. aufgeloester Verein)
        $map[$name] = ['id' => null, 'club' => null, 'place' => null, 'score' => 0, 'manual' => true, 'candidates' => []];
        continue;
    }
    if (isset($map[$name]) && (!$redo || !empty($map[$name]['manual'])) && !($alias && empty($map[$name]['alias_done']))) continue;
    $todo++;
    $club = is_array($alias) ? $alias['q'] : ($alias ?? clubName($name));
    $cands = searchClubs($club);
    if (!$cands) {
        // zweiter Versuch: nur Kennwoerter
        $kw = implode(' ', keyWords($club));
        if ($kw && $kw !== norm($club)) $cands = searchClubs($kw);
        // dritter Versuch: laengstes Kennwort (meist der Ort)
        if (!$cands && $kw) {
            $w = keyWords($club); usort($w, fn($x, $y) => strlen($y) <=> strlen($x));
            if ($w[0] !== $kw) $cands = searchClubs($w[0]);
        }
    }
    foreach ($cands as &$c) $c['score'] = scoreCandidate($name, $c);
    unset($c);
    usort($cands, fn($x, $y) => $y['score'] <=> $x['score']);
    $best = $cands[0] ?? null;
    $manual = false;
    // Alias mit "pick": Kandidat, dessen Name genau so lautet (sonst enthaelt), von Hand festgelegt
    if (is_array($alias) && isset($alias['pick'])) {
        $p = mb_strtolower($alias['pick']);
        $hit = array_values(array_filter($cands, fn($c) => mb_strtolower($c['club']) === $p))
            ?: array_values(array_filter($cands, fn($c) => str_contains(mb_strtolower($c['club']), $p)));
        if ($hit) { $best = $hit[0]; $best['score'] = 100; $manual = true; }
        else echo "!! pick '{$alias['pick']}' nicht gefunden fuer $name\n";
    }
    $map[$name] = [
        'id' => $best && $best['score'] >= 50 ? $best['id'] : null,
        'club' => $best['club'] ?? null,
        'place' => $best['place'] ?? null,
        'score' => $best['score'] ?? 0,
        'manual' => $manual,
        'alias_done' => (bool) $alias,
        'candidates' => array_slice($cands, 0, 6),
    ];
    printf("%-40s -> %-35s %-22s %3d\n", $name, $best['club'] ?? '-', $best['place'] ?? '', $best['score'] ?? 0);
    file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

// Bilder laden
$ok = $miss = 0;
foreach ($names as $name) {
    $e = $map[$name] ?? null;
    $key = preg_replace('/[^a-zA-Z0-9]/', '', $name);
    $file = "$outDir/$key.png";
    if (!$e || empty($e['id'])) {
        // keine (oder keine sichere) Zuordnung mehr: altes HD-Bild entfernen, damit das GIF gilt
        if (is_file($file)) { unlink($file); echo "entfernt: $name\n"; }
        if ($e) { unset($map[$name]['file'], $map[$name]['file_id']); }
        $miss++; continue;
    }
    if (is_file($file) && ($e['file_id'] ?? '') === $e['id']) { $ok++; continue; }
    // groesste verfuegbare Fassung: 350, 261, 200, 99 px
    $img = null; $info = false;
    foreach ([FORMAT, 14, 9, 2] as $fmt) {
        $img = http_get('https://www.fussball.de/export.media/-/action/getLogo/format/' . $fmt . '/id/' . $e['id']);
        $info = $img ? @getimagesizefromstring($img) : false;
        if ($info && $info['mime'] === 'image/png') break;
    }
    if (!$info || $info['mime'] !== 'image/png') { echo "Bild fehlt: $name\n"; $miss++; continue; }
    file_put_contents($file, $img);
    $map[$name]['file_id'] = $e['id'];
    $map[$name]['file'] = "img/teams/hd/$key.png";
    $ok++;
}
file_put_contents($mapFile, json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nGesucht: $todo, Bilder: $ok, ohne Wappen: $miss\n";
