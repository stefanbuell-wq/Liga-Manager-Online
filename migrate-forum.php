<?php
/**
 * SMF to LMO26 Forum Migration Script
 *
 * Migriert Daten vom alten SMF 1.0 Forum in das neue lmo26 Forum-System.
 *
 * USAGE:
 *   php migrate-forum.php [options]
 *
 * OPTIONS:
 *   --dry-run       Zeigt an, was migriert werden würde, ohne Änderungen
 *   --verbose       Ausführliche Ausgaben
 *   --skip-users    User-Migration überspringen
 *   --limit=N       Nur N Topics migrieren (zum Testen)
 */

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CLI only
if (php_sapi_name() !== 'cli') {
    die("Dieses Script kann nur über die Kommandozeile ausgeführt werden.\n");
}

echo "===========================================\n";
echo "  SMF to LMO26 Forum Migration\n";
echo "===========================================\n\n";

// Parse command line arguments
$options = getopt('', ['dry-run', 'verbose', 'skip-users', 'limit:']);
$dryRun = isset($options['dry-run']);
$verbose = isset($options['verbose']);
$skipUsers = isset($options['skip-users']);
$limit = isset($options['limit']) ? intval($options['limit']) : 0;

if ($dryRun) {
    echo "[DRY-RUN MODE - Keine Änderungen werden gespeichert]\n\n";
}

// SMF Database Configuration
// XAMPP Standard: root ohne Passwort
$smf_config = [
    'host' => 'localhost',
    'database' => 'hafosmf',      // Datenbank-Name in phpMyAdmin
    'user' => 'root',             // XAMPP Standard
    'password' => '',             // XAMPP Standard (leer)
    'prefix' => 'smf_'
];

// New SQLite database
require_once __DIR__ . '/lib/LmoDatabase.php';

try {
    // Initialize forum schema
    echo "Initialisiere Forum-Schema...\n";
    LmoDatabase::createForumSchema();
    $pdo = LmoDatabase::getInstance();

    echo "SQLite-Verbindung hergestellt.\n\n";
} catch (Exception $e) {
    die("SQLite-Fehler: " . $e->getMessage() . "\n");
}

// Connect to SMF MySQL database
echo "Verbinde mit SMF MySQL-Datenbank...\n";
try {
    $mysql = new PDO(
        "mysql:host={$smf_config['host']};dbname={$smf_config['database']};charset=latin1",
        $smf_config['user'],
        $smf_config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    echo "MySQL-Verbindung hergestellt.\n\n";
} catch (PDOException $e) {
    die("MySQL-Fehler: " . $e->getMessage() . "\n");
}

$prefix = $smf_config['prefix'];

// Statistics
$stats = [
    'categories' => 0,
    'boards' => 0,
    'topics' => 0,
    'posts' => 0,
    'users' => 0
];

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Convert text from ISO-8859-1/Windows-1252 to UTF-8
 */
function convertEncoding($text) {
    if (empty($text)) return '';

    // Detect encoding and convert
    $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);

    if ($encoding === 'UTF-8') {
        return $text;
    }

    // Try Windows-1252 first (common for German text)
    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
    if ($converted !== false && strlen($converted) > 0) {
        return $converted;
    }

    // Fallback to ISO-8859-1
    return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
}

/**
 * Clean up SMF BBCode to simple text
 */
function cleanBBCode($text) {
    // Convert common BBCode to simple formatting
    // For now, just strip it - can be enhanced later
    $text = preg_replace('/\[b\](.*?)\[\/b\]/si', '**$1**', $text);
    $text = preg_replace('/\[i\](.*?)\[\/i\]/si', '*$1*', $text);
    $text = preg_replace('/\[u\](.*?)\[\/u\]/si', '_$1_', $text);
    $text = preg_replace('/\[url=(.*?)\](.*?)\[\/url\]/si', '$2 ($1)', $text);
    $text = preg_replace('/\[url\](.*?)\[\/url\]/si', '$1', $text);
    $text = preg_replace('/\[img\](.*?)\[\/img\]/si', '[Bild: $1]', $text);
    $text = preg_replace('/\[quote.*?\](.*?)\[\/quote\]/si', "\n> $1\n", $text);
    $text = preg_replace('/\[code\](.*?)\[\/code\]/si', "\n```\n$1\n```\n", $text);
    $text = preg_replace('/\[size=.*?\](.*?)\[\/size\]/si', '$1', $text);
    $text = preg_replace('/\[color=.*?\](.*?)\[\/color\]/si', '$1', $text);
    $text = preg_replace('/\[.*?\]/s', '', $text); // Remove remaining BBCode

    return trim($text);
}

// ============================================
// STEP 1: MIGRATE USERS (optional)
// ============================================

$userMapping = []; // old_id => new_id

if (!$skipUsers) {
    echo "=== SCHRITT 1: Benutzer migrieren ===\n";

    $stmt = $mysql->query("
        SELECT id_member, member_name, real_name, email_address, date_registered
        FROM {$prefix}members
        WHERE is_activated = 1
        ORDER BY id_member
    ");
    $members = $stmt->fetchAll();

    echo "Gefunden: " . count($members) . " aktive Benutzer\n";

    foreach ($members as $member) {
        $username = convertEncoding($member['member_name']);
        $displayName = convertEncoding($member['real_name']);
        $email = $member['email_address'];

        // Skip empty usernames
        if (empty($username)) continue;

        if ($verbose) {
            echo "  - Benutzer: {$username} ({$email})\n";
        }

        if (!$dryRun) {
            // Check if user already exists
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $check->execute([$username, $email]);
            $existing = $check->fetch();

            if ($existing) {
                $userMapping[$member['id_member']] = $existing['id'];
                if ($verbose) echo "    (existiert bereits als ID {$existing['id']})\n";
            } else {
                // Create user with random password (needs reset)
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, display_name, role, created_at)
                    VALUES (?, ?, ?, ?, 'user', datetime('now'))
                ");
                // Generate a random password hash (user will need to reset)
                $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmt->execute([$username, $email, $randomPassword, $displayName]);
                $newId = $pdo->lastInsertId();
                $userMapping[$member['id_member']] = $newId;
                $stats['users']++;
            }

            // Save legacy mapping
            if (isset($userMapping[$member['id_member']])) {
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO forum_legacy_map (entity_type, old_id, new_id) VALUES ('user', ?, ?)");
                $stmt->execute([$member['id_member'], $userMapping[$member['id_member']]]);
            }
        } else {
            $stats['users']++;
        }
    }

    echo "Benutzer migriert: {$stats['users']}\n\n";
} else {
    echo "=== SCHRITT 1: Benutzer-Migration übersprungen ===\n\n";
}

// ============================================
// STEP 2: MIGRATE CATEGORIES AND BOARDS
// ============================================

echo "=== SCHRITT 2: Kategorien und Boards migrieren ===\n";

$categoryMapping = []; // old_board_id => new_category_id

// In SMF 1.0, boards can act as categories (parent boards)
// We'll flatten the structure: each board becomes a category

$stmt = $mysql->query("
    SELECT id_board, id_cat, name, description, board_order
    FROM {$prefix}boards
    ORDER BY id_cat, board_order
");
$boards = $stmt->fetchAll();

echo "Gefunden: " . count($boards) . " Boards\n";

foreach ($boards as $board) {
    $name = convertEncoding($board['name']);
    $description = convertEncoding($board['description']);
    $sortOrder = intval($board['board_order']);

    if (empty($name)) continue;

    if ($verbose) {
        echo "  - Board: {$name}\n";
    }

    if (!$dryRun) {
        // Check if category exists
        $check = $pdo->prepare("SELECT id FROM forum_categories WHERE name = ?");
        $check->execute([$name]);
        $existing = $check->fetch();

        if ($existing) {
            $categoryMapping[$board['id_board']] = $existing['id'];
            if ($verbose) echo "    (existiert bereits als ID {$existing['id']})\n";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO forum_categories (name, description, sort_order, created_at)
                VALUES (?, ?, ?, datetime('now'))
            ");
            $stmt->execute([$name, $description, $sortOrder]);
            $newId = $pdo->lastInsertId();
            $categoryMapping[$board['id_board']] = $newId;
            $stats['categories']++;
        }

        // Save legacy mapping
        if (isset($categoryMapping[$board['id_board']])) {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO forum_legacy_map (entity_type, old_id, new_id) VALUES ('board', ?, ?)");
            $stmt->execute([$board['id_board'], $categoryMapping[$board['id_board']]]);
        }
    } else {
        $stats['categories']++;
    }
}

echo "Kategorien erstellt: {$stats['categories']}\n\n";

// ============================================
// STEP 3: MIGRATE TOPICS
// ============================================

echo "=== SCHRITT 3: Topics migrieren ===\n";

$topicMapping = []; // old_topic_id => new_topic_id

$limitClause = $limit > 0 ? "LIMIT {$limit}" : "";

$stmt = $mysql->query("
    SELECT t.id_topic, t.id_board, t.id_member_started, t.id_first_msg, t.id_last_msg,
           t.num_replies, t.num_views, t.is_sticky, t.locked,
           m.subject, m.poster_time
    FROM {$prefix}topics t
    JOIN {$prefix}messages m ON t.id_first_msg = m.id_msg
    ORDER BY t.id_topic
    {$limitClause}
");
$topics = $stmt->fetchAll();

echo "Gefunden: " . count($topics) . " Topics\n";

foreach ($topics as $topic) {
    // Skip if board not mapped
    if (!isset($categoryMapping[$topic['id_board']]) && !$dryRun) {
        if ($verbose) echo "  - Topic ID {$topic['id_topic']}: Board nicht gefunden, übersprungen\n";
        continue;
    }

    $title = convertEncoding($topic['subject']);
    if (empty($title)) {
        $title = 'Ohne Titel';
    }

    $categoryId = $categoryMapping[$topic['id_board']] ?? 1;
    $userId = $userMapping[$topic['id_member_started']] ?? null;
    $isSticky = intval($topic['is_sticky']);
    $isLocked = intval($topic['locked']);
    $viewCount = intval($topic['num_views']);
    $postCount = intval($topic['num_replies']) + 1;
    $createdAt = date('Y-m-d H:i:s', $topic['poster_time']);

    if ($verbose) {
        echo "  - Topic: " . substr($title, 0, 50) . "...\n";
    }

    if (!$dryRun) {
        // Check if topic exists
        $check = $pdo->prepare("SELECT new_id FROM forum_legacy_map WHERE entity_type = 'topic' AND old_id = ?");
        $check->execute([$topic['id_topic']]);
        $existing = $check->fetch();

        if ($existing) {
            $topicMapping[$topic['id_topic']] = $existing['new_id'];
            if ($verbose) echo "    (existiert bereits als ID {$existing['new_id']})\n";
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO forum_topics
                (category_id, user_id, title, is_sticky, is_locked, view_count, post_count, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$categoryId, $userId, $title, $isSticky, $isLocked, $viewCount, $postCount, $createdAt]);
            $newId = $pdo->lastInsertId();
            $topicMapping[$topic['id_topic']] = $newId;
            $stats['topics']++;

            // Save legacy mapping
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO forum_legacy_map (entity_type, old_id, new_id) VALUES ('topic', ?, ?)");
            $stmt->execute([$topic['id_topic'], $newId]);
        }
    } else {
        $stats['topics']++;
    }
}

echo "Topics migriert: {$stats['topics']}\n\n";

// ============================================
// STEP 4: MIGRATE POSTS (Messages)
// ============================================

echo "=== SCHRITT 4: Beiträge migrieren ===\n";

// Get all topics to process
$topicIds = array_keys($topicMapping);
if (empty($topicIds) && !$dryRun) {
    // Get from database
    $stmt = $pdo->query("SELECT old_id FROM forum_legacy_map WHERE entity_type = 'topic'");
    while ($row = $stmt->fetch()) {
        $topicIds[] = $row['old_id'];
    }
}

if (empty($topicIds)) {
    echo "Keine Topics zum Verarbeiten.\n\n";
} else {
    $totalPosts = 0;
    $processedTopics = 0;

    foreach ($topicIds as $oldTopicId) {
        $processedTopics++;

        // Get messages for this topic
        $stmt = $mysql->prepare("
            SELECT id_msg, id_topic, id_member, poster_name, poster_time, body
            FROM {$prefix}messages
            WHERE id_topic = ?
            ORDER BY id_msg
        ");
        $stmt->execute([$oldTopicId]);
        $messages = $stmt->fetchAll();

        $isFirst = true;
        foreach ($messages as $msg) {
            $newTopicId = $topicMapping[$oldTopicId] ?? null;
            if (!$newTopicId && !$dryRun) {
                // Try to get from legacy map
                $check = $pdo->prepare("SELECT new_id FROM forum_legacy_map WHERE entity_type = 'topic' AND old_id = ?");
                $check->execute([$oldTopicId]);
                $mapped = $check->fetch();
                if ($mapped) {
                    $newTopicId = $mapped['new_id'];
                }
            }

            if (!$newTopicId && !$dryRun) continue;

            $userId = $userMapping[$msg['id_member']] ?? null;
            $guestName = empty($userId) ? convertEncoding($msg['poster_name']) : null;
            $content = convertEncoding($msg['body']);
            $content = cleanBBCode($content);
            $createdAt = date('Y-m-d H:i:s', $msg['poster_time']);

            if (empty($content)) {
                $content = '(kein Inhalt)';
            }

            if (!$dryRun) {
                // Check if post exists
                $check = $pdo->prepare("SELECT new_id FROM forum_legacy_map WHERE entity_type = 'post' AND old_id = ?");
                $check->execute([$msg['id_msg']]);
                $existing = $check->fetch();

                if (!$existing) {
                    $stmt = $pdo->prepare("
                        INSERT INTO forum_posts
                        (topic_id, user_id, guest_name, content, is_first_post, created_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$newTopicId, $userId, $guestName, $content, $isFirst ? 1 : 0, $createdAt]);
                    $postId = $pdo->lastInsertId();

                    // Save legacy mapping
                    $stmt = $pdo->prepare("INSERT OR IGNORE INTO forum_legacy_map (entity_type, old_id, new_id) VALUES ('post', ?, ?)");
                    $stmt->execute([$msg['id_msg'], $postId]);

                    // Update topic's last_post info if this is the last message
                    if ($isFirst || $msg === end($messages)) {
                        $stmt = $pdo->prepare("
                            UPDATE forum_topics
                            SET last_post_id = ?, last_post_at = ?, last_post_user_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$postId, $createdAt, $userId, $newTopicId]);
                    }

                    $stats['posts']++;
                    $totalPosts++;
                }
            } else {
                $stats['posts']++;
                $totalPosts++;
            }

            $isFirst = false;
        }

        // Progress update every 100 topics
        if ($processedTopics % 100 === 0) {
            echo "  Verarbeitet: {$processedTopics} Topics, {$totalPosts} Beiträge...\n";
        }
    }

    echo "Beiträge migriert: {$stats['posts']}\n\n";
}

// ============================================
// STEP 5: UPDATE COUNTERS
// ============================================

if (!$dryRun) {
    echo "=== SCHRITT 5: Zähler aktualisieren ===\n";

    // Update category counters
    $pdo->exec("
        UPDATE forum_categories
        SET topic_count = (SELECT COUNT(*) FROM forum_topics WHERE category_id = forum_categories.id),
            post_count = (SELECT COUNT(*) FROM forum_posts p
                          JOIN forum_topics t ON p.topic_id = t.id
                          WHERE t.category_id = forum_categories.id)
    ");

    // Update topic counters
    $pdo->exec("
        UPDATE forum_topics
        SET post_count = (SELECT COUNT(*) FROM forum_posts WHERE topic_id = forum_topics.id)
    ");

    echo "Zähler aktualisiert.\n\n";
}

// ============================================
// SUMMARY
// ============================================

echo "===========================================\n";
echo "  MIGRATION ABGESCHLOSSEN\n";
echo "===========================================\n";
echo "Ergebnisse:\n";
echo "  - Benutzer:    {$stats['users']}\n";
echo "  - Kategorien:  {$stats['categories']}\n";
echo "  - Topics:      {$stats['topics']}\n";
echo "  - Beiträge:    {$stats['posts']}\n";
echo "===========================================\n";

if ($dryRun) {
    echo "\n[DRY-RUN] Keine Daten wurden tatsächlich migriert.\n";
    echo "Führen Sie das Script ohne --dry-run aus, um die Migration durchzuführen.\n";
}

echo "\nHinweise:\n";
echo "- Benutzer haben ein zufälliges Passwort erhalten und müssen sich neu registrieren\n";
echo "- BBCode wurde in einfache Textformatierung konvertiert\n";
echo "- Legacy-Mappings wurden in forum_legacy_map gespeichert\n";
echo "\n";
