<?php
/**
 * Forum Topics API
 * GET: Topics einer Kategorie abrufen
 * POST: Neues Topic erstellen (Auth required)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib/ForumRepository.php';
require_once __DIR__ . '/../lib/Security.php';

Security::initSession();

$repo = new ForumRepository();

try {
    // Benutzerrolle ermitteln
    $userId = $_SESSION['lmo26_user_id'] ?? null;
    $userRole = $userId ? $repo->getUserRole($userId) : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Topics abrufen
        $categoryId = isset($_GET['category']) ? intval($_GET['category']) : 0;
        $topicId = isset($_GET['id']) ? intval($_GET['id']) : 0;
        $limit = isset($_GET['limit']) ? min(50, intval($_GET['limit'])) : 20;
        $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
        $forHomepage = isset($_GET['homepage']);

        if ($topicId > 0) {
            // Einzelnes Topic mit Posts
            $topic = $repo->getTopicById($topicId);
            if (!$topic) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Topic nicht gefunden']);
                exit;
            }

            // Berechtigung prüfen
            if (!$repo->canViewCategory($topic['category_id'], $userRole)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Keine Berechtigung für diese Kategorie']);
                exit;
            }

            // View-Counter erhöhen
            $repo->incrementTopicViews($topicId);

            $posts = $repo->getPostsByTopic($topicId, $limit, $offset);
            $totalPosts = $repo->getPostCountByTopic($topicId);

            // Berechtigungsinfo hinzufügen
            $canReply = !$topic['is_locked'] && $repo->canReplyInCategory($topic['category_id'], $userRole);

            echo json_encode([
                'success' => true,
                'topic' => $topic,
                'posts' => $posts,
                'pagination' => [
                    'total' => $totalPosts,
                    'limit' => $limit,
                    'offset' => $offset,
                    'pages' => ceil($totalPosts / $limit)
                ],
                'permissions' => [
                    'can_reply' => $canReply,
                    'can_edit' => $userRole === 'admin' || $userRole === 'editor',
                    'can_delete' => $userRole === 'admin'
                ],
                'user' => [
                    'id' => $userId,
                    'role' => $userRole,
                    'logged_in' => $userId !== null
                ],
                'csrf_token' => Security::getCsrfToken()
            ]);

        } elseif ($categoryId > 0) {
            // Topics einer Kategorie
            $category = $repo->getCategoryById($categoryId);
            if (!$category) {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Kategorie nicht gefunden']);
                exit;
            }

            // Berechtigung prüfen
            if (!$repo->canViewCategory($categoryId, $userRole)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Keine Berechtigung für diese Kategorie']);
                exit;
            }

            $topics = $repo->getTopicsByCategory($categoryId, $limit, $offset);
            $totalTopics = $repo->getTopicCountByCategory($categoryId);

            // Berechtigungsinfo hinzufügen
            $canCreate = $repo->canCreateTopicInCategory($categoryId, $userRole);
            $canReply = $repo->canReplyInCategory($categoryId, $userRole);

            echo json_encode([
                'success' => true,
                'category' => $category,
                'topics' => $topics,
                'pagination' => [
                    'total' => $totalTopics,
                    'limit' => $limit,
                    'offset' => $offset,
                    'pages' => ceil($totalTopics / $limit)
                ],
                'permissions' => [
                    'can_create_topic' => $canCreate,
                    'can_reply' => $canReply
                ],
                'user' => [
                    'id' => $userId,
                    'role' => $userRole,
                    'logged_in' => $userId !== null
                ],
                'csrf_token' => Security::getCsrfToken()
            ]);

        } else {
            // Neueste Topics (oder Homepage-Topics)
            if ($forHomepage) {
                $topics = $repo->getHomepageTopics($limit);
            } else {
                $topics = $repo->getRecentTopics($limit, $userRole);
            }

            echo json_encode([
                'success' => true,
                'topics' => $topics,
                'user' => [
                    'id' => $userId,
                    'role' => $userRole,
                    'logged_in' => $userId !== null
                ],
                'csrf_token' => Security::getCsrfToken()
            ]);
        }

    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Neues Topic erstellen
        $input = json_decode(file_get_contents('php://input'), true);

        // CSRF prüfen
        if (!Security::verifyCsrfToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ungültiges CSRF-Token']);
            exit;
        }

        $categoryId = intval($input['category_id'] ?? 0);
        $title = trim($input['title'] ?? '');
        $content = trim($input['content'] ?? '');

        // Validierung
        if ($categoryId <= 0) {
            throw new Exception('Keine Kategorie ausgewählt');
        }
        if (strlen($title) < 3) {
            throw new Exception('Titel muss mindestens 3 Zeichen haben');
        }
        if (strlen($content) < 10) {
            throw new Exception('Inhalt muss mindestens 10 Zeichen haben');
        }

        // Berechtigung prüfen
        if (!$repo->canCreateTopicInCategory($categoryId, $userRole)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Keine Berechtigung zum Erstellen von Topics in dieser Kategorie']);
            exit;
        }

        // Anmeldung prüfen (falls nicht public)
        $category = $repo->getCategoryById($categoryId);
        if (($category['create_permission'] ?? 'registered') !== 'public' && !Security::isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Anmeldung erforderlich']);
            exit;
        }

        $userId = $_SESSION['lmo26_user_id'] ?? null;
        $topicId = $repo->createTopic($categoryId, $userId, $title, $content);

        echo json_encode([
            'success' => true,
            'topic_id' => $topicId,
            'message' => 'Topic erstellt',
            'csrf_token' => Security::getCsrfToken()
        ]);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ]);
}
