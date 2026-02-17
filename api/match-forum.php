<?php
/**
 * Match-Forum Integration API
 * Verknüpft Liga-Manager Spiele/Spieltage mit Forum-Topics
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib/ForumRepository.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/Security.php';

Security::initSession();

$repo = new ForumRepository();
$pdo = LmoDatabase::getInstance();

// Benutzerrolle ermitteln
$userId = $_SESSION['lmo26_user_id'] ?? null;
$userRole = $userId ? $repo->getUserRole($userId) : null;

try {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];

    // CSRF prüfen bei POST/DELETE
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'DELETE'])) {
        if (!Security::verifyCsrfToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ungültiges CSRF-Token']);
            exit;
        }

        // Editor oder Admin erforderlich für Schreiboperationen
        if (!in_array($userRole, ['editor', 'admin'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Editor-Berechtigung erforderlich']);
            exit;
        }
    }

    $action = $input['action'] ?? $_GET['action'] ?? '';

    // ==================== GET Requests ====================

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

        switch ($action) {
            case 'match_topics':
                // Topics für ein Spiel abrufen
                $matchId = intval($_GET['match_id'] ?? 0);
                if ($matchId <= 0) {
                    throw new Exception('Kein Spiel angegeben');
                }

                $topics = $repo->getTopicsByMatch($matchId);
                $links = $repo->getForumLinksForMatch($matchId);

                echo json_encode([
                    'success' => true,
                    'topics' => $topics,
                    'links' => $links,
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'round_topics':
                // Topics für einen Spieltag abrufen
                $leagueId = intval($_GET['league_id'] ?? 0);
                $roundNr = intval($_GET['round_nr'] ?? 0);

                if ($leagueId <= 0 || $roundNr <= 0) {
                    throw new Exception('Liga und Spieltag erforderlich');
                }

                $topics = $repo->getTopicsByMatchday($leagueId, $roundNr);
                $links = $repo->getForumLinksForRound($leagueId, $roundNr);

                echo json_encode([
                    'success' => true,
                    'topics' => $topics,
                    'links' => $links,
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'league_topics':
                // Topics für eine Liga abrufen
                $leagueId = intval($_GET['league_id'] ?? 0);
                $limit = min(50, intval($_GET['limit'] ?? 20));

                if ($leagueId <= 0) {
                    throw new Exception('Keine Liga angegeben');
                }

                $topics = $repo->getTopicsByLeague($leagueId, $limit);
                $links = $repo->getForumLinksForLeague($leagueId, $limit);

                echo json_encode([
                    'success' => true,
                    'topics' => $topics,
                    'links' => $links,
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'leagues':
                // Alle Ligen mit Forum-Kategorien
                $leagues = $repo->getLeaguesWithCategories();

                echo json_encode([
                    'success' => true,
                    'leagues' => $leagues,
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            default:
                echo json_encode([
                    'success' => true,
                    'actions' => [
                        'GET' => ['match_topics', 'round_topics', 'league_topics', 'leagues'],
                        'POST' => ['create_link', 'create_matchday_topic', 'create_report_topic', 'create_league_category']
                    ],
                    'csrf_token' => Security::getCsrfToken()
                ]);
        }
    }

    // ==================== POST Requests ====================

    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

        switch ($action) {
            case 'create_link':
                // Manuelle Verknüpfung erstellen
                $topicId = intval($input['forum_topic_id'] ?? 0);
                $leagueId = intval($input['league_id'] ?? 0) ?: null;
                $roundNr = intval($input['round_nr'] ?? 0) ?: null;
                $matchId = intval($input['match_id'] ?? 0) ?: null;
                $linkType = $input['link_type'] ?? 'discussion';

                if ($topicId <= 0) {
                    throw new Exception('Kein Forum-Topic angegeben');
                }

                // Topic muss existieren
                $topic = $repo->getTopicById($topicId);
                if (!$topic) {
                    throw new Exception('Forum-Topic nicht gefunden');
                }

                $linkId = $repo->createMatchForumLink($topicId, $leagueId, $roundNr, $matchId, $linkType, $userId, false);

                echo json_encode([
                    'success' => true,
                    'link_id' => $linkId,
                    'message' => 'Verknüpfung erstellt',
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'create_matchday_topic':
                // Spieltag-Topic automatisch erstellen
                $leagueId = intval($input['league_id'] ?? 0);
                $roundNr = intval($input['round_nr'] ?? 0);
                $content = trim($input['content'] ?? '');

                if ($leagueId <= 0 || $roundNr <= 0) {
                    throw new Exception('Liga und Spieltag erforderlich');
                }

                // Liga-Daten laden
                $stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
                $stmt->execute([$leagueId]);
                $league = $stmt->fetch();

                if (!$league) {
                    throw new Exception('Liga nicht gefunden');
                }

                // Prüfen ob bereits existiert
                if ($repo->matchdayTopicExists($leagueId, $roundNr)) {
                    throw new Exception('Spieltag-Topic existiert bereits');
                }

                // Kategorie erstellen/abrufen
                $category = $repo->getOrCreateLeagueCategory($leagueId, $league['name']);

                // Topic erstellen
                $topicId = $repo->createMatchdayTopic(
                    $leagueId,
                    $roundNr,
                    $league['name'],
                    $category['id'],
                    $userId,
                    $content
                );

                echo json_encode([
                    'success' => true,
                    'topic_id' => $topicId,
                    'category_id' => $category['id'],
                    'message' => "Spieltag-Topic erstellt",
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'create_report_topic':
                // Spielbericht-Topic erstellen
                $matchId = intval($input['match_id'] ?? 0);
                $title = trim($input['title'] ?? '');
                $content = trim($input['content'] ?? '');
                $categoryId = intval($input['category_id'] ?? 0);

                if ($matchId <= 0) {
                    throw new Exception('Kein Spiel angegeben');
                }
                if (strlen($title) < 5) {
                    throw new Exception('Titel muss mindestens 5 Zeichen haben');
                }
                if (strlen($content) < 10) {
                    throw new Exception('Inhalt muss mindestens 10 Zeichen haben');
                }

                // Match-Daten laden für Liga
                $stmt = $pdo->prepare("
                    SELECT m.*, l.name as league_name
                    FROM matches m
                    JOIN leagues l ON m.league_id = l.id
                    WHERE m.id = ?
                ");
                $stmt->execute([$matchId]);
                $match = $stmt->fetch();

                if (!$match) {
                    throw new Exception('Spiel nicht gefunden');
                }

                // Kategorie bestimmen
                if ($categoryId <= 0) {
                    // Liga-Kategorie verwenden
                    $category = $repo->getOrCreateLeagueCategory($match['league_id'], $match['league_name']);
                    $categoryId = $category['id'];
                }

                $topicId = $repo->createMatchReportTopic($matchId, $categoryId, $title, $content, $userId);

                echo json_encode([
                    'success' => true,
                    'topic_id' => $topicId,
                    'message' => 'Spielbericht erstellt',
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'create_league_category':
                // Kategorie für Liga erstellen
                $leagueId = intval($input['league_id'] ?? 0);

                if ($leagueId <= 0) {
                    throw new Exception('Keine Liga angegeben');
                }

                // Liga laden
                $stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
                $stmt->execute([$leagueId]);
                $league = $stmt->fetch();

                if (!$league) {
                    throw new Exception('Liga nicht gefunden');
                }

                $category = $repo->getOrCreateLeagueCategory($leagueId, $league['name']);

                echo json_encode([
                    'success' => true,
                    'category' => $category,
                    'message' => $category ? 'Kategorie erstellt/gefunden' : 'Fehler',
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'delete_link':
                // Verknüpfung löschen
                $linkId = intval($input['link_id'] ?? 0);

                if ($linkId <= 0) {
                    throw new Exception('Keine Verknüpfung angegeben');
                }

                $repo->deleteMatchForumLink($linkId);

                echo json_encode([
                    'success' => true,
                    'message' => 'Verknüpfung gelöscht',
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            case 'bulk_create_matchday_topics':
                // Mehrere Spieltag-Topics auf einmal erstellen
                $leagueId = intval($input['league_id'] ?? 0);
                $fromRound = intval($input['from_round'] ?? 1);
                $toRound = intval($input['to_round'] ?? 0);

                if ($leagueId <= 0) {
                    throw new Exception('Keine Liga angegeben');
                }

                // Liga laden
                $stmt = $pdo->prepare("SELECT * FROM leagues WHERE id = ?");
                $stmt->execute([$leagueId]);
                $league = $stmt->fetch();

                if (!$league) {
                    throw new Exception('Liga nicht gefunden');
                }

                // Max Spieltage aus options
                $options = json_decode($league['options'] ?? '{}', true);
                $maxRounds = $options['Rounds'] ?? $toRound;
                if ($toRound <= 0 || $toRound > $maxRounds) {
                    $toRound = $maxRounds;
                }

                // Kategorie erstellen/abrufen
                $category = $repo->getOrCreateLeagueCategory($leagueId, $league['name']);

                $created = 0;
                $skipped = 0;

                for ($round = $fromRound; $round <= $toRound; $round++) {
                    if ($repo->matchdayTopicExists($leagueId, $round)) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $repo->createMatchdayTopic(
                            $leagueId,
                            $round,
                            $league['name'],
                            $category['id'],
                            $userId
                        );
                        $created++;
                    } catch (Exception $e) {
                        // Ignorieren, weitermachen
                    }
                }

                echo json_encode([
                    'success' => true,
                    'created' => $created,
                    'skipped' => $skipped,
                    'message' => "$created Topics erstellt, $skipped übersprungen",
                    'csrf_token' => Security::getCsrfToken()
                ]);
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $action);
        }
    }

    // ==================== DELETE Requests ====================

    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $linkId = intval($input['link_id'] ?? $_GET['link_id'] ?? 0);

        if ($linkId <= 0) {
            throw new Exception('Keine Verknüpfung angegeben');
        }

        $repo->deleteMatchForumLink($linkId);

        echo json_encode([
            'success' => true,
            'message' => 'Verknüpfung gelöscht',
            'csrf_token' => Security::getCsrfToken()
        ]);
    }

    else {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ]);
}
