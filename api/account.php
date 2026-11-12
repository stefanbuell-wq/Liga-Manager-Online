<?php
/**
 * Konto-API fuer Forennutzer (oeffentlich, CSRF-geschuetzt)
 *   GET  ?action=me                         -> Sitzungsinfo
 *   POST action=register                    -> Registrierung (E-Mail-Bestaetigung + Freigabe)
 *   POST action=verify   {token}            -> E-Mail bestaetigen
 *   POST action=request_reset {login}       -> Mail "Passwort zuruecksetzen"
 *   POST action=reset    {token, password}  -> neues Passwort setzen
 *   POST action=change_password {current_password, new_password} (angemeldet)
 *   POST action=update_email {email, current_password} (angemeldet)
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/AccountService.php';

Security::initSession();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(array $data, int $code = 200) {
    http_response_code($code);
    $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Zaehlt Aktionen pro IP; nach 5 in 1 h -> 15 Min Sperre (nutzt Login-Rate-Limit) */
function throttle(string $bucket) {
    $id = $bucket . ':' . Security::getClientIp();
    $rc = Security::checkRateLimit($id);
    if (!$rc['allowed']) out(['success' => false, 'error' => 'Zu viele Anfragen. Bitte später erneut versuchen.'], 429);
    Security::recordFailedAttempt($id);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (Security::isMember()) {
            $svc = new AccountService();
            $u = $svc->getUser((int)$_SESSION['lmo26_user_id']);
            out(['success' => true, 'authenticated' => true, 'user' => [
                'id' => (int)$u['id'], 'username' => $u['username'], 'display_name' => $u['display_name'],
                'role' => $u['role'], 'email' => $u['email'], 'staff' => in_array($u['role'], ['editor', 'admin'], true),
            ]]);
        }
        out(['success' => true, 'authenticated' => false]);
    }

    Security::requireCsrf();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';
    $svc = new AccountService();

    switch ($action) {
        case 'register':
            if (!empty($in['website'])) out(['success' => true, 'message' => 'Fast geschafft! Bitte prüfe dein E-Mail-Postfach.']); // Honeypot
            throttle('register');
            $pdo = LmoDatabase::getInstance();
            $res = $svc->register($in['username'] ?? '', $in['email'] ?? '', $in['password'] ?? '', '', $in['legacy_claim'] ?? '');
            out(['success' => true, 'message' => 'Fast geschafft! Wir haben dir eine E-Mail geschickt – bitte bestätige deine Adresse über den Link darin.']);

        case 'verify':
            out(['success' => true, 'message' => $svc->verifyEmail((string)($in['token'] ?? ''))]);

        case 'request_reset':
            throttle('reset');
            try { $svc->requestReset((string)($in['login'] ?? '')); }
            catch (Exception $e) { error_log('requestReset: ' . $e->getMessage()); }
            out(['success' => true, 'message' => 'Falls ein Konto mit diesen Angaben existiert, haben wir eine E-Mail mit einem Link zum Zurücksetzen geschickt.']);

        case 'reset':
            $svc->resetPassword((string)($in['token'] ?? ''), (string)($in['password'] ?? ''));
            out(['success' => true, 'message' => 'Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.']);

        case 'change_password':
        case 'update_email':
            if (!Security::isMember()) out(['success' => false, 'error' => 'Bitte zuerst anmelden.'], 401);
            $u = $svc->getUser((int)$_SESSION['lmo26_user_id']);
            if (!password_verify((string)($in['current_password'] ?? ''), $u['password_hash'])) {
                out(['success' => false, 'error' => 'Aktuelles Passwort ist falsch.'], 400);
            }
            $pdo = LmoDatabase::getInstance();
            if ($action === 'change_password') {
                $err = Security::validatePassword((string)($in['new_password'] ?? ''));
                if ($err) throw new InvalidArgumentException(implode('. ', $err));
                $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                    ->execute([password_hash($in['new_password'], PASSWORD_DEFAULT), $u['id']]);
                Security::markAuthChanged($pdo, (int)$u['id']); // andere Sitzungen abmelden
                out(['success' => true, 'message' => 'Passwort geändert.']);
            }
            $email = strtolower(trim((string)($in['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
            $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE lower(email) = ? AND id <> ?");
            $st->execute([$email, $u['id']]);
            if ($st->fetchColumn() > 0) throw new InvalidArgumentException('Diese E-Mail-Adresse wird bereits verwendet.');
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $u['id']]);
            out(['success' => true, 'message' => 'E-Mail-Adresse gespeichert.']);

        default:
            out(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
    }
} catch (InvalidArgumentException $e) {
    out(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Exception $e) {
    error_log('account.php: ' . $e->getMessage());
    $msg = str_contains($e->getMessage(), 'SMTP') || str_contains($e->getMessage(), 'mail')
        ? 'Die E-Mail konnte nicht versendet werden. Bitte später erneut versuchen.'
        : 'Ein Serverfehler ist aufgetreten.';
    out(['success' => false, 'error' => $msg], 500);
}
