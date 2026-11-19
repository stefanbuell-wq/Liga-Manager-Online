<?php
/**
 * Kommentare unter Artikeln
 *
 *   GET  ?news=<id>                                  – Kommentare + Modus fuer einen Artikel
 *   POST {action: add, news, content}                – Mitglieder (CSRF)
 *   POST {action: delete, id}                        – eigener Kommentar oder Redaktion
 *   POST {action: report, id, reason?}               – Mitglieder
 * Redaktion (editor/admin):
 *   GET  ?action=admin&filter=pending|reported|hidden|all
 *   POST {action: approve|hide, id}
 *   POST {action: article_mode, news, mode: open|closed|off}
 *   POST {action: block|unblock, user}
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/CommentService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cJson(array $data, int $code = 200): void
{
    http_response_code($code);
    $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Security::initSession();
try {
    $svc = new CommentService();
    $pdo = LmoDatabase::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    $in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $action = (string)($in['action'] ?? ($method === 'GET' ? 'list' : ''));
    $uid = Security::isMember() ? (int)($_SESSION['lmo26_user_id'] ?? 0) : 0;
    $mod = Security::isLoggedIn(); // Redaktion (editor/admin)
    $global = CommentService::globalMode();

    // ---------- Redaktion ----------
    if (in_array($action, ['admin', 'approve', 'hide', 'article_mode', 'block', 'unblock'], true)) {
        if (!$mod) cJson(['success' => false, 'error' => 'Nur für die Redaktion.'], 403);
        if ($action === 'admin') {
            $filter = in_array($in['filter'] ?? '', ['pending', 'reported', 'hidden', 'all'], true) ? $in['filter'] : 'pending';
            cJson(['success' => true, 'data' => [
                'mode' => $global, 'counts' => $svc->counts(), 'comments' => $svc->moderationList($filter),
                'articles' => $svc->articleModes(), 'blocked' => CommentService::blockedUsers($pdo, 'comments'),
            ]]);
        }
        if ($method !== 'POST') cJson(['success' => false, 'error' => 'Methode nicht erlaubt'], 405);
        Security::requireCsrf();
        switch ($action) {
            case 'approve':
            case 'hide':
                $svc->setStatus((int)($in['id'] ?? 0), $action === 'approve' ? 'visible' : 'hidden', $uid);
                cJson(['success' => true, 'message' => $action === 'approve' ? 'Freigegeben.' : 'Ausgeblendet.']);
            case 'article_mode':
                $svc->setArticleMode((int)($in['news'] ?? 0), (string)($in['mode'] ?? ''), $uid ?: null);
                cJson(['success' => true, 'message' => 'Gespeichert.']);
            case 'block':
            case 'unblock':
                $target = (int)($in['user'] ?? 0);
                if ($target <= 0) cJson(['success' => false, 'error' => 'Nutzer fehlt.'], 400);
                CommentService::setBlocked($pdo, $target, 'comments', $action === 'block', $uid ?: null);
                cJson(['success' => true, 'message' => $action === 'block' ? 'Nutzer darf nicht mehr kommentieren.' : 'Sperre aufgehoben.']);
        }
    }

    // ---------- Oeffentlich ----------
    if ($global === 'off' && !$mod) cJson(['success' => false, 'error' => 'Kommentare sind ausgeschaltet.'], 404);

    if ($action === 'list') {
        $newsId = (int)($in['news'] ?? 0);
        $mode = $svc->articleMode($newsId);
        $comments = ($mode === 'off' && !$mod) ? [] : $svc->forArticle($newsId, $uid ?: null, $mod);
        $blocked = $uid && CommentService::isBlocked($pdo, $uid, 'comments');
        cJson(['success' => true, 'data' => [
            'global' => $global, 'mode' => $mode, 'approval' => $global === 'approval',
            'comments' => $comments, 'logged_in' => (bool)$uid, 'moderator' => $mod,
            'can_post' => $uid && !$blocked && $global !== 'off' && $mode === 'open',
            'blocked' => $blocked, 'max_len' => CommentService::MAX_LEN,
        ]]);
    }

    if ($method !== 'POST') cJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
    if (!$uid) cJson(['success' => false, 'error' => 'Bitte anmelden.'], 401);
    Security::requireCsrf();
    switch ($action) {
        case 'add':
            $r = $svc->add((int)($in['news'] ?? 0), $uid, (string)($in['content'] ?? ''));
            cJson(['success' => true, 'data' => $r, 'message' => $r['status'] === 'pending'
                ? 'Danke! Dein Kommentar erscheint, sobald die Redaktion ihn freigegeben hat.' : 'Kommentar veröffentlicht.']);
        case 'delete':
            $svc->delete((int)($in['id'] ?? 0), $uid, $mod);
            cJson(['success' => true, 'message' => 'Kommentar gelöscht.']);
        case 'report':
            $new = $svc->report((int)($in['id'] ?? 0), $uid, (string)($in['reason'] ?? ''));
            cJson(['success' => true, 'message' => $new ? 'Danke, die Redaktion sieht sich das an.' : 'Du hast diesen Kommentar schon gemeldet.']);
    }
    cJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (InvalidArgumentException $e) {
    cJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('comments.php: ' . $e->getMessage());
    cJson(['success' => false, 'error' => 'Interner Fehler.'], 500);
}
