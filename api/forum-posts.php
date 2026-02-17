<?php
/**
 * Forum Posts API
 * POST: Neuen Post erstellen (Antwort auf Topic)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib/ForumRepository.php';
require_once __DIR__ . '/../lib/Security.php';

Security::initSession();

$repo = new ForumRepository();

// Benutzerrolle ermitteln
$userId = $_SESSION['lmo26_user_id'] ?? null;
$userRole = $userId ? $repo->getUserRole($userId) : null;

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        // CSRF prüfen
        if (!Security::verifyCsrfToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ungültiges CSRF-Token']);
            exit;
        }

        $action = $input['action'] ?? 'create';

        if ($action === 'create') {
            $topicId = intval($input['topic_id'] ?? 0);
            $content = trim($input['content'] ?? '');

            if ($topicId <= 0) {
                throw new Exception('Kein Topic angegeben');
            }
            if (strlen($content) < 3) {
                throw new Exception('Inhalt muss mindestens 3 Zeichen haben');
            }

            // Topic laden für Kategorie-Check
            $topic = $repo->getTopicById($topicId);
            if (!$topic) {
                throw new Exception('Topic nicht gefunden');
            }

            // Topic gesperrt?
            if ($topic['is_locked'] && $userRole !== 'admin') {
                throw new Exception('Dieses Thema ist gesperrt');
            }

            // Berechtigung prüfen
            if (!$repo->canReplyInCategory($topic['category_id'], $userRole)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Keine Berechtigung zum Antworten in dieser Kategorie']);
                exit;
            }

            // Anmeldung prüfen (falls nicht public)
            $category = $repo->getCategoryById($topic['category_id']);
            if (($category['reply_permission'] ?? 'registered') !== 'public' && !Security::isLoggedIn()) {
                http_response_code(401);
                echo json_encode(['success' => false, 'error' => 'Anmeldung erforderlich']);
                exit;
            }

            $postId = $repo->createPost($topicId, $userId, $content);

            echo json_encode([
                'success' => true,
                'post_id' => $postId,
                'message' => 'Antwort gepostet',
                'csrf_token' => Security::getCsrfToken()
            ]);

        } elseif ($action === 'edit') {
            $postId = intval($input['post_id'] ?? 0);
            $content = trim($input['content'] ?? '');

            if ($postId <= 0) {
                throw new Exception('Kein Post angegeben');
            }

            $post = $repo->getPostById($postId);
            if (!$post) {
                throw new Exception('Post nicht gefunden');
            }

            // Nur eigene Posts, Editor oder Admin darf bearbeiten
            $canEdit = ($post['user_id'] == $userId) ||
                       ($userRole === 'editor') ||
                       ($userRole === 'admin');

            if (!$canEdit) {
                throw new Exception('Keine Berechtigung zum Bearbeiten');
            }

            $repo->updatePost($postId, $content, $userId);

            echo json_encode([
                'success' => true,
                'message' => 'Post bearbeitet',
                'csrf_token' => Security::getCsrfToken()
            ]);

        } elseif ($action === 'delete') {
            $postId = intval($input['post_id'] ?? 0);

            if ($postId <= 0) {
                throw new Exception('Kein Post angegeben');
            }

            $post = $repo->getPostById($postId);
            if (!$post) {
                throw new Exception('Post nicht gefunden');
            }

            // Nur eigene Posts (innerhalb 1h), Editor oder Admin darf löschen
            $isOwnPost = $post['user_id'] == $userId;
            $isRecent = strtotime($post['created_at']) > (time() - 3600); // 1 Stunde

            $canDelete = ($isOwnPost && $isRecent) ||
                         ($userRole === 'editor') ||
                         ($userRole === 'admin');

            if (!$canDelete) {
                throw new Exception('Keine Berechtigung zum Löschen (eigene Posts nur innerhalb 1 Stunde)');
            }

            $repo->deletePost($postId);

            echo json_encode([
                'success' => true,
                'message' => 'Post gelöscht',
                'csrf_token' => Security::getCsrfToken()
            ]);

        } else {
            throw new Exception('Unbekannte Aktion');
        }

    } else {
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
