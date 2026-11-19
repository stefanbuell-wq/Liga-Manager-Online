<?php
/**
 * Live-Ticker (zuschaltbar: Admin -> Einstellungen -> Zusatzfunktionen)
 *
 *   GET  ?action=active                 – laufende Ticker (fuer LIVE-Streifen und Startseite)
 *   GET  ?action=today                  – alle Spiele im Ticker-Zeitfenster
 *   GET  ?action=match&id=<match_id>    – Ticker eines Spiels
 *   POST {action: present, id, on}      – "Ich bin bei diesem Spiel" (Mitglieder, CSRF)
 *   POST {action: event, id, type, minute?, team?, player?, text?, score_home?, score_guest?}
 *   POST {action: delete, event}        – eigene Meldung (5 min) oder Redaktion
 * Redaktion (editor/admin):
 *   GET  ?action=admin
 *   POST {action: restore, event} | {action: adopt, id} | {action: block|unblock, user}
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LiveService.php';

header('Content-Type: application/json; charset=utf-8');

function lJson(array $data, int $code = 200, bool $csrf = true): void
{
    http_response_code($code);
    if ($csrf) $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string)($in['action'] ?? '');

    // Oeffentliche Uebersicht ohne Sitzung (wird von jeder Seite alle 30 s abgefragt)
    if ($action === 'active' && $method === 'GET') {
        if (!LiveService::enabled()) { header('Cache-Control: public, max-age=300'); lJson(['success' => false, 'error' => 'aus'], 404, false); }
        header('Cache-Control: public, max-age=15');
        lJson(['success' => true, 'data' => (new LiveService())->current(true)], 200, false);
    }

    header('Cache-Control: no-store');
    Security::initSession();
    $svc = new LiveService();
    $pdo = LmoDatabase::getInstance();
    $uid = Security::isMember() ? (int)($_SESSION['lmo26_user_id'] ?? 0) : 0;
    $mod = Security::isLoggedIn();

    if (in_array($action, ['admin', 'restore', 'adopt', 'block', 'unblock'], true)) {
        if (!$mod) lJson(['success' => false, 'error' => 'Nur für die Redaktion.'], 403);
        if ($action === 'admin') {
            lJson(['success' => true, 'data' => ['enabled' => LiveService::enabled(), 'matches' => $svc->adminOverview(),
                'current' => $svc->current(false), 'blocked' => CommentService::blockedUsers($pdo, 'live')]]);
        }
        if ($method !== 'POST') lJson(['success' => false, 'error' => 'Methode nicht erlaubt'], 405);
        Security::requireCsrf();
        switch ($action) {
            case 'restore':
                $svc->restoreEvent((int)($in['event'] ?? 0));
                lJson(['success' => true, 'message' => 'Meldung wiederhergestellt.']);
            case 'adopt':
                $s = $svc->adoptResult((int)($in['id'] ?? 0));
                lJson(['success' => true, 'message' => 'Ergebnis ' . $s[0] . ':' . $s[1] . ' übernommen.']);
            case 'block':
            case 'unblock':
                $target = (int)($in['user'] ?? 0);
                if ($target <= 0) lJson(['success' => false, 'error' => 'Nutzer fehlt.'], 400);
                CommentService::setBlocked($pdo, $target, 'live', $action === 'block', $uid ?: null);
                lJson(['success' => true, 'message' => $action === 'block' ? 'Nutzer darf nicht mehr tickern.' : 'Sperre aufgehoben.']);
        }
    }

    if (!LiveService::enabled() && !$mod) lJson(['success' => false, 'error' => 'Der Live-Ticker ist nicht aktiviert.'], 404);

    switch ($action) {
        case 'today':
            lJson(['success' => true, 'data' => $svc->current(false), 'logged_in' => (bool)$uid]);
        case 'match':
            $d = $svc->match((int)($in['id'] ?? 0), $uid ?: null, $mod);
            if (!$d) lJson(['success' => false, 'error' => 'Spiel nicht gefunden.'], 404);
            $blocked = $uid && CommentService::isBlocked($pdo, $uid, 'live');
            $d += ['logged_in' => (bool)$uid, 'moderator' => $mod, 'blocked' => $blocked, 'types' => LiveService::TYPES, 'enabled' => LiveService::enabled()];
            lJson(['success' => true, 'data' => $d]);
    }

    if ($method !== 'POST') lJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
    if (!$uid) lJson(['success' => false, 'error' => 'Bitte anmelden.'], 401);
    Security::requireCsrf();
    switch ($action) {
        case 'present':
            $svc->setPresent((int)($in['id'] ?? 0), $uid, !empty($in['on']));
            lJson(['success' => true, 'message' => !empty($in['on']) ? 'Du bist als Ticker dabei – los geht’s!' : 'Abgemeldet.']);
        case 'event':
            $id = $svc->addEvent((int)($in['id'] ?? 0), $uid, $in);
            lJson(['success' => true, 'data' => ['id' => $id], 'message' => 'Gemeldet.']);
        case 'delete':
            $svc->deleteEvent((int)($in['event'] ?? 0), $uid, $mod);
            lJson(['success' => true, 'message' => 'Meldung gelöscht.']);
    }
    lJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (InvalidArgumentException $e) {
    lJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('live.php: ' . $e->getMessage());
    lJson(['success' => false, 'error' => 'Interner Fehler.'], 500);
}
