<?php
/**
 * Demo-Inhalte fuer eine frische Installation (nur Kommandozeile, nur auf leerer Datenbank):
 * eine Liga mit 10 erfundenen Hamburger Vereinen, Spielplan (Doppelrunde) mit Ergebnissen der
 * ersten 8 Spieltage, ein Spielbericht, eine Spieltagsvorschau, eine Nachricht und ein Forum.
 *
 *   php scripts/demo-data.php            (wird von scripts/install.php --demo aufgerufen)
 *
 * Alle Vereine, Ergebnisse und Texte sind erfunden.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/LmoDatabase.php';

$pdo = LmoDatabase::getInstance();
if ((int)$pdo->query("SELECT COUNT(*) FROM leagues")->fetchColumn() > 0) {
    fwrite(STDERR, "Es gibt bereits Ligen – Demo-Inhalte werden nur in eine leere Installation geschrieben.\n");
    exit(1);
}

mt_srand(2627); // immer dieselben Demo-Ergebnisse
$teams = [
    ['FC Alster 08', 'Alster'], ['SV Elbufer', 'Elbufer'], ['TuS Speicherstadt', 'Speicher'], ['SC Hafenkante', 'Hafenkante'],
    ['Eintracht Fleetenkieker', 'Fleet'], ['Concordia Kanalviertel', 'Cordi K.'], ['VfL Deichtor', 'Deichtor'],
    ['Germania Moorweide', 'Moorweide'], ['FSV Stadtpark 1912', 'Stadtpark'], ['Hammonia Lotsenhaus', 'Lotsen'],
];
$file = 'demooberliga2627.l98';
$name = 'Oberliga Demo 26/27';
$rounds = 2 * (count($teams) - 1);
$perRound = intdiv(count($teams), 2);
$playedRounds = 8;
$options = [
    'Title' => 'Liga Manager Online 4', 'Name' => $name, 'Type' => '0', 'Teams' => (string)count($teams),
    'goalfaktor' => '1', 'pointsfaktor' => '1', 'enableGameSort' => '1', 'Rounds' => (string)$rounds,
    'Matches' => (string)$perRound, 'Actual' => (string)($playedRounds + 1), 'Kegel' => '0', 'HandS' => '0',
    'PointsForWin' => '3', 'PointsForDraw' => '1', 'PointsForLost' => '0', 'Spez' => '0', 'HideDraw' => '0',
    'OnRun' => '0', 'MinusPoints' => '1', 'Direct' => '0', 'Champ' => '1', 'CL' => '0', 'CK' => '0', 'UC' => '0',
    'AR' => '0', 'AB' => '2', 'namePkt' => 'Pkt.', 'nameTor' => 'Tore', 'tableHinRueck' => '1', 'tableHeimAusw' => '1',
    'DatC' => '1', 'DatS' => '1', 'DatM' => '1', 'DatF' => '%d.%m. %H.%M', 'urlT' => '1', 'urlB' => '1', 'Plan' => '1',
    'Ergebnis' => '1', 'mittore' => '1', 'favTeam' => '0', 'selTeam' => '0', 'ticker' => '0', 'Graph' => '1',
    'Kreuz' => '1', 'Tabelle' => '1', 'Ligastats' => '1', 'kurve1' => '0', 'kurve2' => '0',
];

$pdo->beginTransaction();
$pdo->prepare("INSERT INTO leagues (file, name, options) VALUES (?, ?, ?)")
    ->execute([$file, $name, json_encode($options, JSON_UNESCAPED_UNICODE)]);
$leagueId = (int)$pdo->lastInsertId();

$ids = [];
$st = $pdo->prepare("INSERT INTO teams (league_id, original_id, name, short_name) VALUES (?, ?, ?, ?)");
foreach ($teams as $i => [$tName, $short]) {
    $st->execute([$leagueId, $i + 1, $tName, $short]);
    $ids[$i] = (int)$pdo->lastInsertId();
}

// Doppelrunde nach dem Kreisverfahren, freitags ab 07.08.2026, 19:30 Uhr
$n = count($teams);
$order = range(0, $n - 1);
$schedule = [];
for ($r = 0; $r < $n - 1; $r++) {
    $pairs = [];
    for ($k = 0; $k < $n / 2; $k++) {
        $a = $order[$k]; $b = $order[$n - 1 - $k];
        $pairs[] = ($r % 2 === 0 || $k > 0) ? [$a, $b] : [$b, $a];
    }
    $schedule[$r + 1] = $pairs;
    $schedule[$r + $n] = array_map(fn($p) => [$p[1], $p[0]], $pairs);
    // rotieren, erstes Team bleibt fest
    $last = array_pop($order);
    array_splice($order, 1, 0, [$last]);
}
ksort($schedule);

$sm = $pdo->prepare("INSERT INTO matches (league_id, round_nr, home_team_id, guest_team_id, home_goals, guest_goals, match_date, match_time)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
$start = new DateTimeImmutable('2026-08-07');
$matchIds = [];
foreach ($schedule as $round => $pairs) {
    $date = $start->modify('+' . (($round - 1) * 7) . ' days')->format('d.m.Y');
    foreach ($pairs as [$h, $g]) {
        $played = $round <= $playedRounds;
        $hg = $played ? min(5, (int)floor(-log(1 - mt_rand(0, 999) / 1000) * 1.6)) : null;
        $gg = $played ? min(4, (int)floor(-log(1 - mt_rand(0, 999) / 1000) * 1.2)) : null;
        $sm->execute([$leagueId, $round, $ids[$h], $ids[$g], $hg, $gg, $date, '19:30']);
        $matchIds[$round][] = ['id' => (int)$pdo->lastInsertId(), 'home' => $teams[$h][0], 'guest' => $teams[$g][0], 'hg' => $hg, 'gg' => $gg, 'date' => $date];
    }
}

// Berichte
$sn = $pdo->prepare("INSERT INTO news (title, short_content, content, author, timestamp, match_date) VALUES (?, ?, ?, ?, ?, ?)");
$link = $pdo->prepare("INSERT OR IGNORE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, 1.0)");
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$ts = fn($d) => (new DateTimeImmutable(str_replace('.', '-', implode('.', array_reverse(explode('.', $d))))))->setTime(22, 0)->getTimestamp();

// 1. Spielbericht zum Topspiel des 8. Spieltags
$m = $matchIds[$playedRounds][0];
$report = '<span><b>' . $e($m['home']) . ' – ' . $e($m['guest']) . ' ' . $m['hg'] . ':' . $m['gg'] . ' (' . min($m['hg'], 1) . ':' . min($m['gg'], 1) . ')</b></span><br><br>'
    . '<b>Schiedsrichter:</b> Demo-Schiedsrichter<br><b>Zuschauer:</b> 240<br><br>'
    . 'Bei bestem Flutlichtwetter entwickelte sich von Beginn an eine offene Partie. Beide Mannschaften suchten den Weg nach vorn, '
    . 'die Gastgeber hatten in der ersten halben Stunde die klareren Chancen.<br><br>'
    . 'Nach der Pause wurde es hitziger: Die Gäste stellten auf eine Dreierkette um und kamen besser ins Spiel. '
    . 'Am Ende stand ein Ergebnis, mit dem beide Trainer leben konnten.<br><br>'
    . '<i>Dies ist ein Demo-Bericht. Vereine, Spieler und Ergebnisse sind erfunden.</i>';
$sn->execute([$m['home'] . ' – ' . $m['guest'] . ' ' . $m['hg'] . ':' . $m['gg'], $playedRounds . '. Spieltag: ' . $m['home'] . ' – ' . $m['guest'],
    $report, 'Redaktion', $ts($m['date']), $m['date']]);
$link->execute([$m['id'], (int)$pdo->lastInsertId()]);

// 2. Vorschau auf den naechsten Spieltag (Format des Vorschau-Editors)
require_once __DIR__ . '/../lib/Instance.php';
$tip = (string)Instance::get('tip_label') ?: 'Tipp';
$next = $playedRounds + 1;
$parts = ['Der ' . $next . '. Spieltag steht an – hier unsere Einschätzung zu allen Partien.<br> <br>'];
foreach ($matchIds[$next] as $i => $g) {
    if ($i > 0) $parts[] = '<hr class="match-sep">';
    $parts[] = '<b class="match-head">' . $e($g['home']) . ' – ' . $e($g['guest']) . '</b> <br>Fr. 19:30';
    $parts[] = ' <br> <br>Ein Duell auf Augenhöhe, beide Teams wollen in der Tabelle nach oben.';
    $parts[] = ' <br><b>' . $e($tip) . ':</b> ' . mt_rand(0, 3) . ':' . mt_rand(0, 2);
}
$parts[] = '<br> <br><i>Demo-Inhalt – alle Vereine sind erfunden.</i>';
$first = $matchIds[$next][0]['date'];
$sn->execute([$next . '. Spieltag – ' . $name, $next . '. Spieltag – ' . $name, implode('', $parts), 'Redaktion', $ts($first) - 86400 * 2, $first]);
$previewId = (int)$pdo->lastInsertId();
foreach ($matchIds[$next] as $g) $link->execute([$g['id'], $previewId]);

// 3. Allgemeine Nachricht
$sn->execute(['Willkommen in der Demo', 'So sieht der Liga Manager Online mit Beispieldaten aus.',
    'Diese Installation enthält Beispieldaten: eine Liga mit zehn erfundenen Vereinen, acht gespielte Spieltage, '
    . 'einen Spielbericht, eine Vorschau und ein kleines Forum.<br><br>Im Admin (<code>/admin/</code>) können Sie Ergebnisse eintragen, '
    . 'Berichte schreiben und mit dem Saison-Assistenten weitere Ligen anlegen.', 'Redaktion', time(), null]);

// Forum
$admin = $pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
$pdo->prepare("INSERT INTO forum_categories (name, description, sort_order, league_id) VALUES (?, ?, 1, ?)")
    ->execute(['Oberliga', 'Diskussionen zur Oberliga', $leagueId]);
$catLiga = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO forum_categories (name, description, sort_order) VALUES (?, ?, 2)")
    ->execute(['Allgemeines', 'Alles rund um den Amateurfußball']);
$posts = [
    [$catLiga, 'Wer wird Herbstmeister?', ['Nach acht Spieltagen ist oben alles eng beisammen. Euer Tipp für die Herbstmeisterschaft?',
        'Ich sage [b]FC Alster 08[/b] – die Defensive steht am sichersten.']],
];
foreach ($posts as [$cat, $title, $texts]) {
    $pdo->prepare("INSERT INTO forum_topics (category_id, user_id, title, post_count, last_post_at) VALUES (?, ?, ?, ?, datetime('now'))")
        ->execute([$cat, $admin, $title, count($texts)]);
    $topicId = (int)$pdo->lastInsertId();
    foreach ($texts as $i => $t) {
        $pdo->prepare("INSERT INTO forum_posts (topic_id, user_id, content, is_first_post, created_at) VALUES (?, ?, ?, ?, datetime('now', ?))")
            ->execute([$topicId, $admin, $t, $i === 0 ? 1 : 0, '-' . (count($texts) - $i) . ' hours']);
        $last = (int)$pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE forum_topics SET last_post_id = ?, last_post_user_id = ? WHERE id = ?")->execute([$last, $admin, $topicId]);
    $pdo->prepare("UPDATE forum_categories SET topic_count = topic_count + 1, post_count = post_count + ?, last_post_id = ? WHERE id = ?")
        ->execute([count($texts), $last, $cat]);
}
$pdo->commit();

echo "Demo-Inhalte angelegt: $name, " . count($teams) . " Vereine, $playedRounds von $rounds Spieltagen gespielt, 3 Berichte, Forum.\n";
