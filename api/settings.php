<?php
/**
 * Einstellungen (nur Rolle admin)
 *
 *   GET  ?action=get                        – aktuelle Werte + csrf_token
 *   POST action=save, contact_notify_1, contact_notify_2 (CSRF)
 *   POST action=test_contact_mail (CSRF)    – Test-Mail an die gespeicherten Kontaktpersonen
 *   POST action=briefing_save, recipients[], send_empty (CSRF) – Empfaenger des taeglichen Briefings
 *   POST action=briefing_preview (CSRF)     – heutiger Inhalt je Empfaenger (ohne Versand)
 *   POST action=briefing_test (CSRF)        – Briefing jetzt als [TEST] an alle senden
 *   POST action=features_save, calculator, tipp, comments (off|open|approval), live (CSRF) – zuschaltbare Funktionen
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/SettingsRepository.php';
require_once __DIR__ . '/../lib/ContactService.php';
require_once __DIR__ . '/../lib/BriefingService.php';

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
        $cm = (string)$settings->get('feature_comments');
        $data['features'] = ['calculator' => $settings->get('feature_calculator') === '1', 'tipp' => $settings->get('feature_tipp') === '1',
            'comments' => in_array($cm, ['open', 'approval'], true) ? $cm : 'off', 'live' => $settings->get('feature_live') === '1'];
        $briefing = new BriefingService();
        $data['briefing'] = $briefing->getConfig() + [
            'sections' => BriefingService::SECTIONS,
            'team' => $briefing->teamSuggestions(),
            'max' => BriefingService::MAX_RECIPIENTS,
        ];
        stJson(['success' => true, 'data' => $data, 'csrf_token' => Security::getCsrfToken()]);
    }

    if ($method === 'POST') {
        Security::requireCsrf();
        $in = json_decode(file_get_contents('php://input'), true);
        if (!is_array($in)) {
            stJson(['success' => false, 'error' => 'Ungültige Anfrage'], 400);
        }
        $action = $in['action'] ?? '';

        if ($action === 'features_save') {
            $settings->set('feature_calculator', !empty($in['calculator']) ? '1' : '0');
            $settings->set('feature_tipp', !empty($in['tipp']) ? '1' : '0');
            $settings->set('feature_comments', in_array($in['comments'] ?? '', ['open', 'approval'], true) ? $in['comments'] : 'off');
            $settings->set('feature_live', !empty($in['live']) ? '1' : '0');
            @unlink(__DIR__ . '/../data/cache/theme.json'); // Seiten lesen die Schalter ueber api/theme.php
            stJson(['success' => true]);
        }

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

        if ($action === 'briefing_save') {
            try {
                $list = (new BriefingService())->saveConfig((array)($in['recipients'] ?? []), !empty($in['send_empty']));
            } catch (InvalidArgumentException $e) {
                stJson(['success' => false, 'error' => $e->getMessage()], 400);
            }
            stJson(['success' => true, 'data' => $list]);
        }

        if ($action === 'briefing_preview') {
            $b = new BriefingService();
            $cfg = $b->getConfig();
            $data = $b->collect();
            $out = [];
            foreach ($cfg['recipients'] as $r) {
                $mail = $b->render($r, $data, true);
                $out[] = ['email' => $r['email'], 'name' => $r['name'], 'mail' => $mail,
                          'would_send' => $mail !== null && ($mail['total'] > 0 || $cfg['send_empty'])];
            }
            stJson(['success' => true, 'data' => $out]);
        }

        if ($action === 'briefing_test') {
            stJson(['success' => true, 'data' => (new BriefingService())->sendAll(true, true)]);
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
