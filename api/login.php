<?php
/**
 * Login API Endpoint (Redaktion und Forennutzer)
 *
 * - Rate limiting (5 Versuche, 15 Min Sperre) pro IP + Benutzername
 * - Nur Datenbank-Konten (kein Notzugang mehr aus config/auth.php)
 * - Forennutzer (Rolle user) brauchen bestaetigte E-Mail + Freigabe (status = active)
 * - Session: lmo26_auth fuer alle Angemeldeten, lmo26_admin NUR fuer editor/admin
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/AccountService.php';

Security::initSession();

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Login-CSRF: Anmeldungen nur von der eigenen Seite annehmen
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$ownHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && strtolower((string)parse_url($origin, PHP_URL_HOST)) !== $ownHost) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Ungültige Herkunft']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$user = trim($data['username'] ?? '');
$pass = $data['password'] ?? '';

$csrfToken = Security::getCsrfToken();

if ($user === '' || $pass === '') {
    echo json_encode(['success' => false, 'error' => 'Benutzername und Passwort erforderlich', 'csrf_token' => $csrfToken]);
    exit;
}

$identifier = Security::getClientIp() . ':' . mb_strtolower($user);
$rateCheck = Security::checkRateLimit($identifier);
if (!$rateCheck['allowed']) {
    echo json_encode([
        'success' => false,
        'error' => $rateCheck['message'],
        'locked' => true,
        'remaining_seconds' => $rateCheck['remaining_seconds'],
        'csrf_token' => $csrfToken
    ]);
    exit;
}

// Pro Konto (unabhaengig von der IP): keine harte Sperre (sonst koennte jeder ein
// bekanntes Konto aussperren), sondern ab 10 Fehlversuchen/Stunde eine Mindestpause
// zwischen zwei Versuchen (2, 4, 8 ... max. 30 s). Zu fruehe Versuche werden sofort
// mit 429 abgewiesen - ohne Passwortpruefung und ohne den Server warten zu lassen.
$acctIdentifier = 'acct:' . mb_strtolower($user);
[$acctFails, $acctLast] = Security::getFailInfo($acctIdentifier);
if ($acctFails >= 10) {
    $wait = min(30, 2 ** min(5, $acctFails - 9));
    $left = ($acctLast + $wait) - time();
    if ($left > 0) {
        http_response_code(429);
        header('Retry-After: ' . $left);
        echo json_encode([
            'success' => false,
            'error' => "Zu viele Fehlversuche für dieses Konto. Bitte $left Sekunden warten.",
            'remaining_seconds' => $left,
            'csrf_token' => $csrfToken
        ]);
        exit;
    }
}

try {
    $pdo = LmoDatabase::getInstance();
    AccountService::ensureSchema($pdo);

    // Login per Benutzername oder E-Mail
    $stmt = $pdo->prepare("SELECT id, username, password_hash, display_name, role, active, status
                           FROM users WHERE lower(username) = lower(?) OR (email <> '' AND lower(email) = lower(?))
                           ORDER BY (lower(username) = lower(?)) DESC LIMIT 1");
    $stmt->execute([$user, $user, $user]);
    $dbUser = $stmt->fetch();

    // Auch bei unbekanntem Benutzer einen Hash pruefen (gleiche Laufzeit, kein Timing-Orakel)
    $hashToCheck = $dbUser['password_hash'] ?? '$2y$10$vQYICkT9iyjYWIOyEZUWc.rWrBoi/Ej72TsHDU1RG3Ug19g2UrCAO';
    $passOk = password_verify($pass, $hashToCheck) && $dbUser;
    if (!$passOk) {
        Security::recordFailedAttempt($identifier);
        Security::recordFailedAttempt($acctIdentifier);
        echo json_encode(['success' => false, 'error' => 'Ungültige Zugangsdaten', 'csrf_token' => $csrfToken]);
        exit;
    }

    $status = $dbUser['status'] ?? 'active';
    $blocked = [
        'pending_email' => 'Bitte bestätige zuerst deine E-Mail-Adresse (Link in der Registrierungs-Mail).',
        'pending_approval' => 'Dein Konto wartet noch auf die Freigabe durch einen Administrator.',
        'rejected' => 'Dieses Konto wurde nicht freigegeben.',
        'inactive' => 'Dein Konto wurde wegen längerer Inaktivität deaktiviert. Bitte wende dich an die Redaktion.',
    ];
    if (isset($blocked[$status]) || !(int)$dbUser['active']) {
        echo json_encode([
            'success' => false,
            'error' => $blocked[$status] ?? 'Benutzer ist deaktiviert',
            'csrf_token' => $csrfToken
        ]);
        exit;
    }

    Security::clearRateLimit($identifier);
    Security::clearRateLimit($acctIdentifier);
    Security::regenerateSession();

    $isStaff = in_array($dbUser['role'], ['editor', 'admin'], true);
    $_SESSION['lmo26_auth'] = true;
    $_SESSION['lmo26_admin'] = $isStaff;          // nur Redaktion darf Admin-APIs nutzen
    $_SESSION['lmo26_user_id'] = (int)$dbUser['id'];
    $_SESSION['lmo26_user_name'] = $dbUser['display_name'] ?: $dbUser['username'];
    $_SESSION['lmo26_username'] = $dbUser['username'];
    $_SESSION['lmo26_user_role'] = $dbUser['role'];
    $_SESSION['lmo26_login_time'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?")->execute([$dbUser['id']]);

    echo json_encode([
        'success' => true,
        'user' => [
            'id' => (int)$dbUser['id'],
            'name' => $_SESSION['lmo26_user_name'],
            'username' => $dbUser['username'],
            'role' => $dbUser['role'],
            'staff' => $isStaff,
        ],
        'csrf_token' => $_SESSION['csrf_token']
    ]);
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Ein Serverfehler ist aufgetreten', 'csrf_token' => $csrfToken]);
}
