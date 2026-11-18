<?php
/**
 * Tippspiel (zuschaltbar: Admin -> Einstellungen)
 *
 *   GET  ?action=rounds                               – aktive Tipprunden
 *   GET  ?action=matchday&round=<id>[&md=<n>]         – Spiele eines Spieltags (+ eigene Tipps, wenn angemeldet)
 *   GET  ?action=ranking&round=<id>[&md=<n>]          – Rangliste (Saison oder Spieltag)
 *   GET  ?action=match_tips&round=<id>&match=<id>     – Tipps aller zu einem Spiel (erst nach Anstoß)
 *   POST JSON {action: save, round, tips: [{match_id, home, guest}]}   – Mitglieder, CSRF
 * Admin:
 *   GET  ?action=admin                                – alle Tipprunden
 *   POST JSON {action: create_round, league_file, name?, start_round?}
 *   POST JSON {action: set_active, round, active}
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/TippService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function tJson(array $data, int $code = 200): void
{
    http_response_code($code);
    $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Security::initSession();
try {
    $svc = new TippService();
    $method = $_SERVER['REQUEST_METHOD'];
    $in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = $in['action'] ?? '';
    $isAdmin = Security::isLoggedIn() && Security::getUserRole() === 'admin';
    $uid = Security::isMember() ? (int)($_SESSION['lmo26_user_id'] ?? 0) : 0;

    if (in_array($action, ['admin', 'create_round', 'set_active'], true)) {
        if (!$isAdmin) tJson(['success' => false, 'error' => 'Nur für Administratoren.'], 403);
        if ($action === 'admin') tJson(['success' => true, 'data' => ['rounds' => $svc->rounds(false), 'enabled' => TippService::enabled(), 'leagues' => $svc->leagues()]]);
        Security::requireCsrf();
        if ($action === 'create_round') {
            $id = $svc->createRound((string)($in['league_file'] ?? ''), (string)($in['name'] ?? ''), (int)($in['start_round'] ?? 1));
            tJson(['success' => true, 'data' => ['id' => $id], 'message' => 'Tipprunde angelegt.']);
        }
        $svc->setActive((int)($in['round'] ?? 0), !empty($in['active']));
        tJson(['success' => true, 'message' => 'Gespeichert.']);
    }

    if (!TippService::enabled() && !$isAdmin) tJson(['success' => false, 'error' => 'Das Tippspiel ist nicht aktiviert.'], 404);

    if ($action === 'rounds') {
        tJson(['success' => true, 'data' => $svc->rounds(true), 'user' => $uid ? ['logged_in' => true] : ['logged_in' => false]]);
    }

    $round = $svc->round((int)($in['round'] ?? 0));
    if (!$round || (!$round['active'] && !$isAdmin)) tJson(['success' => false, 'error' => 'Tipprunde nicht gefunden.'], 404);

    switch ($action) {
        case 'matchday':
            $md = (int)($in['md'] ?? 0) ?: $svc->currentMatchday($round);
            $md = max((int)$round['start_round'], min($md, max(1, (int)$round['rounds'])));
            tJson(['success' => true, 'data' => [
                'round' => ['id' => (int)$round['id'], 'name' => $round['name'], 'league' => $round['league_name'], 'start_round' => (int)$round['start_round'], 'rounds' => (int)$round['rounds']],
                'md' => $md, 'matches' => $svc->matchday($round, $md, $uid ?: null), 'logged_in' => (bool)$uid,
            ]]);
        case 'ranking':
            $md = isset($in['md']) && $in['md'] !== '' ? (int)$in['md'] : null;
            $rows = array_map(function ($r) use ($uid) { $r['me'] = $uid && $r['uid'] === $uid; unset($r['uid']); return $r; }, $svc->ranking($round, $md));
            tJson(['success' => true, 'data' => $rows]);
        case 'match_tips':
            tJson(['success' => true, 'data' => $svc->matchTips($round, (int)($in['match'] ?? 0))]);
        case 'save':
            if ($method !== 'POST') tJson(['success' => false, 'error' => 'Methode nicht erlaubt'], 405);
            if (!$uid) tJson(['success' => false, 'error' => 'Bitte anmelden, um zu tippen.'], 401);
            Security::requireCsrf();
            if (!Security::checkRateLimit('tipp:' . $uid, 120)['allowed']) tJson(['success' => false, 'error' => 'Zu viele Speichervorgänge. Bitte später erneut versuchen.'], 429);
            Security::recordFailedAttempt('tipp:' . $uid);
            $r = $svc->save($round, $uid, is_array($in['tips'] ?? null) ? $in['tips'] : []);
            $msg = $r['saved'] . ' Tipp' . ($r['saved'] === 1 ? '' : 's') . ' gespeichert.';
            if ($r['rejected']) $msg .= ' ' . count($r['rejected']) . ' nicht gespeichert (Anstoß vorbei oder ungültig).';
            tJson(['success' => true, 'data' => $r, 'message' => $msg]);
    }
    tJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (InvalidArgumentException $e) {
    tJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('tipp.php: ' . $e->getMessage());
    tJson(['success' => false, 'error' => 'Interner Fehler.'], 500);
}
