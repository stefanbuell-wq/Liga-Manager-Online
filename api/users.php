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

    // Default-Admin erstellen falls keine Benutzer existieren
    $count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, display_name, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', password_hash('hafo', PASSWORD_DEFAULT), 'Administrator', 'admin']);
    }
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
        // Legacy-Session ohne User-ID -> als Admin behandeln
        return true;
    }
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    return $role === 'admin';
}

try {
    ensureUsersTable();
    $pdo = LmoDatabase::getInstance();

    // GET: Benutzerliste
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['list'])) {
        requireAuth();

        $stmt = $pdo->query("
            SELECT id, username, display_name, role, active, created_at, last_login
            FROM users
            ORDER BY username
        ");
        $users = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'users' => $users
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET: Einzelner Benutzer
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        requireAuth();

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

                // Update ohne Passwort
                $stmt = $pdo->prepare("
                    UPDATE users SET display_name = ?, role = ?, active = ? WHERE id = ?
                ");
                $stmt->execute([$displayName, $role, $active, $userId]);

                // Passwort nur ändern wenn angegeben
                if (!empty($newPassword)) {
                    $passwordErrors = Security::validatePassword($newPassword);
                    if (!empty($passwordErrors)) {
                        throw new Exception(implode('. ', $passwordErrors));
                    }
                    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
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

                echo json_encode([
                    'success' => true,
                    'message' => 'Passwort geändert'
                ], JSON_UNESCAPED_UNICODE);
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
