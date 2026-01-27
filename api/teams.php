<?php
/**
 * API-Endpunkt: Team-Verwaltung
 *
 * GET    ?liga=xxx           - Alle Teams einer Liga
 * GET    ?id=xxx             - Einzelnes Team
 * POST   (action=create)     - Neues Team erstellen
 * POST   (action=update)     - Team aktualisieren
 * POST   (action=delete)     - Team löschen
 *
 * Security: Auth + CSRF protection for POST
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoRepository.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

Security::initSession();
header('Content-Type: application/json; charset=utf-8');

// Auth check for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true) {
        http_response_code(403);
        echo json_encode(['error' => 'Nicht autorisiert', 'csrf_token' => Security::getCsrfToken()]);
        exit;
    }
    Security::requireCsrf();
}

// Spalten hinzufügen falls nicht vorhanden (einfache Migration)
function ensureTeamColumns() {
    $pdo = LmoDatabase::getInstance();

    // Prüfen welche Spalten existieren
    $cols = $pdo->query("PRAGMA table_info(teams)")->fetchAll();
    $colNames = array_column($cols, 'name');

    if (!in_array('short_name', $colNames)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN short_name TEXT");
    }
    if (!in_array('logo_file', $colNames)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN logo_file TEXT");
    }
}

try {
    ensureTeamColumns();
    $repo = new LmoRepository();

    // GET: Teams einer Liga abrufen
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['liga'])) {
        $league = $repo->getLeagueByFile($_GET['liga']);
        $teams = $repo->getTeamsForAdmin($league['id']);

        echo json_encode([
            'success' => true,
            'league' => [
                'id' => $league['id'],
                'file' => $league['file'],
                'name' => $league['options']['Name'] ?? $league['name']
            ],
            'teams' => $teams
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET: Einzelnes Team
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        $team = $repo->getTeamById((int)$_GET['id']);

        if (!$team) {
            http_response_code(404);
            echo json_encode(['error' => 'Team nicht gefunden']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'team' => $team
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST: Team-Aktionen
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                $liga = $input['liga'] ?? '';
                $name = trim($input['name'] ?? '');
                $shortName = trim($input['short_name'] ?? '') ?: null;
                $logoFile = trim($input['logo_file'] ?? '') ?: null;

                if (empty($liga) || empty($name)) {
                    throw new Exception('Liga und Name sind erforderlich');
                }

                $league = $repo->getLeagueByFile($liga);

                if ($repo->teamNameExists($league['id'], $name)) {
                    throw new Exception('Ein Team mit diesem Namen existiert bereits');
                }

                $teamId = $repo->createTeam($league['id'], $name, $shortName, $logoFile);

                echo json_encode([
                    'success' => true,
                    'message' => 'Team erstellt',
                    'team_id' => $teamId
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'update':
                $teamId = (int)($input['id'] ?? 0);
                $name = trim($input['name'] ?? '');
                $shortName = trim($input['short_name'] ?? '') ?: null;
                $logoFile = trim($input['logo_file'] ?? '') ?: null;

                if (empty($teamId) || empty($name)) {
                    throw new Exception('Team-ID und Name sind erforderlich');
                }

                $team = $repo->getTeamById($teamId);
                if (!$team) {
                    throw new Exception('Team nicht gefunden');
                }

                if ($repo->teamNameExists($team['league_id'], $name, $teamId)) {
                    throw new Exception('Ein anderes Team mit diesem Namen existiert bereits');
                }

                $repo->updateTeam($teamId, $name, $shortName, $logoFile);

                echo json_encode([
                    'success' => true,
                    'message' => 'Team aktualisiert'
                ], JSON_UNESCAPED_UNICODE);
                break;

            case 'delete':
                $teamId = (int)($input['id'] ?? 0);

                if (empty($teamId)) {
                    throw new Exception('Team-ID ist erforderlich');
                }

                $repo->deleteTeam($teamId);

                echo json_encode([
                    'success' => true,
                    'message' => 'Team gelöscht'
                ], JSON_UNESCAPED_UNICODE);
                break;

            default:
                throw new Exception('Unbekannte Aktion: ' . $action);
        }
        exit;
    }

    // Keine gültige Anfrage
    echo json_encode([
        'error' => 'Ungültige Anfrage',
        'usage' => [
            'GET ?liga=xxx' => 'Alle Teams einer Liga',
            'GET ?id=xxx' => 'Einzelnes Team',
            'POST action=create' => 'Team erstellen (liga, name, short_name?, logo_file?)',
            'POST action=update' => 'Team aktualisieren (id, name, short_name?, logo_file?)',
            'POST action=delete' => 'Team löschen (id)'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
