<?php
/**
 * Kontaktformular
 *
 * Oeffentlich:
 *   POST (JSON) action=send – Anfrage absenden (Honeypot, Mindest-Ausfuellzeit, 5 pro Stunde je IP)
 *
 * Verwaltung (nur Rolle admin):
 *   GET  ?action=list
 *   GET  ?action=count             – Anzahl neuer Anfragen
 *   POST action=update, id, status, note (CSRF)
 *   POST action=delete, id (CSRF)
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/ContactService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ktJson(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $svc = new ContactService();

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $in = json_decode($raw, true);
        if (!is_array($in)) {
            ktJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
        }
        $action = $in['action'] ?? '';

        /* ---------- Oeffentlich: absenden ---------- */
        if ($action === 'send') {
            // JSON erzwingen: einfache fremde HTML-Formulare koennen kein application/json senden
            if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
                ktJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
            }
            // Honeypot und Ausfuellzeit: Bots erhalten scheinbaren Erfolg
            $elapsed = (int)($in['elapsed'] ?? 0);
            if (!empty($in['website']) || $elapsed < 3000) {
                ktJson(['success' => true]);
            }

            $key = 'kontakt:' . ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); // nicht X-Forwarded-For (faelschbar)
            $limit = Security::checkRateLimit($key);
            if (!$limit['allowed']) {
                ktJson(['success' => false, 'error' => 'Zu viele Anfragen. Bitte später erneut versuchen.'], 429);
            }

            $svc->create($in);
            Security::recordFailedAttempt($key); // zaehlt Absendungen: max. 5 pro Stunde
            ktJson(['success' => true]);
        }

        /* ---------- Verwaltung ---------- */
        Security::initSession();
        if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') {
            ktJson(['success' => false, 'error' => 'Nur für Administratoren'], 403);
        }
        Security::requireCsrf();

        switch ($action) {
            case 'update':
                $svc->update((int)($in['id'] ?? 0), (string)($in['status'] ?? ''), $in['note'] ?? null);
                ktJson(['success' => true]);
            case 'delete':
                $svc->delete((int)($in['id'] ?? 0));
                ktJson(['success' => true]);
            default:
                ktJson(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
        }
    }

    if ($method === 'GET') {
        Security::initSession();
        if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') {
            ktJson(['success' => false, 'error' => 'Nur für Administratoren'], 403);
        }
        $action = $_GET['action'] ?? '';
        if ($action === 'count') {
            ktJson(['success' => true, 'data' => ['open' => $svc->countOpen()]]);
        }
        if ($action === 'list') {
            ktJson([
                'success' => true,
                'data' => [
                    'requests' => $svc->listAll(),
                    'topics' => ContactService::TOPICS,
                    'interests' => ContactService::INTERESTS,
                ],
                'csrf_token' => Security::getCsrfToken(),
            ]);
        }
    }

    ktJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
} catch (InvalidArgumentException $e) {
    ktJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('kontakt.php: ' . $e->getMessage());
    ktJson(['success' => false, 'error' => 'Serverfehler. Bitte später erneut versuchen.'], 500);
}
