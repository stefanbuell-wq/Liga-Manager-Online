<?php
/**
 * Profilbild (Avatar) des angemeldeten Kontos
 *
 *   GET  ?action=crests                      – Wappen zur Auswahl (oeffentlich, gecacht)
 *   GET                                      – eigenes Profilbild (angemeldet)
 *   POST multipart action=upload, file=bild  – Bild hochladen (CSRF per Feld csrf_token oder Header)
 *   POST JSON action=crest, key=<Wappen>     – Vereinswappen waehlen
 *   POST JSON action=remove                  – Profilbild entfernen
 *   POST JSON action=admin_remove, user_id   – (nur Admin) Bild eines Kontos entfernen
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/AvatarService.php';

header('Content-Type: application/json; charset=utf-8');

function avJson(array $data, int $code = 200): void
{
    http_response_code($code);
    $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Security::initSession();

try {
    $svc = new AvatarService();
    $method = $_SERVER['REQUEST_METHOD'];
    $in = $method === 'POST' ? ($_POST ?: (json_decode(file_get_contents('php://input'), true) ?: [])) : $_GET;
    $action = $in['action'] ?? '';

    if ($method === 'GET' && $action === 'crests') {
        header('Cache-Control: public, max-age=3600');
        avJson(['success' => true, 'data' => $svc->crestChoices()]);
    }

    if (!Security::isMember()) {
        avJson(['success' => false, 'error' => 'Bitte anmelden.'], 401);
    }
    $uid = (int)$_SESSION['lmo26_user_id'];

    if ($method === 'GET') {
        avJson(['success' => true, 'data' => ['avatar' => $svc->get($uid)]]);
    }

    Security::requireCsrf();

    // Schutz gegen Upload-Fluten: 20 Aenderungen je Stunde und Konto
    $rl = 'avatar:' . $uid;
    if (!Security::checkRateLimit($rl, 20)['allowed']) {
        avJson(['success' => false, 'error' => 'Zu viele Änderungen. Bitte später erneut versuchen.'], 429);
    }

    switch ($action) {
        case 'upload':
            Security::recordFailedAttempt($rl);
            avJson(['success' => true, 'data' => ['avatar' => $svc->upload($uid, $_FILES['file'] ?? null)], 'message' => 'Profilbild gespeichert.']);

        case 'crest':
            Security::recordFailedAttempt($rl);
            avJson(['success' => true, 'data' => ['avatar' => $svc->setCrest($uid, (string)($in['key'] ?? ''))], 'message' => 'Wappen als Profilbild gespeichert.']);

        case 'remove':
            $svc->remove($uid);
            avJson(['success' => true, 'data' => ['avatar' => null], 'message' => 'Profilbild entfernt.']);

        case 'admin_remove':
            if (Security::getUserRole() !== 'admin') avJson(['success' => false, 'error' => 'Nur für Administratoren.'], 403);
            $svc->remove((int)($in['user_id'] ?? 0));
            avJson(['success' => true, 'message' => 'Profilbild entfernt.']);

        default:
            avJson(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
    }
} catch (InvalidArgumentException $e) {
    avJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('avatar.php: ' . $e->getMessage());
    avJson(['success' => false, 'error' => 'Serverfehler'], 500);
}
