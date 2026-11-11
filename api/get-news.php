<?php
/**
 * API-Endpunkt: News/Spielbericht abrufen
 *
 * Parameter:
 *   - id: News-ID (einzelner Artikel)
 *   - match_id: Match-ID (alle News zu einem Spiel)
 *   - list: 1 (Liste aller News mit Paginierung)
 *   - limit: Anzahl (Standard: 20)
 *   - offset: Start-Position (Standard: 0)
 *   - search: Suchbegriff
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/LmoRepository.php';

require_once __DIR__ . '/../lib/NewsContent.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

/** Erstes verknuepftes Spiel (Liga, Heim, Gast) je News-ID */
function linkedMatchInfo(PDO $pdo, array $ids): array
{
    if (!$ids) return [];
    $in = implode(',', array_map('intval', $ids));
    $rows = $pdo->query("
        SELECT mn.news_id, l.file AS league_file, l.name AS league_name,
               th.name AS home, tg.name AS guest
        FROM match_news mn
        JOIN matches m ON m.id = mn.match_id
        JOIN leagues l ON l.id = m.league_id
        LEFT JOIN teams th ON th.id = m.home_team_id
        LEFT JOIN teams tg ON tg.id = m.guest_team_id
        WHERE mn.news_id IN ($in)
        ORDER BY mn.news_id, mn.confidence DESC, m.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $id = $r['news_id'];
        if (!isset($out[$id])) { $r['match_count'] = 0; $out[$id] = $r; }
        $out[$id]['match_count']++;
    }
    return $out;
}

try {
    $repo = new LmoRepository();

    // Einzelne News abrufen
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $news = $repo->getNewsById($id);

        if (!$news) {
            http_response_code(404);
            echo json_encode(['error' => 'News nicht gefunden', 'id' => $id]);
            exit;
        }

        // Datum formatieren
        $news['date_formatted'] = $news['timestamp'] > 0
            ? date('d.m.Y H:i', $news['timestamp'])
            : null;

        // Anzeige aufbereiten: Altbilder, Wappen, Autor (Inhalt selbst bleibt unveraendert)
        $pdo = LmoDatabase::getInstance();
        $link = linkedMatchInfo($pdo, [$id])[$id] ?? null;
        $teams = ($link && $link['match_count'] == 1 && $link['home'] && $link['guest']) ? [$link['home'], $link['guest']] : null;
        $news['author'] = NewsContent::displayAuthor($news['author'] ?? '', $news['content'] ?? '');
        $news['content'] = NewsContent::prepare($news['content'] ?? '', $teams);
        $news['short_content'] = NewsContent::sanitize(NewsContent::rewriteImages($news['short_content'] ?? ''));
        $news['league_name'] = $link['league_name'] ?? null;
        $news['league_file'] = $link['league_file'] ?? null;
        unset($news['email']);

        echo json_encode([
            'success' => true,
            'news' => $news
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News für ein Match abrufen
    if (isset($_GET['match_id'])) {
        $matchId = (int) $_GET['match_id'];
        $newsList = $repo->getNewsForMatch($matchId);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y H:i', $news['timestamp'])
                : null;
            $news['author'] = NewsContent::displayAuthor($news['author'] ?? '', $news['content'] ?? '');
            $news['content'] = NewsContent::prepare($news['content'] ?? '');
            unset($news['email']);
        }
        unset($news);

        echo json_encode([
            'success' => true,
            'match_id' => $matchId,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News-Suche
    if (isset($_GET['search'])) {
        $query = trim($_GET['search']);
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        $newsList = $repo->searchNews($query, $limit);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y', $news['timestamp'])
                : null;
        }

        echo json_encode([
            'success' => true,
            'query' => $query,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News-Liste (Paginierung), optional nur Artikel mit Spielen einer Liga
    if (isset($_GET['list'])) {
        $limit = max(1, min(50, isset($_GET['limit']) ? (int) $_GET['limit'] : 20));
        $offset = max(0, isset($_GET['offset']) ? (int) $_GET['offset'] : 0);
        $leagueFile = isset($_GET['league']) ? trim((string)$_GET['league']) : '';
        $pdo = LmoDatabase::getInstance();

        $where = '';
        $params = [];
        if ($leagueFile !== '') {
            $where = 'WHERE EXISTS (SELECT 1 FROM match_news mn JOIN matches m ON m.id = mn.match_id
                       JOIN leagues l ON l.id = m.league_id WHERE mn.news_id = n.id AND l.file = :league)';
            $params[':league'] = $leagueFile;
        }
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM news n $where");
        $cnt->execute($params);
        $total = (int)$cnt->fetchColumn();

        $stmt = $pdo->prepare("SELECT n.id, n.title, n.short_content, n.author, n.timestamp, n.match_date,
                                      substr(n.content, 1, 4000) AS head
                               FROM news n $where
                               ORDER BY n.timestamp DESC, n.id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $newsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $links = linkedMatchInfo($pdo, array_column($newsList, 'id'));
        foreach ($newsList as &$news) {
            $head = $news['head'] ?? '';
            unset($news['head']);
            $link = $links[$news['id']] ?? null;
            $news['date_formatted'] = $news['timestamp'] > 0 ? date('d.m.Y', $news['timestamp']) : null;
            $news['author'] = NewsContent::displayAuthor($news['author'], $head);
            $news['preview'] = NewsContent::excerpt($news['short_content'], $head);
            $news['kind'] = NewsContent::kind((string)$news['title'], $head, (bool)$link);
            $news['league_name'] = $link['league_name'] ?? null;
            $news['league_file'] = $link['league_file'] ?? null;
            $teams = ($link && $link['match_count'] == 1) ? [$link['home'], $link['guest']] : NewsContent::teamsFromScoreLine($head);
            $news['logos'] = $teams ? array_values(array_filter([NewsContent::teamLogo($teams[0]), NewsContent::teamLogo($teams[1])])) : [];
            if (count($news['logos']) !== 2) $news['logos'] = [];
        }
        unset($news);

        echo json_encode([
            'success' => true,
            'limit' => $limit,
            'offset' => $offset,
            'total' => $total,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Keine Parameter - Hilfe ausgeben
    echo json_encode([
        'error' => 'Parameter fehlt',
        'usage' => [
            'id' => 'News-ID für einzelnen Artikel',
            'match_id' => 'Match-ID für alle News zu einem Spiel',
            'list' => '1 für paginierte Liste',
            'search' => 'Suchbegriff',
            'limit' => 'Anzahl (Standard: 20)',
            'offset' => 'Start-Position (Standard: 0)'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Serverfehler',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
