<?php
/**
 * Vereinswappen verwalten (nur Admin)
 *
 *   GET                                            – alle Vereine mit Wappen-Status
 *   POST multipart action=upload, name, file       – Wappen hochladen/ersetzen
 *   POST JSON action=copy, name, source            – Wappen eines anderen Vereins übernehmen
 *   POST JSON action=remove, name                  – Wappen entfernen (nach img/teams/_alt/)
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/CrestService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function crJson(array $data, int $code = 200): void
{
    http_response_code($code);
    $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Security::initSession();
if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') {
    crJson(['success' => false, 'error' => 'Nur für Administratoren.'], 403);
}

try {
    $svc = new CrestService();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        crJson(['success' => true, 'data' => $svc->listAll()]);
    }
    if ($method !== 'POST') {
        crJson(['success' => false, 'error' => 'Methode nicht erlaubt'], 405);
    }

    Security::requireCsrf();
    $in = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?: []);
    $action = $in['action'] ?? '';
    $name = trim((string)($in['name'] ?? ''));

    switch ($action) {
        case 'upload':
            $f = $_FILES['file'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                crJson(['success' => false, 'error' => 'Keine Datei empfangen (max. 3 MB).'], 400);
            }
            crJson(['success' => true, 'data' => $svc->upload($name, $f['tmp_name'], (int)$f['size']), 'message' => 'Wappen gespeichert.']);
        case 'copy':
            crJson(['success' => true, 'data' => $svc->copyFrom($name, trim((string)($in['source'] ?? ''))), 'message' => 'Wappen übernommen.']);
        case 'remove':
            crJson(['success' => true, 'data' => $svc->remove($name), 'message' => 'Wappen entfernt.']);
        default:
            crJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }
} catch (InvalidArgumentException $e) {
    crJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('crests.php: ' . $e->getMessage());
    crJson(['success' => false, 'error' => 'Fehler beim Speichern des Wappens.'], 500);
}
