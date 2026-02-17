<?php
/**
 * Security Helper Class
 *
 * Provides CSRF protection, rate limiting, and session hardening
 */

class Security {

    // Rate Limiting Settings
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_TIME = 900; // 15 Minuten in Sekunden

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
        self::initSession();
        return isset($_SESSION['lmo26_admin']) && $_SESSION['lmo26_admin'] === true;
    }

    /**
     * Get current user's role
     */
    public static function getUserRole() {
        if (!self::isLoggedIn()) {
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
            if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
                ini_set('session.cookie_secure', 1);
            }

            session_start();
        }

        // Session timeout (2 Stunden)
        $timeout = 7200;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::destroySession();
            return false;
        }
        $_SESSION['last_activity'] = time();

        return true;
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
     */
    public static function checkRateLimit($identifier) {
        // Temporary bypass via env or toggle file to assist recovery
        if (getenv('LMO26_RL_OFF') === '1') {
            return ['allowed' => true];
        }
        $toggleFile = __DIR__ . '/../data/ratelimit.off';
        if (file_exists($toggleFile)) {
            return ['allowed' => true];
        }

        $pdo = self::getRateLimitDb();

        // Clean old entries
        $pdo->exec("DELETE FROM rate_limits WHERE expires_at < datetime('now')");

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

            // Check attempts
            if ($record['attempts'] >= self::MAX_LOGIN_ATTEMPTS) {
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
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        // Check for proxy headers (be careful with these in production)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }

        return $ip;
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
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:;");
    }

    /**
     * Require CSRF token for POST requests
     */
    public static function requireCsrf() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
