<?php
/**
 * API-Endpunkt: Benutzerverwaltung
 *
 * GET    ?list=1             - Alle Benutzer auflisten
 * GET    ?id=xxx             - Einzelnen Benutzer abrufen
 * POST   (action=create)     - Neuen Benutzer erstellen
 * POST   (action=update)     - Benutzer aktualisieren
 * POST   (action=delete)     - Benutzer löschen
 * POST   (action=change_password) - Eigenes Passwort ändern
 *
 * Security: CSRF protection, password policy
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/AccountService.php';

Security::initSession();
header('Content-Type: application/json; charset=utf-8');

// CSRF protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

// Tabelle erstellen falls nicht vorhanden
function ensureUsersTable() {
    $pdo = LmoDatabase::getInstance();
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        display_name TEXT,
        role TEXT DEFAULT 'editor',
        active INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login DATETIME
    )");

    // Kein automatischer Default-Admin mehr  – Ersteinrichtung per CLI-Skript
}

// Auth Check (außer für Passwort-Änderung des eigenen Accounts)
function requireAuth() {
    if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'Nicht autorisiert']);
        exit;
    }
}

// Admin-Rolle erforderlich
function requireAdmin($pdo) {
    $userId = $_SESSION['lmo26_user_id'] ?? null;
    if (!$userId) {
        return false; // keine Legacy-Sessions ohne Benutzer-ID mehr
    }
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? AND active = 1 AND COALESCE(status,'active') = 'active'");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    return $role === 'admin';
}

// Nur Admins: Benutzerdaten (E-Mail, Status) sind fuer Redakteure tabu
function requireAdminOr403($pdo) {
    if (!requireAdmin($pdo)) {
        http_response_code(403);
        echo json_encode(['error' => 'Nur für Administratoren']);
        exit;
    }
}

try {
    ensureUsersTable();
    $pdo = LmoDatabase::getInstance();
    $accounts = new AccountService(); // legt Zusatzspalten/Tabellen an

    // GET: Benutzerliste
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list'])) {
        requireAuth();
        requireAdminOr403($pdo);
        $accounts->deactivateInactiveDaily();
        $withLegacy = !empty($_GET['legacy']);
        $stmt = $pdo->query("
            SELECT id, username, display_name, role, active, created_at, last_login, email, status, legacy_claim, avatar
            FROM users
            " . ($withLegacy ? "" : "WHERE NOT (role = 'user' AND last_login IS NULL AND (email IS NULL OR email = '') AND COALESCE(status,'active') = 'active')") . "
            ORDER BY CASE WHEN status IN ('pending_approval','pending_email') THEN 0 ELSE 1 END, CASE role WHEN 'admin' THEN 0 WHEN 'editor' THEN 1 ELSE 2 END, (last_login IS NULL), username COLLATE NOCASE
        ");
        $users = $stmt->fetchAll();
        $legacyCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user' AND last_login IS NULL AND (email IS NULL OR email = '') AND COALESCE(status,'active') = 'active'")->fetchColumn();

        echo json_encode([
            'success' => true,
            'users' => $users,
            'legacy_hidden' => $withLegacy ? 0 : $legacyCount,
            'pending' => count(array_filter($users, fn($u) => in_array($u['status'], ['pending_approval', 'pending_email'], true))),
            'mail_configured' => (new Mailer())->isConfigured()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET: Einzelner Benutzer
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        requireAuth();
        requireAdminOr403($pdo);

        $stmt = $pdo->prepare("
            SELECT id, username, display_name, role, active, created_at, last_login
            FROM users WHERE id = ?
        ");
        $stmt->execute([(int)$_GET['id']]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new Exception('Benutzer nicht gefunden');
        }

        echo json_encode([
            'success' => true,
            'user' => $user
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST: Aktionen
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                requireAuth();
                if (!requireAdmin($pdo)) {
                    throw new Exception('Nur Administratoren können Benutzer erstellen');
                }

                $username = trim($input['username'] ?? '');
                $password = $input['password'] ?? '';
                $displayName = trim($input['display_name'] ?? '');
                $role = $input['role'] ?? 'editor';

                if (empty($username) || empty($password)) {
                    throw new Exception('Benutzername und Passwort sind erforderlich');
                }

                // Password policy check
                $passwordErrors = Security::validatePassword($password);
                if (!empty($passwordErrors)) {
                    throw new Exception(implode('. ', $passwordErrors));
                }

                // Prüfen ob Username existiert
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception('Benutzername existiert bereits');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password_hash, display_name, role)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $displayName ?: $username,
                    in_array($role, ['admin', 'editor']) ? $role : 'editor'
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Benutzer erstellt',
                    'user_id' => $pdo->lastInsertId()
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'update':
                requireAuth();
                if (!requireAdmin($pdo)) {
                    throw new Exception('Nur Administratoren können Benutzer bearbeiten');
                }

                $userId = (int)($input['id'] ?? 0);
                $displayName = trim($input['display_name'] ?? '');
                $role = $input['role'] ?? 'editor';
                $active = isset($input['active']) ? (int)$input['active'] : 1;
                $newPassword = $input['password'] ?? '';

                if (empty($userId)) {
                    throw new Exception('Benutzer-ID ist erforderlich');
                }

                if (!in_array($role, ['user', 'editor', 'admin'], true)) {
                    throw new Exception('Ungültige Rolle');
                }
                // Letzten aktiven Admin nicht herabstufen/deaktivieren
                if ($role !== 'admin' || !$active) {
                    $st = $pdo->prepare("SELECT role, active FROM users WHERE id = ?");
                    $st->execute([$userId]);
                    $cur = $st->fetch();
                    if ($cur && $cur['role'] === 'admin' && (int)$cur['active'] === 1) {
                        $others = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND COALESCE(status,'active') = 'active' AND id <> " . (int)$userId)->fetchColumn();
                        if ($others === 0) throw new Exception('Der letzte aktive Administrator kann nicht herabgestuft oder deaktiviert werden');
                    }
                }
                // Reaktivieren setzt auch den Status zurueck (z. B. nach Inaktivitaet)
                $stmt = $pdo->prepare("
                    UPDATE users SET display_name = ?, role = ?, active = ?,
                        status = CASE WHEN ? = 1 AND COALESCE(status,'active') IN ('inactive','disabled') THEN 'active' ELSE status END
                    WHERE id = ?
                ");
                $stmt->execute([$displayName, $role, $active, $active, $userId]);

                // Passwort nur ändern wenn angegeben
                if (!empty($newPassword)) {
                    $passwordErrors = Security::validatePassword($newPassword);
                    if (!empty($passwordErrors)) {
                        throw new Exception(implode('. ', $passwordErrors));
                    }
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                    Security::markAuthChanged($pdo, (int)$userId);
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Benutzer aktualisiert'
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'delete':
                requireAuth();
                if (!requireAdmin($pdo)) {
                    throw new Exception('Nur Administratoren können Benutzer löschen');
                }

                $userId = (int)($input['id'] ?? 0);

                if (empty($userId)) {
                    throw new Exception('Benutzer-ID ist erforderlich');
                }

                // Nicht den letzten Admin löschen
                $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
                $adminCount = $stmt->fetchColumn();

                $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userRole = $stmt->fetchColumn();

                if ($userRole === 'admin' && $adminCount <= 1) {
                    throw new Exception('Der letzte Administrator kann nicht gelöscht werden');
                }

                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Benutzer gelöscht'
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'change_password':
                requireAuth();

                $currentPassword = $input['current_password'] ?? '';
                $newPassword = $input['new_password'] ?? '';
                $userId = $_SESSION['lmo26_user_id'] ?? null;

                if (!$userId) {
                    // Legacy: Kein User-ID in Session, verwende config
                    throw new Exception('Passwortänderung nicht verfügbar (Legacy-Modus)');
                }

                if (empty($currentPassword) || empty($newPassword)) {
                    throw new Exception('Aktuelles und neues Passwort sind erforderlich');
                }

                // Password policy check
                $passwordErrors = Security::validatePassword($newPassword);
                if (!empty($passwordErrors)) {
                    throw new Exception(implode('. ', $passwordErrors));
                }

                // Aktuelles Passwort prüfen
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $hash = $stmt->fetchColumn();

                if (!password_verify($currentPassword, $hash)) {
                    throw new Exception('Aktuelles Passwort ist falsch');
                }

                // Neues Passwort setzen
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                Security::markAuthChanged($pdo, (int)$userId);

                echo json_encode([
                    'success' => true,
                    'message' => 'Passwort geändert'
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'approve':
            case 'reject':
            case 'send_reset':
                requireAuth();
                if (!requireAdmin($pdo)) {
                    throw new Exception('Nur Administratoren');
                }
                $targetId = (int)($input['id'] ?? 0);
                if ($action === 'approve') {
                    $login = $accounts->approve($targetId, ($input['accept_claim'] ?? true) !== false);
                    $msg = "Freigeschaltet – Anmeldename: $login (E-Mail verschickt)";
                } elseif ($action === 'reject') {
                    $accounts->reject($targetId);
                    $msg = 'Registrierung abgelehnt und gelöscht';
                } else {
                    $accounts->sendResetFor($targetId);
                    $msg = 'E-Mail zum Zurücksetzen verschickt';
                }
                echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new Exception('Unbekannte Aktion');
        }
        exit;
    }

    echo json_encode(['error' => 'Ungültige Anfrage'], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ], JSON_UNESCAPED_UNICODE);
}
