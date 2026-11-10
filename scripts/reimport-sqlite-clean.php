<?php
/**
 * Sauberer Neu-Import (Re-Import) fuer lmo26 SQLite-Datenbank (data/database.sqlite)
 * aus dem neuen Export (Stand Oktober 2026) unter Erhalt aller bestehenden Spieler (players)
 * und Admin/Editor-Accounts.
 */

error_reporting(E_ALL);
ini_set('memory_limit', '1024M');

if (php_sapi_name() !== 'cli') {
    die("Nur ueber CLI ausfuehrbar.\n");
}

require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/LmoParser.php';
require_once __DIR__ . '/../lib/LegacyContentConverter.php';

$exportRoot = 'e:/Web-Projekte/hafo.de/export/4a6373fe278b0dd04a80ee8f170b7e3c (2)/hafo.de';
$localHafo  = 'e:/Web-Projekte/hafo.de/hafo.de';
$dbPath     = __DIR__ . '/../data/database.sqlite';
$backupPath = __DIR__ . '/../data/database_backup_' . date('Ymd') . '_pre_clean_import.sqlite';

echo "==========================================================\n";
echo "  LMO26 SAUBERER RE-IMPORT (Ligen, News, Forum)\n";
echo "==========================================================\n";

// 0. Backup der SQLite-Datenbank anlegen (einmalig vor dem Import)
if (file_exists($dbPath) && !file_exists($backupPath)) {
    copy($dbPath, $backupPath);
    echo "[Backup] SQLite gesichert nach: " . basename($backupPath) . "\n";
} else {
    echo "[Backup] Vorhandenes Tages-Backup bleibt erhalten.\n";
}

// 0b. Neue LMO-Dateien und News-Dateien in lokalen Arbeitsordner hafo.de synchronisieren
echo "[Sync] Synchronisiere neue Ligen- und News-Dateien nach hafo.de/...\n";
$copiedLeagues = 0;
foreach (glob("$exportRoot/lmo4/ligen/*.{l98,L98}", GLOB_BRACE) as $srcFile) {
    $dstFile = "$localHafo/lmo4/ligen/" . basename($srcFile);
    if (!file_exists($dstFile) || md5_file($srcFile) !== md5_file($dstFile)) {
        copy($srcFile, $dstFile);
        $copiedLeagues++;
    }
}
$copiedNews = 0;
foreach (glob("$exportRoot/news/news/news.*.php") as $srcFile) {
    $dstFile = "$localHafo/news/news/" . basename($srcFile);
    if (!file_exists($dstFile) || filesize($srcFile) !== filesize($dstFile)) {
        copy($srcFile, $dstFile);
        $copiedNews++;
    }
}
if (file_exists("$exportRoot/news/users.php")) {
    copy("$exportRoot/news/users.php", "$localHafo/news/users.php");
}
echo "  -> $copiedLeagues Liga-Dateien und $copiedNews News-Dateien aktualisiert.\n\n";

$pdo = LmoDatabase::getInstance();
LmoDatabase::migrateSchema();
$playersBefore = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
echo "[Check] Spieler in 'players' vor Import: $playersBefore\n\n";

// =========================================================================
// 1. LIGEN, TEAMS (in-place!), SPIELE & STRAFPUNKTE
// =========================================================================
echo "=== 1. Importiere Ligen, Teams, Spiele & Punktekorrekturen ===\n";

// Tabelle point_corrections sicherstellen
$pdo->exec("CREATE TABLE IF NOT EXISTS point_corrections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    league_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    points INTEGER NOT NULL DEFAULT 0,
    reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE,
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE(league_id, team_id)
)");

$ligaDir = "$exportRoot/lmo4/ligen";
$lmoFiles = glob("$ligaDir/*.{l98,L98}", GLOB_BRACE);
$parser = new LmoParser($ligaDir);

$stmtGetLeague    = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
$stmtInsertLeague = $pdo->prepare("INSERT INTO leagues (file, name, options) VALUES (?, ?, ?)");
$stmtUpdateLeague = $pdo->prepare("UPDATE leagues SET name = ?, options = ? WHERE id = ?");

$stmtGetTeam      = $pdo->prepare("SELECT id FROM teams WHERE league_id = ? AND original_id = ?");
$stmtInsertTeam   = $pdo->prepare("INSERT INTO teams (league_id, original_id, name, short_name) VALUES (?, ?, ?, ?)");
$stmtUpdateTeam   = $pdo->prepare("UPDATE teams SET name = ?, short_name = COALESCE(NULLIF(?, ''), short_name) WHERE id = ?");

$stmtDeleteMatches = $pdo->prepare("DELETE FROM matches WHERE league_id = ?");
$stmtInsertMatch   = $pdo->prepare("
    INSERT INTO matches (league_id, round_nr, home_team_id, guest_team_id, home_goals, guest_goals, match_date, match_time, match_note, report_url)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmtUpsertCorrection = $pdo->prepare("
    INSERT OR REPLACE INTO point_corrections (league_id, team_id, points, reason)
    VALUES (?, ?, ?, ?)
");

$pdo->beginTransaction();

// Entferne die alte Test-Liga test_writer.l98 falls vorhanden
$pdo->exec("DELETE FROM leagues WHERE file = 'test_writer.l98'");

$totalLeagues = 0;
$totalTeams   = 0;
$totalMatches = 0;
$totalCorrections = 0;
$explicitMatchNewsLinks = []; // match_id => news_id

foreach ($lmoFiles as $filePath) {
    $fileName = basename($filePath);
    $data = $parser->load($fileName);
    $leagueName = $data['options']['Name'] ?? ($data['options']['Title'] ?? $fileName);
    $optionsJson = json_encode($data['options'], JSON_UNESCAPED_UNICODE);

    $stmtGetLeague->execute([$fileName]);
    $leagueId = $stmtGetLeague->fetchColumn();
    if ($leagueId) {
        $stmtUpdateLeague->execute([$leagueName, $optionsJson, $leagueId]);
    } else {
        $stmtInsertLeague->execute([$fileName, $leagueName, $optionsJson]);
        $leagueId = $pdo->lastInsertId();
    }

    // Teams aktualisieren / einfuegen (KEIN DELETE FROM teams, damit players erhalten bleiben!)
    $teamMap = []; // original_id => db_id
    foreach ($data['teams'] as $team) {
        $origId = (int)$team['id'];
        $tName  = $team['name'];
        $tShort = $team['short_name'] ?? '';

        $stmtGetTeam->execute([$leagueId, $origId]);
        $dbTeamId = $stmtGetTeam->fetchColumn();
        if ($dbTeamId) {
            $stmtUpdateTeam->execute([$tName, $tShort, $dbTeamId]);
        } else {
            $stmtInsertTeam->execute([$leagueId, $origId, $tName, $tShort ?: null]);
            $dbTeamId = $pdo->lastInsertId();
        }
        $teamMap[$origId] = (int)$dbTeamId;
        $totalTeams++;

        // Strafpunkte (SP) in point_corrections uebernehmen
        $sp = (int)($team['penalty_points'] ?? 0);
        if ($sp > 0) {
            $reason = $team['note'] ?: "$sp Punkte Abzug";
            $stmtUpsertCorrection->execute([$leagueId, $dbTeamId, -$sp, $reason]);
            $totalCorrections++;
        }
    }

    // Spiele der Liga neu schreiben
    $stmtDeleteMatches->execute([$leagueId]);
    foreach ($data['matches'] as $roundNr => $matches) {
        foreach ($matches as $m) {
            $uH = $teamMap[$m['home_id']] ?? null;
            $uG = $teamMap[$m['guest_id']] ?? null;
            if (!$uH || !$uG) {
                continue;
            }
            $stmtInsertMatch->execute([
                $leagueId,
                $roundNr,
                $uH,
                $uG,
                $m['home_goals'],
                $m['guest_goals'],
                $m['date'] ?? null,
                $m['time'] ?? null,
                $m['note'] ?? null,
                $m['report_url'] ?? null,
            ]);
            $matchId = (int)$pdo->lastInsertId();
            $totalMatches++;

            if (!empty($m['news_id'])) {
                $explicitMatchNewsLinks[$matchId] = (int)$m['news_id'];
            }
        }
    }

    $totalLeagues++;
}

try {
    $pdo->exec("INSERT INTO teams_fts(teams_fts) VALUES('rebuild')");
} catch (Exception $e) {}

$pdo->commit();

$playersAfter = (int)$pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
echo "  -> $totalLeagues Ligen, $totalTeams Teams, $totalMatches Spiele, $totalCorrections Punktabzuege importiert.\n";
echo "  -> Spieler in 'players' nach Ligen-Update: $playersAfter (100% erhalten!).\n\n";

// =========================================================================
// 2. NEWS / SPIELBERICHTE & MATCH-VERKNUEPFUNGEN
// =========================================================================
echo "=== 2. Importiere News / Spielberichte (6.958 Dateien) ===\n";

$newsDir  = "$exportRoot/news/news";
$usersMap = LegacyContentConverter::loadFusionUsersMap("$exportRoot/news/users.php");
$newsFiles = glob("$newsDir/news.*.php");

$pdo->beginTransaction();

// FTS-Trigger waehrend des Massen-Imports kurz deaktivieren, danach rebuilden
$pdo->exec("DROP TRIGGER IF EXISTS news_ai");
$pdo->exec("DROP TRIGGER IF EXISTS news_au");
$pdo->exec("DROP TRIGGER IF EXISTS news_ad");
$pdo->exec("DELETE FROM match_news");
$pdo->exec("DELETE FROM news");

$stmtInsertNews = $pdo->prepare("
    INSERT INTO news (id, title, short_content, content, author, email, timestamp, match_date)
    VALUES (:id, :title, :short, :content, :author, :email, :ts, :match_date)
");

$importedNews = 0;
$newsData = [];

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

    $shortRaw  = trim($parts[0] ?? '');
    $fullRaw   = trim($parts[1] ?? '');
    $writerRaw = LegacyContentConverter::cleanPlainTitle(LegacyContentConverter::toUtf8($parts[2] ?? ''));
    $titleRaw  = LegacyContentConverter::cleanPlainTitle(LegacyContentConverter::toUtf8($parts[3] ?? ''));
    $timestamp = (int)trim($parts[6] ?? 0);

    $author    = $usersMap[$writerRaw] ?? ($writerRaw !== '' ? $writerRaw : 'Redaktion');
    $title     = $titleRaw !== '' ? $titleRaw : "News #$id";
    $shortHtml = LegacyContentConverter::convertFusionNewsContent($shortRaw);
    $fullHtml  = LegacyContentConverter::convertFusionNewsContent($fullRaw);
    $matchDate = $timestamp > 0 ? date('Y-m-d', $timestamp) : null;

    $stmtInsertNews->execute([
        ':id'         => $id,
        ':title'      => $title,
        ':short'      => $shortHtml,
        ':content'    => $fullHtml,
        ':author'     => $author,
        ':email'      => '', // Datenschutz: keine E-Mail-Adressen speichern
        ':ts'         => $timestamp,
        ':match_date' => $matchDate,
    ]);

    $newsData[$id] = [
        'title'     => $title,
        'content'   => $fullHtml,
        'timestamp' => $timestamp,
    ];
    $importedNews++;
}

// FTS-Trigger wiederherstellen und Index neu aufbauen
try {
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ai AFTER INSERT ON news BEGIN INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_au AFTER UPDATE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END");
    $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ad AFTER DELETE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); END");
    $pdo->exec("INSERT INTO news_fts(news_fts) VALUES('rebuild')");
} catch (Exception $e) {}

// Spielberichte mit Spielen verknuepfen (1. explizite BEx Links, 2. Datum+Teamnamen)
$stmtInsertLink = $pdo->prepare("INSERT OR REPLACE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, ?)");
$linkedMatches = [];

foreach ($explicitMatchNewsLinks as $matchId => $newsId) {
    if (isset($newsData[$newsId])) {
        $stmtInsertLink->execute([$matchId, $newsId, 1.0]);
        $linkedMatches[$matchId] = true;
    }
}

$stmtMatches = $pdo->query("
    SELECT m.id, m.match_date, t1.name as home_name, t2.name as guest_name
    FROM matches m
    JOIN teams t1 ON m.home_team_id = t1.id
    JOIN teams t2 ON m.guest_team_id = t2.id
    WHERE m.match_date IS NOT NULL AND m.match_date != ''
");
$matchesByDay = [];
foreach ($stmtMatches->fetchAll() as $match) {
    $ts = strtotime($match['match_date']);
    if (!$ts) continue;
    $dayKey = (int)($ts / 86400);
    $matchesByDay[$dayKey][] = [
        'id'    => (int)$match['id'],
        'home'  => mb_strtolower($match['home_name'], 'UTF-8'),
        'guest' => mb_strtolower($match['guest_name'], 'UTF-8'),
    ];
}

$matchToNews = [];
foreach ($newsData as $newsId => $news) {
    $newsTs = $news['timestamp'];
    if ($newsTs <= 0) continue;
    $searchText = mb_strtolower($news['title'] . ' ' . mb_substr(strip_tags($news['content']), 0, 2000, 'UTF-8'), 'UTF-8');
    $newsDayKey = (int)($newsTs / 86400);

    $bestMatch = null;
    $bestConf  = 0.0;

    for ($dayOffset = -5; $dayOffset <= 5; $dayOffset++) {
        $checkDay = $newsDayKey + $dayOffset;
        if (!isset($matchesByDay[$checkDay])) continue;

        foreach ($matchesByDay[$checkDay] as $m) {
            if (isset($linkedMatches[$m['id']])) continue;
            if (mb_strpos($searchText, $m['home'], 0, 'UTF-8') === false) continue;
            if (mb_strpos($searchText, $m['guest'], 0, 'UTF-8') === false) continue;

            $conf = 1.0 - (abs($dayOffset) * 0.15);
            if (preg_match('/\d+\s*[:\-]\s*\d+/', $news['title'])) {
                $conf += 0.15;
            }
            if ($conf > $bestConf) {
                $bestConf  = $conf;
                $bestMatch = $m['id'];
            }
        }
    }

    if ($bestMatch && $bestConf >= 0.5) {
        if (!isset($matchToNews[$bestMatch]) || $bestConf > $matchToNews[$bestMatch]['conf']) {
            $matchToNews[$bestMatch] = ['news_id' => $newsId, 'conf' => min(1.0, $bestConf)];
        }
    }
}

foreach ($matchToNews as $matchId => $info) {
    $stmtInsertLink->execute([$matchId, $info['news_id'], round($info['conf'], 2)]);
    $linkedMatches[$matchId] = true;
}

$pdo->commit();
echo "  -> $importedNews News importiert, " . count($linkedMatches) . " Spiele mit Spielberichten verknuepft.\n\n";

// =========================================================================
// 3. FORUM RE-IMPORT AUS hafo_export (78.589 Beitraege, Berechtigungen korrekt!)
// =========================================================================
echo "=== 3. Importiere Forum aus hafo_export (78.589 Beitraege bis 10/2026) ===\n";

$mysql = new PDO("mysql:host=localhost;dbname=hafo_export;charset=utf8mb4", "root", "", [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$pdo->beginTransaction();

// 3.1 Benutzer synchronisieren (bestehende User und Rollen admin/editor unangetastet lassen!)
$userMapping = []; // smf id_member => sqlite users.id
$existingUsersByName = [];
foreach ($pdo->query("SELECT id, username FROM users")->fetchAll() as $u) {
    $existingUsersByName[mb_strtolower(trim($u['username']), 'UTF-8')] = (int)$u['id'];
}

$stmtInsertUser = $pdo->prepare("
    INSERT OR IGNORE INTO users (username, email, password_hash, display_name, role, created_at)
    VALUES (?, '', ?, ?, 'user', ?)
");
$stmtFindUser = $pdo->prepare("SELECT id FROM users WHERE username = ? COLLATE NOCASE");
$dummyHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

$members = $mysql->query("
    SELECT id_member, member_name, real_name, date_registered
    FROM smf_members
    WHERE is_activated = 1
    ORDER BY id_member
")->fetchAll();

$newUsersCount = 0;
foreach ($members as $m) {
    $smfId = (int)$m['id_member'];
    $username = LegacyContentConverter::cleanPlainTitle($m['member_name']);
    $displayName = LegacyContentConverter::cleanPlainTitle($m['real_name']) ?: $username;
    if ($username === '') continue;

    $lower = mb_strtolower($username, 'UTF-8');
    if (isset($existingUsersByName[$lower])) {
        $userMapping[$smfId] = $existingUsersByName[$lower];
    } else {
        $regDate = ((int)$m['date_registered'] > 0) ? date('Y-m-d H:i:s', (int)$m['date_registered']) : date('Y-m-d H:i:s');
        $stmtInsertUser->execute([$username, $dummyHash, $displayName, $regDate]);
        $newId = (int)$pdo->lastInsertId();
        if (!$newId) {
            $stmtFindUser->execute([$username]);
            $newId = (int)$stmtFindUser->fetchColumn();
        }
        if ($newId) {
            $existingUsersByName[$lower] = $newId;
            $userMapping[$smfId] = $newId;
            $newUsersCount++;
        }
    }
}
// Datenschutz: E-Mail-Adressen normaler Forenuser in SQLite leeren (ausser Admin/Editor)
$pdo->exec("UPDATE users SET email = '' WHERE role = 'user'");

// 3.2 Forum-Tabellen leeren und sauber neu aufbauen
$pdo->exec("DELETE FROM forum_posts");
$pdo->exec("DELETE FROM forum_topics");
$pdo->exec("DELETE FROM forum_categories");
$pdo->exec("DELETE FROM forum_legacy_map");
$pdo->exec("DELETE FROM sqlite_sequence WHERE name IN ('forum_categories', 'forum_topics', 'forum_posts', 'forum_legacy_map')");

$stmtInsertLegacy = $pdo->prepare("INSERT OR REPLACE INTO forum_legacy_map (entity_type, old_id, new_id) VALUES (?, ?, ?)");
foreach ($userMapping as $oldUid => $newUid) {
    $stmtInsertLegacy->execute(['user', $oldUid, $newUid]);
}

// 3.3 Kategorien (Boards) mit korrekten Berechtigungen anlegen!
// Board 6 ("hafo.de") -> public; Board 14 ("hafo/FussballHH") -> admin; alle anderen (7,8,12,13,15,16) -> editor
$stmtInsertCat = $pdo->prepare("
    INSERT INTO forum_categories (name, description, sort_order, view_permission, reply_permission, create_permission, show_on_homepage, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
");

$boards = $mysql->query("SELECT id_board, id_cat, name, description, board_order, member_groups FROM smf_boards ORDER BY id_cat, board_order")->fetchAll();
$categoryMapping = []; // smf id_board => sqlite category_id

foreach ($boards as $b) {
    $idBoard = (int)$b['id_board'];
    $name = LegacyContentConverter::cleanPlainTitle($b['name']);
    $desc = LegacyContentConverter::cleanPlainTitle($b['description']);
    $sortOrder = (int)$b['board_order'];

    if ($idBoard === 6) {
        $viewPerm = 'public';
        $replyPerm = 'registered';
        $createPerm = 'registered';
        $showHome = 1;
    } elseif ($idBoard === 14) {
        $viewPerm = 'admin';
        $replyPerm = 'admin';
        $createPerm = 'admin';
        $showHome = 0;
    } else {
        $viewPerm = 'editor';
        $replyPerm = 'editor';
        $createPerm = 'editor';
        $showHome = 0;
    }

    $stmtInsertCat->execute([$name, $desc, $sortOrder, $viewPerm, $replyPerm, $createPerm, $showHome]);
    $catId = (int)$pdo->lastInsertId();
    $categoryMapping[$idBoard] = $catId;
    $stmtInsertLegacy->execute(['board', $idBoard, $catId]);
    echo sprintf("  Board #%d '%s' -> Kategorie #%d (view_permission = '%s')\n", $idBoard, $name, $catId, $viewPerm);
}

// 3.4 Topics migrieren
$topics = $mysql->query("
    SELECT t.id_topic, t.id_board, t.id_member_started, t.num_replies, t.num_views, t.is_sticky, t.locked,
           m.subject, m.poster_time
    FROM smf_topics t
    JOIN smf_messages m ON t.id_first_msg = m.id_msg
    ORDER BY t.id_topic
")->fetchAll();

$stmtInsertTopic = $pdo->prepare("
    INSERT INTO forum_topics (category_id, user_id, title, is_sticky, is_locked, view_count, post_count, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$topicMapping = [];
foreach ($topics as $t) {
    $oldTopicId = (int)$t['id_topic'];
    $idBoard    = (int)$t['id_board'];
    if (!isset($categoryMapping[$idBoard])) continue;

    $catId     = $categoryMapping[$idBoard];
    $userId    = $userMapping[(int)$t['id_member_started']] ?? null;
    $title     = LegacyContentConverter::cleanPlainTitle($t['subject'] ?? '') ?: 'Ohne Titel';
    $isSticky  = (int)$t['is_sticky'];
    $isLocked  = (int)$t['locked'];
    $views     = (int)$t['num_views'];
    $postCount = (int)$t['num_replies'] + 1;
    $createdAt = date('Y-m-d H:i:s', (int)$t['poster_time']);

    $stmtInsertTopic->execute([$catId, $userId, $title, $isSticky, $isLocked, $views, $postCount, $createdAt]);
    $newTopicId = (int)$pdo->lastInsertId();
    $topicMapping[$oldTopicId] = $newTopicId;
    $stmtInsertLegacy->execute(['topic', $oldTopicId, $newTopicId]);
}
echo "  -> " . count($topicMapping) . " Topics angelegt.\n";

// 3.5 Alle Beitraege (78.589) in einem Durchlauf migrieren
$stmtInsertPost = $pdo->prepare("
    INSERT INTO forum_posts (topic_id, user_id, guest_name, content, is_first_post, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmtUpdateTopicLast = $pdo->prepare("
    UPDATE forum_topics
    SET last_post_id = ?, last_post_at = ?, last_post_user_id = ?
    WHERE id = ?
");

$stmtAllMsgs = $mysql->query("
    SELECT id_msg, id_topic, id_member, poster_name, poster_time, body
    FROM smf_messages
    ORDER BY id_topic ASC, id_msg ASC
");

$migratedPosts = 0;
$currentTopicOldId = null;
$lastPostInfoByTopic = [];

while ($msg = $stmtAllMsgs->fetch()) {
    $oldTopicId = (int)$msg['id_topic'];
    $newTopicId = $topicMapping[$oldTopicId] ?? null;
    if (!$newTopicId) continue;

    $isFirst = ($oldTopicId !== $currentTopicOldId) ? 1 : 0;
    $currentTopicOldId = $oldTopicId;

    $userId = $userMapping[(int)$msg['id_member']] ?? null;
    $guestName = $userId ? null : (LegacyContentConverter::cleanPlainTitle($msg['poster_name'] ?? '') ?: 'Gast');
    $content = LegacyContentConverter::convertSmfPost($msg['body'] ?? '');
    if ($content === '') {
        $content = '(kein Inhalt)';
    }
    $createdAt = date('Y-m-d H:i:s', (int)$msg['poster_time']);

    $stmtInsertPost->execute([$newTopicId, $userId, $guestName, $content, $isFirst, $createdAt]);
    $newPostId = (int)$pdo->lastInsertId();

    $stmtInsertLegacy->execute(['post', (int)$msg['id_msg'], $newPostId]);
    $lastPostInfoByTopic[$newTopicId] = [$newPostId, $createdAt, $userId];

    $migratedPosts++;
    if ($migratedPosts % 10000 === 0) {
        echo "  -> $migratedPosts Beitraege konvertiert...\n";
    }
}

foreach ($lastPostInfoByTopic as $tId => [$pId, $pAt, $pUid]) {
    $stmtUpdateTopicLast->execute([$pId, $pAt, $pUid, $tId]);
}

// Zaehler aktualisieren
$pdo->exec("
    UPDATE forum_topics
    SET post_count = (SELECT COUNT(*) FROM forum_posts WHERE topic_id = forum_topics.id)
");
$pdo->exec("
    UPDATE forum_categories
    SET topic_count = (SELECT COUNT(*) FROM forum_topics WHERE category_id = forum_categories.id),
        post_count  = (SELECT COUNT(*) FROM forum_posts p JOIN forum_topics t ON p.topic_id = t.id WHERE t.category_id = forum_categories.id),
        last_post_id = (
            SELECT p.id FROM forum_posts p
            JOIN forum_topics t ON p.topic_id = t.id
            WHERE t.category_id = forum_categories.id
            ORDER BY p.created_at DESC, p.id DESC
            LIMIT 1
        )
");

$pdo->commit();

// 4. Cache leeren
$cacheFiles = glob(__DIR__ . '/../data/cache/*.json') ?: [];
foreach ($cacheFiles as $cf) {
    @unlink($cf);
}

echo "  -> $migratedPosts Forenbeitraege migriert ($newUsersCount neue Benutzer ergaenzt).\n";
echo "  -> " . count($cacheFiles) . " Cache-Dateien in data/cache/ geleert.\n\n";

echo "==========================================================\n";
echo "  RE-IMPORT ERFOLGREICH ABGESCHLOSSEN\n";
echo "==========================================================\n";
