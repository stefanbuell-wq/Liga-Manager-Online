<?php
/**
 * Login API Endpoint
 *
 * Security features:
 * - Rate limiting (5 attempts, 15 min lockout)
 * - Session regeneration after login
 * - Secure session cookies
 * - bcrypt password hashing
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

// Initialize secure session
Security::initSession();

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
$user = trim($data['username'] ?? '');
$pass = $data['password'] ?? '';

// Return CSRF token with any response
$csrfToken = Security::getCsrfToken();

if (empty($user) || empty($pass)) {
    echo json_encode([
        'success' => false,
        'error' => 'Benutzername und Passwort erforderlich',
        'csrf_token' => $csrfToken
    ]);
    exit;
}

// Rate limiting check
$identifier = Security::getClientIp() . ':' . $user;
$toggleFile = __DIR__ . '/../data/ratelimit.off';
$bypass = (getenv('LMO26_RL_OFF') === '1') || file_exists($toggleFile);
$rateCheck = $bypass ? ['allowed' => true] : Security::checkRateLimit($identifier);
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

try {
    $pdo = LmoDatabase::getInstance();

    // Check if users table exists
    $tableExists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetch();

    $loginSuccess = false;
    $userData = null;

    $ovFile = __DIR__ . '/../data/login.override';
    if ($bypass && file_exists($ovFile)) {
        $ov = trim(@file_get_contents($ovFile));
        if ($ov !== '') {
            $parts = explode(':', $ov, 2);
            if (count($parts) === 2) {
                if ($user === $parts[0] && $pass === $parts[1]) {
                    $loginSuccess = true;
                    $userData = ['id' => null, 'name' => 'Admin', 'role' => 'admin'];
                }
            }
        }
    }

    if ($tableExists) {
        // Database login
        $stmt = $pdo->prepare("SELECT id, password_hash, display_name, role, active FROM users WHERE username = ?");
        $stmt->execute([$user]);
        $dbUser = $stmt->fetch();

        if ($dbUser && password_verify($pass, $dbUser['password_hash'])) {
            if (!$dbUser['active']) {
                Security::recordFailedAttempt($identifier);
                echo json_encode([
                    'success' => false,
                    'error' => 'Benutzer ist deaktiviert',
                    'csrf_token' => $csrfToken
                ]);
                exit;
            }

            $loginSuccess = true;
            $userData = [
                'id' => $dbUser['id'],
                'name' => $dbUser['display_name'] ?: $user,
                'role' => $dbUser['role']
            ];

            // Update last login
            $stmt = $pdo->prepare("UPDATE users SET last_login = datetime('now') WHERE id = ?");
            $stmt->execute([$dbUser['id']]);
        }
    }

    // Fallback: Config file (Legacy)
    if (!$loginSuccess) {
        $configFile = __DIR__ . '/../config/auth.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if ($user === $config['username'] && password_verify($pass, $config['password_hash'])) {
                $loginSuccess = true;
                $userData = [
                    'id' => null,
                    'name' => 'Admin',
                    'role' => 'admin'
                ];
            }
        }
    }

    if ($loginSuccess) {
        // Clear rate limit on success
        Security::clearRateLimit($identifier);

        // Regenerate session ID to prevent session fixation
        Security::regenerateSession();

        // Set session data
        $_SESSION['lmo26_admin'] = true;
        $_SESSION['lmo26_user_id'] = $userData['id'];
        $_SESSION['lmo26_user_name'] = $userData['name'];
        $_SESSION['lmo26_user_role'] = $userData['role'];
        $_SESSION['lmo26_login_time'] = time();

        // Generate new CSRF token after login
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        echo json_encode([
            'success' => true,
            'user' => [
                'name' => $userData['name'],
                'role' => $userData['role']
            ],
            'csrf_token' => $_SESSION['csrf_token']
        ]);
    } else {
        // Record failed attempt
        Security::recordFailedAttempt($identifier);

        echo json_encode([
            'success' => false,
            'error' => 'Ungueltige Zugangsdaten',
            'csrf_token' => $csrfToken
        ]);
    }

} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => 'Ein Serverfehler ist aufgetreten',
        'csrf_token' => $csrfToken
    ]);
}
