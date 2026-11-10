<?php
/**
 * Jahresweiser JSON-Datenexport (Forum, News, Ligen)
 * Quelle: Neuer Server-Abzug in E:\Web-Projekte\hafo.de\export + MariaDB hafo_export
 * Ziel:   E:\Web-Projekte\hafo.de\export\json
 */

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

if (php_sapi_name() !== 'cli') {
    die("Nur ueber CLI ausfuehrbar.\n");
}

require_once __DIR__ . '/../lib/LegacyContentConverter.php';
require_once __DIR__ . '/../lib/LmoParser.php';

$exportSourceRoot = 'e:/Web-Projekte/hafo.de/export/4a6373fe278b0dd04a80ee8f170b7e3c (2)/hafo.de';
$jsonOutDir       = 'e:/Web-Projekte/hafo.de/export/json';

if (!is_dir($jsonOutDir)) {
    mkdir($jsonOutDir, 0777, true);
}

echo "==========================================================\n";
echo "  HAFO.DE JAHRES-JSON-EXPORT (Forum, News, Ligen)\n";
echo "==========================================================\n";
echo "Quellpfad: $exportSourceRoot\n";
echo "Zielpfad:  $jsonOutDir\n\n";

$manifest = [
    'generated_at' => date('c'),
    'source'       => $exportSourceRoot,
    'database'     => 'hafo_export (MySQL dump 2026-10-25)',
    'forum'        => ['total_posts' => 0, 'total_topics' => 0, 'years' => []],
    'news'         => ['total_articles' => 0, 'years' => []],
    'leagues'      => ['total_leagues' => 0, 'total_matches' => 0, 'seasons' => []],
];

// =========================================================================
// 1. FORUM EXPORT (aus hafo_export)
// =========================================================================
echo "=== 1. Exportiere Forum (SMF -> forum-YYYY.json) ===\n";

$mysql = new PDO("mysql:host=localhost;dbname=hafo_export;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Boards laden
$boardsRaw = $mysql->query("SELECT id_board, id_cat, name, description, member_groups, board_order FROM smf_boards ORDER BY id_board")->fetchAll();
$boards = [];
foreach ($boardsRaw as $b) {
    $idBoard = (int)$b['id_board'];
    $groups = array_filter(array_map('trim', explode(',', $b['member_groups'] ?? '')), fn($v) => $v !== '');
    // Board 6 ("hafo.de") erlaubt Gaeste (-1) und normale Mitglieder (0) -> public
    $isPublic = in_array('-1', $groups, true) || in_array('0', $groups, true);
    $boards[$idBoard] = [
        'id_board'      => $idBoard,
        'name'          => LegacyContentConverter::cleanPlainTitle($b['name']),
        'description'   => LegacyContentConverter::cleanPlainTitle($b['description']),
        'visibility'    => $isPublic ? 'public' : 'internal',
        'member_groups' => $b['member_groups'],
    ];
}

// Topics laden
$topicsRaw = $mysql->query("
    SELECT t.id_topic, t.id_board, t.is_sticky, t.locked, t.num_views, t.num_replies, m.subject AS first_subject
    FROM smf_topics t
    LEFT JOIN smf_messages m ON t.id_first_msg = m.id_msg
")->fetchAll();
$topics = [];
foreach ($topicsRaw as $t) {
    $topics[(int)$t['id_topic']] = [
        'id_topic' => (int)$t['id_topic'],
        'id_board' => (int)$t['id_board'],
        'title'    => LegacyContentConverter::cleanPlainTitle($t['first_subject'] ?? '') ?: 'Ohne Titel',
        'sticky'   => (bool)$t['is_sticky'],
        'locked'   => (bool)$t['locked'],
        'views'    => (int)$t['num_views'],
    ];
}
$manifest['forum']['total_topics'] = count($topics);

// Jahre ermitteln
$years = $mysql->query("SELECT DISTINCT YEAR(FROM_UNIXTIME(poster_time)) AS yr FROM smf_messages ORDER BY yr")->fetchAll(PDO::FETCH_COLUMN);

$stmtMsgsByYear = $mysql->prepare("
    SELECT m.id_msg, m.id_topic, m.id_board, m.poster_time, m.poster_name, m.subject, m.body,
           mem.real_name
    FROM smf_messages m
    LEFT JOIN smf_members mem ON m.id_member = mem.id_member
    WHERE m.poster_time >= :start_ts AND m.poster_time < :end_ts
    ORDER BY m.poster_time ASC, m.id_msg ASC
");

foreach ($years as $yr) {
    $yr = (int)$yr;
    $startTs = mktime(0, 0, 0, 1, 1, $yr);
    $endTs   = mktime(0, 0, 0, 1, 1, $yr + 1);

    $stmtMsgsByYear->execute([':start_ts' => $startTs, ':end_ts' => $endTs]);
    $rows = $stmtMsgsByYear->fetchAll();

    $postsOut = [];
    $pubCount = 0;
    $intCount = 0;

    foreach ($rows as $r) {
        $idBoard = (int)$r['id_board'];
        $idTopic = (int)$r['id_topic'];
        $boardInfo = $boards[$idBoard] ?? [
            'name' => "Board $idBoard",
            'visibility' => 'internal',
        ];
        $topicInfo = $topics[$idTopic] ?? [
            'title' => LegacyContentConverter::cleanPlainTitle($r['subject'] ?? ''),
        ];

        $visibility = $boardInfo['visibility'];
        if ($visibility === 'public') {
            $pubCount++;
        } else {
            $intCount++;
        }

        $author = LegacyContentConverter::cleanPlainTitle($r['real_name'] ?: $r['poster_name']);
        if ($author === '') {
            $author = 'Gast';
        }

        $ts = (int)$r['poster_time'];
        $postsOut[] = [
            'id_msg'       => (int)$r['id_msg'],
            'id_topic'     => $idTopic,
            'topic_title'  => $topicInfo['title'],
            'id_board'     => $idBoard,
            'board_name'   => $boardInfo['name'],
            'visibility'   => $visibility,
            'author'       => $author,
            'timestamp'    => $ts,
            'date'         => date('Y-m-d H:i:s', $ts),
            'subject'      => LegacyContentConverter::cleanPlainTitle($r['subject'] ?? ''),
            'content_html' => LegacyContentConverter::convertSmfPost($r['body'] ?? ''),
        ];
    }

    $yearPayload = [
        'year'           => $yr,
        'total_posts'    => count($postsOut),
        'public_posts'   => $pubCount,
        'internal_posts' => $intCount,
        'boards'         => array_values($boards),
        'posts'          => $postsOut,
    ];

    $fileName = "forum-{$yr}.json";
    file_put_contents(
        "$jsonOutDir/$fileName",
        json_encode($yearPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $manifest['forum']['total_posts'] += count($postsOut);
    $manifest['forum']['years'][$yr] = [
        'file'           => $fileName,
        'total_posts'    => count($postsOut),
        'public_posts'   => $pubCount,
        'internal_posts' => $intCount,
    ];

    echo sprintf("  %s: %5d Beitraege (public: %5d, internal: %5d)\n", $fileName, count($postsOut), $pubCount, $intCount);
}

// =========================================================================
// 2. NEWS EXPORT (aus export/.../hafo.de/news/news)
// =========================================================================
echo "\n=== 2. Exportiere News / Spielberichte (news-YYYY.json) ===\n";

$newsDir = "$exportSourceRoot/news/news";
$usersMap = LegacyContentConverter::loadFusionUsersMap("$exportSourceRoot/news/users.php");
$newsFiles = glob("$newsDir/news.*.php");

$newsByYear = [];
foreach ($newsFiles as $file) {
    if (!preg_match('/news\.(\d+)\.php$/', basename($file), $m)) {
        continue;
    }
    $id = (int)$m[1];
    $raw = file_get_contents($file);
    $lines = explode("\n", $raw, 2);
    if (count($lines) < 2) {
        continue;
    }
    $parts = explode('|<|', $lines[1]);
    if (count($parts) < 7) {
        continue;
    }

    $shortRaw = trim($parts[0] ?? '');
    $fullRaw  = trim($parts[1] ?? '');
    $writerRaw = LegacyContentConverter::cleanPlainTitle(LegacyContentConverter::toUtf8($parts[2] ?? ''));
    $titleRaw  = LegacyContentConverter::cleanPlainTitle(LegacyContentConverter::toUtf8($parts[3] ?? ''));
    $timestamp = (int)trim($parts[6] ?? 0);

    $author = $usersMap[$writerRaw] ?? ($writerRaw !== '' ? $writerRaw : 'Redaktion');
    $title  = $titleRaw !== '' ? $titleRaw : "News #$id";
    $shortHtml = LegacyContentConverter::convertFusionNewsContent($shortRaw);
    $fullHtml  = LegacyContentConverter::convertFusionNewsContent($fullRaw);

    $year = $timestamp > 0 ? (int)date('Y', $timestamp) : 2003;
    $newsByYear[$year][$id] = [
        'id'            => $id,
        'title'         => $title,
        'short_content' => $shortHtml,
        'content_html'  => $fullHtml,
        'author'        => $author,
        'timestamp'     => $timestamp,
        'date'          => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : null,
    ];
}

ksort($newsByYear);
foreach ($newsByYear as $yr => $items) {
    // Sortiere chronologisch nach Timestamp, dann ID
    uasort($items, function ($a, $b) {
        if ($a['timestamp'] !== $b['timestamp']) {
            return $a['timestamp'] <=> $b['timestamp'];
        }
        return $a['id'] <=> $b['id'];
    });

    $articles = array_values($items);
    $fileName = "news-{$yr}.json";
    $payload = [
        'year'     => $yr,
        'count'    => count($articles),
        'articles' => $articles,
    ];
    file_put_contents(
        "$jsonOutDir/$fileName",
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $manifest['news']['total_articles'] += count($articles);
    $manifest['news']['years'][$yr] = [
        'file'  => $fileName,
        'count' => count($articles),
    ];
    echo sprintf("  %s: %4d Artikel\n", $fileName, count($articles));
}

// =========================================================================
// 3. LIGEN EXPORT (aus export/.../hafo.de/lmo4/ligen/*.l98)
// =========================================================================
echo "\n=== 3. Exportiere Ligen, Spiele & Tabellen (ligen-YYYY.json) ===\n";

$ligaDir = "$exportSourceRoot/lmo4/ligen";
$lmoFiles = glob("$ligaDir/*.{l98,L98}", GLOB_BRACE);
$parser = new LmoParser($ligaDir);

$leaguesByYear = [];
foreach ($lmoFiles as $filePath) {
    $fileName = basename($filePath);
    $parsed = $parser->load($fileName);
    $title = $parsed['options']['Name'] ?? $fileName;

    // Startjahr der Saison bestimmen
    $startYear = null;
    if (preg_match('/(\d{2})[-_]?(\d{2})\.l98$/i', $fileName, $m)) {
        $y1 = (int)$m[1];
        $startYear = ($y1 >= 90) ? (1900 + $y1) : (2000 + $y1);
    } elseif (preg_match('/(20\d{2}|19\d{2})/', $title, $m)) {
        $startYear = (int)$m[1];
    }
    if (!$startYear) {
        $startYear = 2001;
    }

    $matchCount = 0;
    foreach ($parsed['matches'] as $roundMatches) {
        $matchCount += count($roundMatches);
    }

    $leaguesByYear[$startYear][] = [
        'file'        => $fileName,
        'name'        => $title,
        'season'      => sprintf('%d/%02d', $startYear, ($startYear + 1) % 100),
        'team_count'  => count($parsed['teams']),
        'round_count' => count($parsed['matches']),
        'match_count' => $matchCount,
        'options'     => [
            'PointsForWin'  => (int)($parsed['options']['PointsForWin'] ?? 3),
            'PointsForDraw' => (int)($parsed['options']['PointsForDraw'] ?? 1),
            'PointsForLost' => (int)($parsed['options']['PointsForLost'] ?? 0),
            'Rounds'        => (int)($parsed['options']['Rounds'] ?? count($parsed['matches'])),
            'Actual'        => (int)($parsed['options']['Actual'] ?? 0),
        ],
        'teams'       => array_values($parsed['teams']),
        'table'       => $parsed['table'],
        'rounds'      => $parsed['matches'],
    ];

    $manifest['leagues']['total_leagues']++;
    $manifest['leagues']['total_matches'] += $matchCount;
}

ksort($leaguesByYear);
foreach ($leaguesByYear as $yr => $leagues) {
    usort($leagues, fn($a, $b) => strcmp($a['file'], $b['file']));
    $fileName = "ligen-{$yr}.json";
    $seasonLabel = sprintf('%d/%d', $yr, $yr + 1);
    $matchesInYear = array_sum(array_column($leagues, 'match_count'));

    $payload = [
        'season_start_year' => $yr,
        'season'            => $seasonLabel,
        'league_count'      => count($leagues),
        'match_count'       => $matchesInYear,
        'leagues'           => $leagues,
    ];

    file_put_contents(
        "$jsonOutDir/$fileName",
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );

    $manifest['leagues']['seasons'][$yr] = [
        'file'         => $fileName,
        'season'       => $seasonLabel,
        'league_count' => count($leagues),
        'match_count'  => $matchesInYear,
    ];
    echo sprintf("  %s (Saison %s): %2d Ligen, %4d Spiele\n", $fileName, $seasonLabel, count($leagues), $matchesInYear);
}

// Manifest schreiben
file_put_contents(
    "$jsonOutDir/index.json",
    json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

echo "\n==========================================================\n";
echo "  JSON-EXPORT ERFOLGREICH ABGESCHLOSSEN\n";
echo "  Forum:  {$manifest['forum']['total_posts']} Beitraege in " . count($manifest['forum']['years']) . " Jahresdateien\n";
echo "  News:   {$manifest['news']['total_articles']} Artikel in " . count($manifest['news']['years']) . " Jahresdateien\n";
echo "  Ligen:  {$manifest['leagues']['total_leagues']} Ligen ({$manifest['leagues']['total_matches']} Spiele) in " . count($manifest['leagues']['seasons']) . " Jahresdateien\n";
echo "  Index:  $jsonOutDir/index.json\n";
echo "==========================================================\n";
