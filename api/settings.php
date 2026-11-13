<?php
/**
 * Einstellungen (nur Rolle admin)
 *
 *   GET  ?action=get                        – aktuelle Werte + csrf_token
 *   POST action=save, contact_notify_1, contact_notify_2 (CSRF)
 *   POST action=test_contact_mail (CSRF)    – Test-Mail an die gespeicherten Kontaktpersonen
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/SettingsRepository.php';
require_once __DIR__ . '/../lib/ContactService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function stJson(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    Security::initSession();
    if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') {
        stJson(['success' => false, 'error' => 'Nur für Administratoren'], 403);
    }
    $settings = new SettingsRepository();
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET' && ($_GET['action'] ?? '') === 'get') {
        $data = [];
        foreach (ContactService::NOTIFY_KEYS as $k) {
            $data[$k] = $settings->get($k);
        }
        stJson(['success' => true, 'data' => $data, 'csrf_token' => Security::getCsrfToken()]);
    }

    if ($method === 'POST') {
        Security::requireCsrf();
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) {
            stJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
        }
        $action = $in['action'] ?? '';

        if ($action === 'save') {
            $values = [];
            foreach (ContactService::NOTIFY_KEYS as $i => $k) {
                $v = trim((string)($in[$k] ?? ''));
                if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 200)) {
                    stJson(['success' => false, 'error' => 'Kontaktperson ' . ($i + 1) . ': keine gültige E-Mail-Adresse.'], 400);
                }
                $values[$k] = $v;
            }
            foreach ($values as $k => $v) {
                $settings->set($k, $v);
            }
            stJson(['success' => true, 'data' => $values]);
        }

        if ($action === 'test_contact_mail') {
            $result = ContactService::sendTestNotification();
            if (!$result) {
                stJson(['success' => false, 'error' => 'Keine Kontaktperson eingetragen.'], 400);
            }
            stJson(['success' => true, 'data' => $result]);
        }
    }

    stJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
} catch (Throwable $e) {
    error_log('settings.php: ' . $e->getMessage());
    stJson(['success' => false, 'error' => 'Serverfehler. Bitte später erneut versuchen.'], 500);
}
