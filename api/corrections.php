<?php
/**
 * API-Endpunkt: Tabellenkorrekturen (Punktabzüge/-zuschläge)
 *
 * GET    ?liga=xxx           - Alle Korrekturen einer Liga
 * POST   (action=save)       - Korrektur speichern/aktualisieren
 * POST   (action=delete)     - Korrektur löschen
 *
 * Security: Auth + CSRF protection
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

Security::initSession();
header('Content-Type: application/json; charset=utf-8');

// Auth Check
if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Nicht autorisiert', 'csrf_token' => Security::getCsrfToken()]);
    exit;
}

// CSRF protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

// Tabelle erstellen falls nicht vorhanden
function ensureCorrectionsTable() {
    $pdo = LmoDatabase::getInstance();
    $pdo->exec("CREATE TABLE IF NOT EXISTS point_corrections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        league_id INTEGER NOT NULL,
        team_id INTEGER NOT NULL,
        points INTEGER NOT NULL DEFAULT 0,
        reason TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE,
        FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
        UNIQUE(league_id, team_id)
    )");
}

try {
    ensureCorrectionsTable();
    $pdo = LmoDatabase::getInstance();

    // GET: Korrekturen einer Liga abrufen
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['liga'])) {
        $stmt = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
        $stmt->execute([$_GET['liga']]);
        $leagueId = $stmt->fetchColumn();

        if (!$leagueId) {
            throw new Exception('Liga nicht gefunden');
        }

        $stmt = $pdo->prepare("
            SELECT pc.*, t.name as team_name
            FROM point_corrections pc
            JOIN teams t ON pc.team_id = t.id
            WHERE pc.league_id = ?
            ORDER BY t.name
        ");
        $stmt->execute([$leagueId]);
        $corrections = $stmt->fetchAll();

        // Auch Teams ohne Korrektur für Dropdown
        $stmt = $pdo->prepare("SELECT id, name FROM teams WHERE league_id = ? ORDER BY name");
        $stmt->execute([$leagueId]);
        $teams = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'corrections' => $corrections,
            'teams' => $teams
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST: Aktionen
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'save':
                $liga = $input['liga'] ?? '';
                $teamId = (int)($input['team_id'] ?? 0);
                $points = (int)($input['points'] ?? 0);
                $reason = trim($input['reason'] ?? '');

                if (empty($liga) || empty($teamId)) {
                    throw new Exception('Liga und Team sind erforderlich');
                }

                $stmt = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
                $stmt->execute([$liga]);
                $leagueId = $stmt->fetchColumn();

                if (!$leagueId) {
                    throw new Exception('Liga nicht gefunden');
                }

                // Upsert (INSERT OR REPLACE)
                $stmt = $pdo->prepare("
                    INSERT OR REPLACE INTO point_corrections (league_id, team_id, points, reason)
                    VALUES (:lid, :tid, :pts, :reason)
                ");
                $stmt->execute([
                    ':lid' => $leagueId,
                    ':tid' => $teamId,
                    ':pts' => $points,
                    ':reason' => $reason ?: null
                ]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Korrektur gespeichert'
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'delete':
                $id = (int)($input['id'] ?? 0);

                if (empty($id)) {
                    throw new Exception('ID ist erforderlich');
                }

                $stmt = $pdo->prepare("DELETE FROM point_corrections WHERE id = ?");
                $stmt->execute([$id]);

                echo json_encode([
                    'success' => true,
                    'message' => 'Korrektur gelöscht'
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
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
