<?php
/**
 * News Admin API - CRUD für Spielberichte/News
 */

require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/Security.php';

header('Content-Type: application/json; charset=utf-8');

$security = new Security();

// Mindestens Editor-Rechte erforderlich
if (!$security->requirePermission('editor')) {
    http_response_code(403);
    echo json_encode(['error' => 'Keine Berechtigung']);
    exit;
}

$pdo = LmoDatabase::getInstance();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';

        switch ($action) {
            case 'list':
                // News-Liste mit Paginierung und Suche
                $page = max(1, intval($_GET['page'] ?? 1));
                $limit = min(100, max(10, intval($_GET['limit'] ?? 20)));
                $offset = ($page - 1) * $limit;
                $search = $_GET['search'] ?? '';

                $where = '';
                $params = [];

                if ($search) {
                    $useFts = false;
                    try { $pdo->query("SELECT 1 FROM news_fts LIMIT 1"); $useFts = true; } catch (Exception $e) {}
                    $parts = [];
                    $params[':title'] = $search . '%';
                    $params[':author'] = $search . '%';
                    $parts[] = "(title LIKE :title COLLATE NOCASE OR author LIKE :author COLLATE NOCASE)";
                    if ($useFts) {
                        $tokens = preg_split('/\s+/', $search);
                        $q = [];
                        foreach ($tokens as $t) { $t = trim($t); if ($t !== '') $q[] = $t . '*'; }
                        $fts = implode(' AND ', $q);
                        $params[':fts'] = $fts;
                        $parts[] = "id IN (SELECT rowid FROM news_fts WHERE news_fts MATCH :fts)";
                    } else {
                        $params[':contains'] = '%' . $search . '%';
                        $parts[] = "short_content LIKE :contains";
                    }
                    $where = "WHERE " . implode(" OR ", $parts);
                }

                // Total count
                $countSql = "SELECT COUNT(*) FROM news $where";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($params);
                $total = $countStmt->fetchColumn();

                $useFtsOrder = false;
                if ($search) {
                    try { $pdo->query("SELECT 1 FROM news_fts LIMIT 1"); $useFtsOrder = true; } catch (Exception $e) {}
                }
                if ($useFtsOrder) {
                    $wTitle = isset($_GET['w_title']) ? max(0.0, min(10.0, floatval($_GET['w_title']))) : 5.0;
                    $wShort = isset($_GET['w_short']) ? max(0.0, min(10.0, floatval($_GET['w_short']))) : 2.0;
                    $wContent = isset($_GET['w_content']) ? max(0.0, min(10.0, floatval($_GET['w_content']))) : 1.0;
                    $wAuthor = isset($_GET['w_author']) ? max(0.0, min(10.0, floatval($_GET['w_author']))) : 3.0;
                    $bmArgs = $wTitle . ", " . $wShort . ", " . $wContent . ", " . $wAuthor;
                    $sql = "SELECT n.id, n.title, n.short_content, n.author, n.timestamp, n.match_date,
                                   (SELECT bm25(news_fts, $bmArgs) FROM news_fts WHERE rowid = n.id AND news_fts MATCH :fts) AS rank_score
                            FROM news n " . $where . "
                            ORDER BY CASE WHEN rank_score IS NULL THEN 1 ELSE 0 END, rank_score ASC, n.timestamp DESC
                            LIMIT :limit OFFSET :offset";
                    $stmt = $pdo->prepare($sql);
                } else {
                    $sql = "SELECT id, title, short_content, author, timestamp, match_date
                            FROM news $where
                            ORDER BY timestamp DESC
                            LIMIT :limit OFFSET :offset";
                    $stmt = $pdo->prepare($sql);
                }
                foreach ($params as $key => $val) {
                    $stmt->bindValue($key, $val);
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                try {
                    $stmt->execute();
                    $news = $stmt->fetchAll();
                } catch (Exception $e) {
                    if ($useFtsOrder) {
                        $sql = "SELECT id, title, short_content, author, timestamp, match_date
                                FROM news $where
                                ORDER BY timestamp DESC
                                LIMIT :limit OFFSET :offset";
                        $stmt = $pdo->prepare($sql);
                        foreach ($params as $key => $val) {
                            $stmt->bindValue($key, $val);
                        }
                        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                        $stmt->execute();
                        $news = $stmt->fetchAll();
                    } else {
                        throw $e;
                    }
                }

                // Datum formatieren
                foreach ($news as &$n) {
                    $n['date_formatted'] = $n['timestamp'] > 0
                        ? date('d.m.Y H:i', $n['timestamp'])
                        : ($n['match_date'] ?? '-');
                }

                echo json_encode([
                    'success' => true,
                    'news' => $news,
                    'total' => $total,
                    'page' => $page,
                    'pages' => ceil($total / $limit),
                    'limit' => $limit
                ]);
                break;

            case 'get':
                // Einzelne News laden
                $id = intval($_GET['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Keine ID angegeben');
                }

                $stmt = $pdo->prepare("SELECT * FROM news WHERE id = ?");
                $stmt->execute([$id]);
                $news = $stmt->fetch();

                if (!$news) {
                    throw new Exception('News nicht gefunden');
                }

                // Verknüpfte Matches laden
                $stmt = $pdo->prepare("
                    SELECT m.id, m.match_date, t1.name as home, t2.name as guest, mn.confidence
                    FROM match_news mn
                    JOIN matches m ON mn.match_id = m.id
                    JOIN teams t1 ON m.home_team_id = t1.id
                    JOIN teams t2 ON m.guest_team_id = t2.id
                    WHERE mn.news_id = ?
                    ORDER BY mn.confidence DESC
                ");
                $stmt->execute([$id]);
                $news['linked_matches'] = $stmt->fetchAll();

                echo json_encode([
                    'success' => true,
                    'news' => $news
                ]);
                break;

            case 'search_matches':
                // Matches für Verknüpfung suchen
                $query = $_GET['query'] ?? '';
                $date = $_GET['date'] ?? '';

                $sql = "SELECT m.id, m.match_date, m.round_nr, l.name as league_name,
                               t1.name as home, t2.name as guest
                        FROM matches m
                        JOIN teams t1 ON m.home_team_id = t1.id
                        JOIN teams t2 ON m.guest_team_id = t2.id
                        JOIN leagues l ON m.league_id = l.id
                        WHERE 1=1";
                $params = [];

                if ($query) {
                    $useFtsT = false;
                    try { $pdo->query("SELECT 1 FROM teams_fts LIMIT 1"); $useFtsT = true; } catch (Exception $e) {}
                    if ($useFtsT) {
                        $tokens = preg_split('/\s+/', $query);
                        $q = [];
                        foreach ($tokens as $t) { $t = trim($t); if ($t !== '') $q[] = $t . '*'; }
                        $qfts = implode(' AND ', $q);
                        $sql .= " AND (t1.id IN (SELECT rowid FROM teams_fts WHERE teams_fts MATCH :qfts) OR t2.id IN (SELECT rowid FROM teams_fts WHERE teams_fts MATCH :qfts))";
                        $params[':qfts'] = $qfts;
                    } else {
                        $sql .= " AND (t1.name LIKE :q COLLATE NOCASE OR t2.name LIKE :q COLLATE NOCASE)";
                        $params[':q'] = $query . '%';
                    }
                }
                if ($date) {
                    $sql .= " AND m.match_date = :date";
                    $params[':date'] = $date;
                }

                $sql .= " ORDER BY m.match_date DESC LIMIT 50";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                echo json_encode([
                    'success' => true,
                    'matches' => $stmt->fetchAll()
                ]);
                break;

            default:
                throw new Exception('Unbekannte Aktion');
        }

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        // CSRF-Prüfung
        if (!$security->validateCsrfToken($input['csrf_token'] ?? '')) {
            throw new Exception('Ungültiger CSRF-Token');
        }

        switch ($action) {
            case 'create':
                // Neue News erstellen
                $title = trim($input['title'] ?? '');
                $shortContent = trim($input['short_content'] ?? '');
                $content = trim($input['content'] ?? '');
                $author = trim($input['author'] ?? 'Redaktion');
                $matchDate = $input['match_date'] ?? null;

                if (!$title) {
                    throw new Exception('Titel ist erforderlich');
                }

                $timestamp = time();

                $stmt = $pdo->prepare("
                    INSERT INTO news (title, short_content, content, author, timestamp, match_date)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $shortContent, $content, $author, $timestamp, $matchDate]);
                $newId = $pdo->lastInsertId();

                // Match-Verknüpfungen erstellen
                if (!empty($input['match_ids'])) {
                    $linkStmt = $pdo->prepare("INSERT OR IGNORE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, 1.0)");
                    foreach ($input['match_ids'] as $matchId) {
                        $linkStmt->execute([intval($matchId), $newId]);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'id' => $newId,
                    'message' => 'News erstellt'
                ]);
                break;

            case 'update':
                // News aktualisieren
                $id = intval($input['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Keine ID angegeben');
                }

                $title = trim($input['title'] ?? '');
                $shortContent = trim($input['short_content'] ?? '');
                $content = trim($input['content'] ?? '');
                $author = trim($input['author'] ?? 'Redaktion');
                $matchDate = $input['match_date'] ?? null;

                if (!$title) {
                    throw new Exception('Titel ist erforderlich');
                }

                $stmt = $pdo->prepare("
                    UPDATE news
                    SET title = ?, short_content = ?, content = ?, author = ?, match_date = ?
                    WHERE id = ?
                ");
                $stmt->execute([$title, $shortContent, $content, $author, $matchDate, $id]);

                // Match-Verknüpfungen aktualisieren
                if (isset($input['match_ids'])) {
                    // Alte löschen
                    $pdo->prepare("DELETE FROM match_news WHERE news_id = ?")->execute([$id]);

                    // Neue erstellen
                    if (!empty($input['match_ids'])) {
                        $linkStmt = $pdo->prepare("INSERT INTO match_news (match_id, news_id, confidence) VALUES (?, ?, 1.0)");
                        foreach ($input['match_ids'] as $matchId) {
                            $linkStmt->execute([intval($matchId), $id]);
                        }
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'News aktualisiert'
                ]);
                break;

            case 'delete':
                // News löschen
                $id = intval($input['id'] ?? 0);
                if (!$id) {
                    throw new Exception('Keine ID angegeben');
                }

                // Verknüpfungen löschen
                $pdo->prepare("DELETE FROM match_news WHERE news_id = ?")->execute([$id]);

                // News löschen
                $stmt = $pdo->prepare("DELETE FROM news WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'News gelöscht'
                ]);
                break;

            case 'link_match':
                // Match mit News verknüpfen
                $newsId = intval($input['news_id'] ?? 0);
                $matchId = intval($input['match_id'] ?? 0);

                if (!$newsId || !$matchId) {
                    throw new Exception('News-ID und Match-ID erforderlich');
                }

                $stmt = $pdo->prepare("INSERT OR REPLACE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, 1.0)");
                $stmt->execute([$matchId, $newsId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Verknüpfung erstellt'
                ]);
                break;

            case 'unlink_match':
                // Match-Verknüpfung entfernen
                $newsId = intval($input['news_id'] ?? 0);
                $matchId = intval($input['match_id'] ?? 0);

                if (!$newsId || !$matchId) {
                    throw new Exception('News-ID und Match-ID erforderlich');
                }

                $stmt = $pdo->prepare("DELETE FROM match_news WHERE match_id = ? AND news_id = ?");
                $stmt->execute([$matchId, $newsId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Verknüpfung entfernt'
                ]);
                break;

            default:
                throw new Exception('Unbekannte Aktion');
        }

    } else {
        throw new Exception('Methode nicht erlaubt');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
