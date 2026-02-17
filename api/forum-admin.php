<?php
/**
 * Forum Admin API
 * Kategorien verwalten, Topics moderieren
 * Nur für Admins
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

// Admin-Check
if (!Security::isLoggedIn() || ($_SESSION['lmo26_user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin-Berechtigung erforderlich']);
    exit;
}

$repo = new ForumRepository();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF prüfen bei POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!Security::verifyCsrfToken($input['csrf_token'] ?? '')) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Ungültiges CSRF-Token']);
            exit;
        }
    }

    $action = $input['action'] ?? $_GET['action'] ?? '';

    switch ($action) {
        // ==================== KATEGORIEN ====================

        case 'create_category':
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $sortOrder = intval($input['sort_order'] ?? 0);

            if (strlen($name) < 2) {
                throw new Exception('Name muss mindestens 2 Zeichen haben');
            }

            $id = $repo->createCategory($name, $description, $sortOrder);

            echo json_encode([
                'success' => true,
                'id' => $id,
                'message' => 'Kategorie erstellt',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'update_category':
            $id = intval($input['id'] ?? 0);
            $name = trim($input['name'] ?? '');
            $description = trim($input['description'] ?? '');
            $sortOrder = intval($input['sort_order'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Keine Kategorie angegeben');
            }
            if (strlen($name) < 2) {
                throw new Exception('Name muss mindestens 2 Zeichen haben');
            }

            $repo->updateCategory($id, $name, $description, $sortOrder);

            echo json_encode([
                'success' => true,
                'message' => 'Kategorie aktualisiert',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'delete_category':
            $id = intval($input['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Keine Kategorie angegeben');
            }

            // Prüfen ob Topics existieren
            $topicCount = $repo->getTopicCountByCategory($id);
            if ($topicCount > 0) {
                throw new Exception("Kategorie enthält noch $topicCount Topics. Bitte erst löschen.");
            }

            $repo->deleteCategory($id);

            echo json_encode([
                'success' => true,
                'message' => 'Kategorie gelöscht',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'update_permissions':
            $id = intval($input['id'] ?? 0);
            $viewPerm = $input['view_permission'] ?? 'public';
            $replyPerm = $input['reply_permission'] ?? 'registered';
            $createPerm = $input['create_permission'] ?? 'registered';
            $showOnHomepage = (bool)($input['show_on_homepage'] ?? false);
            $isArchived = (bool)($input['is_archived'] ?? false);

            if ($id <= 0) {
                throw new Exception('Keine Kategorie angegeben');
            }

            // Gültige Berechtigungsstufen
            $validPerms = ['public', 'registered', 'editor', 'admin', 'none'];
            if (!in_array($viewPerm, $validPerms) ||
                !in_array($replyPerm, $validPerms) ||
                !in_array($createPerm, $validPerms)) {
                throw new Exception('Ungültige Berechtigungsstufe');
            }

            $repo->updateCategoryPermissions($id, $viewPerm, $replyPerm, $createPerm, $showOnHomepage, $isArchived);

            echo json_encode([
                'success' => true,
                'message' => 'Berechtigungen aktualisiert',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        // ==================== BENUTZER ====================

        case 'users':
            $limit = isset($_GET['limit']) ? min(100, intval($_GET['limit'])) : 50;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $search = $_GET['search'] ?? '';

            $users = $repo->getUsersWithRoles($limit, $offset, $search);
            $totalUsers = $repo->getUserCount($search);

            echo json_encode([
                'success' => true,
                'users' => $users,
                'pagination' => [
                    'total' => $totalUsers,
                    'limit' => $limit,
                    'offset' => $offset,
                    'pages' => ceil($totalUsers / $limit)
                ],
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'update_user_role':
            $userId = intval($input['user_id'] ?? 0);
            $role = $input['role'] ?? 'user';

            if ($userId <= 0) {
                throw new Exception('Kein Benutzer angegeben');
            }

            // Gültige Rollen
            $validRoles = ['user', 'editor', 'admin'];
            if (!in_array($role, $validRoles)) {
                throw new Exception('Ungültige Rolle');
            }

            // Sich selbst nicht degradieren
            $currentUserId = $_SESSION['lmo26_user_id'] ?? 0;
            if ($userId == $currentUserId && $role !== 'admin') {
                throw new Exception('Sie können Ihre eigene Admin-Rolle nicht entfernen');
            }

            $repo->updateUserRole($userId, $role);

            echo json_encode([
                'success' => true,
                'message' => 'Benutzerrolle aktualisiert',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        // ==================== TOPICS ====================

        case 'lock_topic':
            $id = intval($input['id'] ?? 0);
            $locked = (bool)($input['locked'] ?? true);

            if ($id <= 0) {
                throw new Exception('Kein Topic angegeben');
            }

            $repo->lockTopic($id, $locked);

            echo json_encode([
                'success' => true,
                'message' => $locked ? 'Topic gesperrt' : 'Topic entsperrt',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'sticky_topic':
            $id = intval($input['id'] ?? 0);
            $sticky = (bool)($input['sticky'] ?? true);

            if ($id <= 0) {
                throw new Exception('Kein Topic angegeben');
            }

            $repo->stickyTopic($id, $sticky);

            echo json_encode([
                'success' => true,
                'message' => $sticky ? 'Topic angepinnt' : 'Topic nicht mehr angepinnt',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        case 'delete_topic':
            $id = intval($input['id'] ?? 0);

            if ($id <= 0) {
                throw new Exception('Kein Topic angegeben');
            }

            $repo->deleteTopic($id);

            echo json_encode([
                'success' => true,
                'message' => 'Topic gelöscht',
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        // ==================== STATISTIKEN ====================

        case 'stats':
            $stats = $repo->getStats();
            $categories = $repo->getCategories();

            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'categories' => $categories,
                'csrf_token' => Security::getCsrfToken()
            ]);
            break;

        default:
            // Default: Stats zurückgeben
            $stats = $repo->getStats();
            echo json_encode([
                'success' => true,
                'stats' => $stats,
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
