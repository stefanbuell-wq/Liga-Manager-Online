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
        }

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

    // News-Liste (Paginierung)
    if (isset($_GET['list'])) {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

        $newsList = $repo->getNewsList($limit, $offset);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y', $news['timestamp'])
                : null;
        }

        echo json_encode([
            'success' => true,
            'limit' => $limit,
            'offset' => $offset,
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
