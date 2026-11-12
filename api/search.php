<?php
/**
 * Gesamtsuche: Vereine, Spiele/Ergebnisse, Ligen, Spieler, Berichte, Forum (nur oeffentlich)
 *
 * GET q=<suchtext>                 -> alle Bereiche, je die besten Treffer + Gesamtzahl
 * GET q=<suchtext>&type=<bereich>  -> nur ein Bereich, seitenweise (offset, limit)
 *     Bereiche: vereine, spiele, ligen, spieler, berichte, forum
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/NewsContent.php';

const PREVIEW = ['vereine' => 6, 'spiele' => 10, 'ligen' => 6, 'spieler' => 8, 'berichte' => 6, 'forum' => 6];
const STOP = ['vs', 'gegen', 'und', 'der', 'die', 'das', 'im', 'in', 'am', 'beim', 'von', 'gg'];

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Vergleichsform: klein, Umlaute ausgeschrieben, nur Buchstaben/Ziffern */
function norm(?string $s): string
{
    $s = mb_strtolower((string)$s, 'UTF-8');
    $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ó' => 'o', 'ç' => 'c', 'ş' => 's', 'ı' => 'i']);
    return ' ' . trim(preg_replace('/[^a-z0-9]+/', ' ', $s)) . ' ';
}

/** Saisonbeginn aus Liganame ("15/16", "2004/2005") fuer chronologische Sortierung */
function seasonKey(string $name): int
{
    if (preg_match('#(\d{4})/\d{4}#', $name, $m)) return (int)$m[1];
    if (preg_match('#(\d{2})/\d{2}#', $name, $m)) return 2000 + (int)$m[1];
    return 0;
}

/** Datum TT.MM.JJJJ -> JJJJMMTT zum Sortieren */
function dateKey(?string $d): string
{
    return preg_match('/^(\d{2})\.(\d{2})\.(\d{4})/', (string)$d, $m) ? $m[3] . $m[2] . $m[1] : '00000000';
}

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$type = (string)($_GET['type'] ?? '');
$offset = max(0, (int)($_GET['offset'] ?? 0));
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
if (mb_strlen($q) < 2) out(['success' => false, 'error' => 'Bitte mindestens 2 Zeichen eingeben.'], 400);
if ($type !== '' && !isset(PREVIEW[$type])) out(['success' => false, 'error' => 'Unbekannter Bereich'], 400);

// Suchwoerter: Saison "15/16", Jahre "2015", sonstige Woerter
$seasons = [];
if (preg_match_all('#\b(\d{2})\s*/\s*(\d{2})\b#', $q, $m, PREG_SET_ORDER)) {
    foreach ($m as $x) $seasons[] = [$x[1], $x[2]];
    $q2 = preg_replace('#\b\d{2}\s*/\s*\d{2}\b#', ' ', $q);
} else {
    $q2 = $q;
}
$years = []; $words = [];
foreach (array_filter(explode(' ', trim(norm($q2)))) as $w) {
    if (preg_match('/^(19|20)\d{2}$/', $w)) $years[] = $w;
    elseif (strlen($w) >= 2 && !in_array($w, STOP, true)) $words[] = $w;
}
$words = array_slice(array_values(array_unique($words)), 0, 6);
$years = array_slice(array_values(array_unique($years)), 0, 2);

$pdo = LmoDatabase::getInstance();
$want = fn(string $t) => $type === '' || $type === $t;
$take = fn(string $t) => $type === '' ? PREVIEW[$t] : $limit;
$skip = fn() => $type === '' ? 0 : $offset;
$result = [];

/** Liga-Saisonbezug: passt Liga zu Jahr/Saison aus der Suche? */
$leagueFitsTime = function (string $name, string $file) use ($years, $seasons): bool {
    if (!$years && !$seasons) return true;
    $hay = $name . ' ' . $file;
    foreach ($seasons as [$a, $b]) {
        if (strpos($hay, "$a/$b") !== false || strpos($hay, "20$a/20$b") !== false || strpos($file, $a . $b) !== false) return true;
    }
    foreach ($years as $y) {
        $yy = substr($y, 2);
        if (strpos($hay, $y) !== false || preg_match("#\\b$yy/|/$yy\\b#", $name)) return true;
    }
    return false;
};

// ---------- Ligen und Vereine (klein genug fuer PHP) ----------
$leagues = [];
foreach ($pdo->query("SELECT id, file, name FROM leagues") as $l) {
    $l['n'] = norm($l['name'] . ' ' . $l['file']);
    $l['season'] = seasonKey($l['name']);
    $leagues[$l['id']] = $l;
}
$teams = $pdo->query("SELECT id, league_id, name FROM teams WHERE name IS NOT NULL AND name != ''")->fetchAll(PDO::FETCH_ASSOC);

// Woerter, die einen Vereinsnamen treffen (Wortanfang), und welche Teams sie treffen
$teamIdsByWord = []; $clubHits = [];
foreach ($words as $w) {
    if (strlen($w) < 3) continue;
    foreach ($teams as $t) {
        $n = norm($t['name']);
        if (strpos($n, ' ' . $w) !== false) {
            $teamIdsByWord[$w][] = (int)$t['id'];
            $clubHits[$t['name']][$w] = true;
        }
    }
}

if ($want('vereine')) {
    $clubs = [];
    if ($clubHits) {
        $best = max(array_map('count', $clubHits));
        foreach ($clubHits as $name => $hit) {
            if (count($hit) < $best) continue;
            $seasonsOf = [];
            foreach ($teams as $t) {
                if ($t['name'] !== $name || !isset($leagues[$t['league_id']])) continue;
                $l = $leagues[$t['league_id']];
                $seasonsOf[$l['id']] = ['file' => $l['file'], 'name' => $l['name'], 's' => $l['season']];
            }
            usort($seasonsOf, fn($a, $b) => $b['s'] <=> $a['s']);
            foreach ($seasonsOf as &$so) unset($so['s']);
            unset($so);
            $clubs[] = ['name' => $name, 'logo' => NewsContent::teamLogo($name), 'leagues' => array_values($seasonsOf)];
        }
        usort($clubs, fn($a, $b) => count($b['leagues']) <=> count($a['leagues']) ?: strcmp($a['name'], $b['name']));
    }
    $result['vereine'] = ['total' => count($clubs), 'items' => array_slice($clubs, $skip(), $take('vereine'))];
}

if ($want('ligen')) {
    $found = [];
    foreach ($leagues as $l) {
        $ok = true;
        foreach ($words as $w) if (strpos($l['n'], $w) === false) { $ok = false; break; }
        if ($ok && !$words && !$years && !$seasons) $ok = false;
        if ($ok && $leagueFitsTime($l['name'], $l['file'])) $found[] = ['file' => $l['file'], 'name' => $l['name'], 's' => $l['season']];
    }
    usort($found, fn($a, $b) => $b['s'] <=> $a['s'] ?: strcmp($a['name'], $b['name']));
    foreach ($found as &$f) unset($f['s']);
    unset($f);
    $result['ligen'] = ['total' => count($found), 'items' => array_slice($found, $skip(), $take('ligen'))];
}

// ---------- Spiele: jedes Vereinswort muss Heim oder Gast treffen ----------
if ($want('spiele')) {
    $items = []; $total = 0;
    $teamWords = array_keys($teamIdsByWord);
    if ($teamWords) {
        $conds = []; $params = [];
        foreach ($teamWords as $i => $w) {
            $ids = implode(',', array_unique($teamIdsByWord[$w]));
            $conds[] = "(m.home_team_id IN ($ids) OR m.guest_team_id IN ($ids))";
        }
        if ($years) {
            $yc = [];
            foreach ($years as $i => $y) { $yc[] = "m.match_date LIKE :y$i"; $params[":y$i"] = "%.$y"; }
            $conds[] = '(' . implode(' OR ', $yc) . ')';
        }
        // Liga-Woerter (z. B. "oberliga") und Saison "15/16" schraenken die Liga ein;
        // Woerter, die weder Verein noch Liga treffen ("pokal"), werden ignoriert
        $leagueWords = array_filter(array_diff($words, $teamWords), function ($w) use ($leagues) {
            foreach ($leagues as $l) if (strpos($l['n'], $w) !== false) return true;
            return false;
        });
        if ($leagueWords || $seasons) {
            $allowed = [];
            foreach ($leagues as $l) {
                $ok = true;
                foreach ($leagueWords as $w) if (strpos($l['n'], $w) === false) { $ok = false; break; }
                if ($ok && $seasons) {
                    $ok = false;
                    foreach ($seasons as [$a, $b]) {
                        if (strpos($l['name'], "$a/$b") !== false || strpos($l['name'], "20$a/20$b") !== false || strpos($l['file'], $a . $b) !== false) $ok = true;
                    }
                }
                if ($ok) $allowed[] = (int)$l['id'];
            }
            $conds[] = $allowed ? 'm.league_id IN (' . implode(',', $allowed) . ')' : '0';
        }
        $where = implode(' AND ', $conds);
        $rows = $pdo->prepare("
            SELECT m.id, m.league_id, m.round_nr, m.home_goals, m.guest_goals, m.match_date,
                   th.name AS home, tg.name AS guest,
                   (SELECT mn.news_id FROM match_news mn JOIN news n ON n.id = mn.news_id WHERE mn.match_id = m.id
                    ORDER BY (n.title LIKE 'Vorschau%'), mn.confidence DESC, n.timestamp DESC LIMIT 1) AS news_id
            FROM matches m JOIN teams th ON th.id = m.home_team_id JOIN teams tg ON tg.id = m.guest_team_id
            WHERE $where");
        $rows->execute($params);
        $all = $rows->fetchAll(PDO::FETCH_ASSOC);
        // Erst gespielte Partien (neueste zuerst), danach kommende (naechste zuerst)
        $today = date('Ymd');
        usort($all, function ($a, $b) use ($today) {
            $ka = dateKey($a['match_date']); $kb = dateKey($b['match_date']);
            $fa = $ka > $today; $fb = $kb > $today;
            if ($fa !== $fb) return $fa ? 1 : -1;
            return $fa ? strcmp($ka, $kb) : (strcmp($kb, $ka) ?: $b['id'] <=> $a['id']);
        });
        $total = count($all);
        foreach (array_slice($all, $skip(), $take('spiele')) as $r) {
            $l = $leagues[$r['league_id']] ?? null;
            $played = $r['home_goals'] !== null && $r['home_goals'] !== '' && (int)$r['home_goals'] >= 0 && $r['guest_goals'] !== null && (int)$r['guest_goals'] >= 0;
            $items[] = [
                'date' => $r['match_date'] ?: null,
                'home' => $r['home'], 'guest' => $r['guest'],
                'home_logo' => NewsContent::teamLogo($r['home']), 'guest_logo' => NewsContent::teamLogo($r['guest']),
                'score' => $played ? $r['home_goals'] . ':' . $r['guest_goals'] : null,
                'league' => $l['name'] ?? '', 'league_file' => $l['file'] ?? '', 'round' => (int)$r['round_nr'],
                'news_id' => $r['news_id'] ? (int)$r['news_id'] : null,
            ];
        }
    }
    $result['spiele'] = ['total' => $total, 'items' => $items];
}

// ---------- Spieler (Kader) ----------
if ($want('spieler')) {
    $found = [];
    if ($words) {
        $rows = $pdo->query("SELECT p.id, p.name, p.number, p.position, t.name AS team, t.league_id
                             FROM players p JOIN teams t ON t.id = p.team_id");
        foreach ($rows as $p) {
            $pn = norm($p['name']); $hay = $pn . norm($p['team']);
            $inName = false; $ok = true;
            foreach ($words as $w) {
                if (strpos($pn, ' ' . $w) !== false) $inName = true;
                elseif (strpos($hay, ' ' . $w) === false) { $ok = false; break; }
            }
            if (!$ok || !$inName) continue;
            $l = $leagues[$p['league_id']] ?? null;
            if ($l && !$leagueFitsTime($l['name'], $l['file'])) continue;
            $found[] = ['name' => $p['name'], 'number' => $p['number'], 'position' => $p['position'], 'team' => $p['team'],
                        'team_logo' => NewsContent::teamLogo($p['team']),
                        'league' => $l['name'] ?? '', 'league_file' => $l['file'] ?? '', 'lid' => $l['season'] ?? 0];
        }
        usort($found, fn($a, $b) => $b['lid'] <=> $a['lid'] ?: strcmp($a['name'], $b['name']));
        foreach ($found as &$f) unset($f['lid']);
        unset($f);
    }
    $result['spieler'] = ['total' => count($found), 'items' => array_slice($found, $skip(), $take('spieler'))];
}

// ---------- Volltext (Berichte, Forum) ----------
$ftsTerms = [];
preg_match_all('/[\p{L}\p{N}]+/u', $q, $w);
foreach ($w[0] as $t) if (mb_strlen($t) >= 2 && !in_array(mb_strtolower($t), STOP, true)) $ftsTerms[] = '"' . $t . '"*';
$fts = implode(' ', array_slice($ftsTerms, 0, 6));

if ($want('berichte')) {
    $items = []; $total = 0;
    if ($fts !== '') {
        $c = $pdo->prepare("SELECT COUNT(*) FROM news_fts WHERE news_fts MATCH ?");
        $c->execute([$fts]);
        $total = (int)$c->fetchColumn();
        $s = $pdo->prepare("SELECT n.id, n.title, n.timestamp, n.author, substr(n.content, 1, 2500) AS head,
                                   EXISTS (SELECT 1 FROM match_news mn WHERE mn.news_id = n.id) AS linked
                            FROM news n WHERE n.id IN (SELECT rowid FROM news_fts WHERE news_fts MATCH :q)
                            ORDER BY n.timestamp DESC LIMIT :l OFFSET :o");
        $s->bindValue(':q', $fts);
        $s->bindValue(':l', $take('berichte'), PDO::PARAM_INT);
        $s->bindValue(':o', $skip(), PDO::PARAM_INT);
        $s->execute();
        foreach ($s as $r) {
            $items[] = ['id' => (int)$r['id'], 'title' => $r['title'],
                        'date' => $r['timestamp'] > 0 ? date('d.m.Y', $r['timestamp']) : null,
                        'author' => NewsContent::displayAuthor($r['author'], $r['head']),
                        'kind' => NewsContent::kind((string)$r['title'], $r['head'], (bool)$r['linked'])];
        }
    }
    $result['berichte'] = ['total' => $total, 'items' => $items];
}

if ($want('forum')) {
    $items = []; $total = 0;
    $hasFts = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE name = 'forum_fts'")->fetchColumn();
    if ($fts !== '' && $hasFts) {
        // Treffer je Thema (Beitraege + Thementitel), nur oeffentliche Bereiche
        $s = $pdo->prepare("
            SELECT t.id, t.title, t.post_count, COUNT(fp.id) AS hits, MAX(fp.created_at) AS last_hit
            FROM forum_fts f
            JOIN forum_posts fp ON fp.id = f.rowid
            JOIN forum_topics t ON t.id = fp.topic_id
            JOIN forum_categories c ON c.id = t.category_id
            WHERE forum_fts MATCH :q AND c.view_permission = 'public'
            GROUP BY t.id");
        $s->execute([':q' => $fts]);
        $byTopic = [];
        foreach ($s as $r) $byTopic[$r['id']] = $r;
        $tw = array_filter($words, fn($x) => strlen($x) >= 3);
        if ($tw) {
            foreach ($pdo->query("SELECT t.id, t.title, t.post_count, t.last_post_at FROM forum_topics t
                                  JOIN forum_categories c ON c.id = t.category_id WHERE c.view_permission = 'public'") as $t) {
                $n = norm($t['title']); $ok = true;
                foreach ($tw as $x) if (strpos($n, $x) === false) { $ok = false; break; }
                if ($ok && !isset($byTopic[$t['id']])) $byTopic[$t['id']] = ['id' => $t['id'], 'title' => $t['title'], 'post_count' => $t['post_count'], 'hits' => 0, 'last_hit' => $t['last_post_at']];
                elseif ($ok) $byTopic[$t['id']]['title_hit'] = true;
            }
        }
        $all = array_values($byTopic);
        usort($all, fn($a, $b) => strcmp((string)$b['last_hit'], (string)$a['last_hit']));
        $total = count($all);
        foreach (array_slice($all, $skip(), $take('forum')) as $r) {
            $items[] = ['id' => (int)$r['id'], 'title' => $r['title'], 'posts' => (int)$r['post_count'],
                        'hits' => (int)$r['hits'], 'last' => $r['last_hit'] ? date('d.m.Y', strtotime($r['last_hit'])) : null];
        }
    }
    $result['forum'] = ['total' => $total, 'items' => $items];
}

out(['success' => true, 'q' => $q, 'results' => $result]);
