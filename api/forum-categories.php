<?php
/**
 * Forum Categories API
 * GET: Liste aller Kategorien mit Statistiken (gefiltert nach Berechtigung)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/ForumRepository.php';
require_once __DIR__ . '/../lib/Security.php';

Security::initSession();

try {
    $repo = new ForumRepository();

    // Benutzerrolle ermitteln
    $userId = $_SESSION['lmo26_user_id'] ?? null;
    $userRole = $userId ? $repo->getUserRole($userId) : null;

    // Für Admin: Optional alle Kategorien anzeigen
    $includeHidden = isset($_GET['all']) && $userRole === 'admin';

    // Kategorien laden (gefiltert nach Berechtigung)
    $categories = $repo->getCategories($userRole, $includeHidden);

    // Berechtigungsinfo pro Kategorie hinzufügen
    foreach ($categories as &$cat) {
        $cat['can_view'] = $repo->hasPermission($cat['view_permission'] ?? 'public', $userRole);
        $cat['can_reply'] = $repo->hasPermission($cat['reply_permission'] ?? 'registered', $userRole);
        $cat['can_create'] = $repo->hasPermission($cat['create_permission'] ?? 'registered', $userRole);
    }

    $stats = $repo->getStats();

    echo json_encode([
        'success' => true,
        'categories' => $categories,
        'stats' => $stats,
        'user' => [
            'id' => $userId,
            'role' => $userRole,
            'logged_in' => $userId !== null
        ],
        'csrf_token' => Security::getCsrfToken()
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ]);
}
