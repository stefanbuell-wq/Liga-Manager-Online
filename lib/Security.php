<?php
/**
 * Security Helper Class
 *
 * Provides CSRF protection, rate limiting, and session hardening
 */

require_once __DIR__ . '/LmoDatabase.php';

class Security {

    /** Pro Request nur einmal mit der Benutzertabelle abgleichen */
    private static $authSynced = false;

    // Rate Limiting Settings
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_TIME = 900; // 15 Minuten in Sekunden

    // Angemeldet bleiben: 24 Stunden ohne Aktivitaet, auch ueber Browser-Neustart
    const SESSION_LIFETIME = 86400;

    // Password Policy
    const MIN_PASSWORD_LENGTH = 8;

    // Role hierarchy
    const ROLES = [
        'guest' => 0,
        'user' => 1,
        'editor' => 2,
        'admin' => 3
    ];

    /**
     * Check if user is logged in
     */
    public static function isLoggedIn() {
        // Bedeutet "Redaktion angemeldet" (editor/admin) – fuer Admin-APIs
        self::initSession();
        return isset($_SESSION['lmo26_admin']) && $_SESSION['lmo26_admin'] === true;
    }

    /**
     * Irgendein angemeldetes Konto (auch Forennutzer)
     */
    public static function isMember() {
        self::initSession();
        return !empty($_SESSION['lmo26_auth']) && !empty($_SESSION['lmo26_user_id']);
    }

    /**
     * Get current user's role
     */
    public static function getUserRole() {
        if (!self::isMember() && !self::isLoggedIn()) {
            return 'guest';
        }
        return $_SESSION['lmo26_user_role'] ?? 'user';
    }

    /**
     * Get current user's role level (numeric)
     */
    public static function getRoleLevel($role = null) {
        if ($role === null) {
            $role = self::getUserRole();
        }
        return self::ROLES[$role] ?? 0;
    }

    /**
     * Check if user has required permission level
     * @param string $requiredRole - 'guest', 'user', 'editor', 'admin'
     * @return bool
     */
    public function requirePermission($requiredRole) {
        self::initSession();

        if (!self::isLoggedIn()) {
            return false;
        }

        $userLevel = self::getRoleLevel();
        $requiredLevel = self::ROLES[$requiredRole] ?? 0;

        return $userLevel >= $requiredLevel;
    }

    /**
     * Validate CSRF token from request
     */
    public function validateCsrfToken($token) {
        return self::verifyCsrfToken($token);
    }

    /**
     * Initialize secure session
     */
    public static function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Secure session settings
            ini_set('session.cookie_httponly', 1);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.use_strict_mode', 1);

            // HTTPS only in production
            if ((isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '')
                || ($_SERVER['SERVER_PORT'] ?? '') == 443) {
                ini_set('session.cookie_secure', 1);
            }

            // Eigener Ablageort (data/ ist per .htaccess gesperrt): die Aufraeumroutine des
            // Servers loescht Sitzungen im Standardordner sonst schon nach 24 Minuten.
            $dir = dirname(__DIR__) . '/data/sessions';
            if (is_dir($dir) || @mkdir($dir, 0700, true)) {
                if (is_writable($dir)) {
                    session_save_path($dir);
                    ini_set('session.gc_probability', 1);
                    ini_set('session.gc_divisor', 100);
                }
            }
            ini_set('session.gc_maxlifetime', self::SESSION_LIFETIME);
            ini_set('session.cookie_lifetime', self::SESSION_LIFETIME);

            session_start();
        }

        // Session timeout (24 Stunden ohne Aktivitaet)
        $timeout = self::SESSION_LIFETIME;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::destroySession();
            return false;
        }
        $_SESSION['last_activity'] = time();

        // Cookie-Ablauf bei Aktivitaet nachziehen (hoechstens stuendlich), sonst endet er 24 h nach dem Login
        if (!empty($_SESSION['lmo26_user_id']) && !headers_sent()
            && time() - (int)($_SESSION['cookie_renewed'] ?? 0) > 3600) {
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + self::SESSION_LIFETIME,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Strict',
            ]);
            $_SESSION['cookie_renewed'] = time();
        }

        // Rolle/Status bei jeder Anfrage aus der DB uebernehmen: herabgestufte,
        // gesperrte oder geloeschte Konten verlieren ihre Rechte sofort.
        if (!empty($_SESSION['lmo26_user_id']) && !self::$authSynced) {
            self::$authSynced = true;
            if (!self::syncAuthFromDb()) {
                self::destroySession();
                return false;
            }
        }

        return true;
    }

    /**
     * Session-Rechte mit users abgleichen. false = Session ungueltig
     * (Konto fehlt, inaktiv, nicht freigegeben oder Passwort seit Login geaendert).
     */
    private static function syncAuthFromDb() {
        try {
            $pdo = LmoDatabase::getInstance();
            try {
                $st = $pdo->prepare("SELECT role, active, status, auth_changed_at FROM users WHERE id = ?");
                $st->execute([(int)$_SESSION['lmo26_user_id']]);
            } catch (Exception $e) {
                // Spalte auth_changed_at noch nicht vorhanden
                $st = $pdo->prepare("SELECT role, active, status, NULL AS auth_changed_at FROM users WHERE id = ?");
                $st->execute([(int)$_SESSION['lmo26_user_id']]);
            }
            $u = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return true; // DB nicht erreichbar: nichts entziehen, Endpunkt scheitert ohnehin
        }
        if (!$u || !(int)$u['active'] || (($u['status'] ?? 'active') !== 'active')) {
            return false;
        }
        if (!empty($u['auth_changed_at'])) {
            $changed = strtotime($u['auth_changed_at'] . ' UTC');
            $loginAt = (int)($_SESSION['lmo26_login_time'] ?? 0);
            if ($changed && $changed > $loginAt) return false;
        }
        $_SESSION['lmo26_user_role'] = $u['role'];
        $_SESSION['lmo26_admin'] = in_array($u['role'], ['editor', 'admin'], true);
        $_SESSION['lmo26_auth'] = true;
        return true;
    }

    /**
     * Passwort/Zugang eines Kontos geaendert: alle anderen Sessions dieses Kontos
     * werden beim naechsten Request ungueltig. Die eigene laufende Session bleibt.
     */
    public static function markAuthChanged(PDO $pdo, int $userId) {
        $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('auth_changed_at', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN auth_changed_at DATETIME");
        }
        $pdo->prepare("UPDATE users SET auth_changed_at = datetime('now') WHERE id = ?")->execute([$userId]);
        if (session_status() === PHP_SESSION_ACTIVE && (int)($_SESSION['lmo26_user_id'] ?? 0) === $userId) {
            $_SESSION['lmo26_login_time'] = time();
        }
    }

    /**
     * Regenerate session ID (call after login)
     */
    public static function regenerateSession() {
        session_regenerate_id(true);
    }

    /**
     * Destroy session completely
     */
    public static function destroySession() {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Generate CSRF token
     */
    public static function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrfToken($token) {
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Get CSRF token for API responses
     */
    public static function getCsrfToken() {
        return $_SESSION['csrf_token'] ?? self::generateCsrfToken();
    }

    /**
     * Check rate limiting for login attempts
     * Returns: true if allowed, false if blocked
     * $resetAfterLock: nach Ablauf der Sperre wieder volle Versuche (Login). Ohne (Kontaktformular,
     * Konto-Mails, Zaehler) bleibt das Stundenbudget: bis expires_at wird erneut gesperrt.
     */
    public static function checkRateLimit($identifier, $maxAttempts = null, $resetAfterLock = false) {
        $maxAttempts = $maxAttempts ?? self::MAX_LOGIN_ATTEMPTS;
        $pdo = self::getRateLimitDb();

        // Alte Eintraege loeschen. Zeiten werden in PHP-Ortszeit geschrieben, daher auch mit
        // PHP-Zeit vergleichen (SQLite datetime('now') ist UTC und laege eine Stunde zurueck).
        $pdo->prepare("DELETE FROM rate_limits WHERE expires_at < ?")->execute([date('Y-m-d H:i:s')]);

        // Check current attempts
        $stmt = $pdo->prepare("
            SELECT attempts, locked_until
            FROM rate_limits
            WHERE identifier = ?
        ");
        $stmt->execute([$identifier]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            // Check if locked
            if ($record['locked_until'] && strtotime($record['locked_until']) > time()) {
                $remaining = strtotime($record['locked_until']) - time();
                return [
                    'allowed' => false,
                    'remaining_seconds' => $remaining,
                    'message' => 'Zu viele Fehlversuche. Bitte warten Sie ' . ceil($remaining / 60) . ' Minuten.'
                ];
            }

            // Login: Sperre abgelaufen -> Zaehler zuruecksetzen, damit es wieder volle Versuche gibt
            // (sonst sperrte der naechste Versuch sofort erneut fuer 15 Minuten)
            if ($resetAfterLock && $record['locked_until']) {
                $pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?")->execute([$identifier]);
                return ['allowed' => true];
            }

            // Check attempts
            if ($record['attempts'] >= $maxAttempts) {
                // Lock the account
                $lockUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_TIME);
                $stmt = $pdo->prepare("UPDATE rate_limits SET locked_until = ? WHERE identifier = ?");
                $stmt->execute([$lockUntil, $identifier]);

                return [
                    'allowed' => false,
                    'remaining_seconds' => self::LOCKOUT_TIME,
                    'message' => 'Zu viele Fehlversuche. Bitte warten Sie 15 Minuten.'
                ];
            }
        }

        return ['allowed' => true];
    }

    /**
     * Anzahl Fehlversuche (innerhalb der Ablaufzeit) ohne Sperrwirkung
     */
    public static function getFailedAttempts($identifier) {
        return self::getFailInfo($identifier)[0];
    }

    /**
     * [Anzahl Fehlversuche, Zeitpunkt des letzten Fehlversuchs] (expires_at = letzter + 1 h)
     */
    public static function getFailInfo($identifier) {
        $pdo = self::getRateLimitDb();
        $stmt = $pdo->prepare("SELECT attempts, expires_at FROM rate_limits WHERE identifier = ?");
        $stmt->execute([$identifier]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return [0, 0];
        $exp = strtotime($r['expires_at']);
        if (!$exp || $exp < time()) return [0, 0];
        return [(int)$r['attempts'], $exp - 3600];
    }

    /**
     * Record failed login attempt
     */
    public static function recordFailedAttempt($identifier) {
        $pdo = self::getRateLimitDb();

        $stmt = $pdo->prepare("SELECT id, attempts FROM rate_limits WHERE identifier = ?");
        $stmt->execute([$identifier]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 Stunde

        if ($record) {
            $stmt = $pdo->prepare("
                UPDATE rate_limits
                SET attempts = attempts + 1, expires_at = ?
                WHERE identifier = ?
            ");
            $stmt->execute([$expiresAt, $identifier]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rate_limits (identifier, attempts, expires_at)
                VALUES (?, 1, ?)
            ");
            $stmt->execute([$identifier, $expiresAt]);
        }
    }

    /**
     * Clear rate limit after successful login
     */
    public static function clearRateLimit($identifier) {
        $pdo = self::getRateLimitDb();
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?");
        $stmt->execute([$identifier]);
    }

    /**
     * Get rate limit database connection
     */
    private static function getRateLimitDb() {
        static $pdo = null;

        if ($pdo === null) {
            $dbPath = __DIR__ . '/../data/security.db';
            $dbDir = dirname($dbPath);

            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }

            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Create table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                identifier TEXT UNIQUE NOT NULL,
                attempts INTEGER DEFAULT 0,
                locked_until DATETIME,
                expires_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
        }

        return $pdo;
    }

    /**
     * Validate password strength
     */
    public static function validatePassword($password) {
        $errors = [];

        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors[] = 'Passwort muss mindestens ' . self::MIN_PASSWORD_LENGTH . ' Zeichen haben';
        }

        if (!preg_match('/[A-Za-z]/', $password)) {
            $errors[] = 'Passwort muss mindestens einen Buchstaben enthalten';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Passwort muss mindestens eine Zahl enthalten';
        }

        return $errors;
    }

    /**
     * Sanitize output to prevent XSS
     */
    public static function escape($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Get client IP address
     */
    public static function getClientIp() {
        // Nur REMOTE_ADDR: X-Forwarded-For kann jeder Client selbst setzen
        // und wuerde damit die Login-/Reset-Drosselung aushebeln.
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Set security headers
     */
    public static function setSecurityHeaders() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (adjust as needed)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self' data:; img-src 'self' data: blob:;");
    }

    /**
     * Require CSRF token for POST requests
     */
    public static function requireCsrf() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

            if (!self::verifyCsrfToken($token)) {
                http_response_code(403);
                echo json_encode(['error' => 'CSRF-Token ungültig oder abgelaufen. Bitte Seite neu laden.']);
                exit;
            }
        }
    }
}
