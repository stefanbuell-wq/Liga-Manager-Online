<?php
/**
 * Spieler API - Verwaltung von Spielern pro Team
 * GET /api/get-players.php?team_id=1
 * POST /api/get-players.php - CRUD Operationen
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/Security.php';

try {
    $pdo = LmoDatabase::getInstance();

    // GET - Spieler pro Team abrufen
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['team_id'])) {
        $teamId = (int)$_GET['team_id'];

        if ($teamId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid team ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT id, name, number, position, team_id 
            FROM players 
            WHERE team_id = ? 
            ORDER BY number ASC NULLS LAST, name ASC
        ");
        $stmt->execute([$teamId]);
        $players = $stmt->fetchAll();

        echo json_encode(['success' => true, 'players' => $players], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Auth erforderlich für POST/PUT/DELETE
    Security::checkAuth();

    // POST/PUT/DELETE - Spieler verwalten
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';

        switch ($action) {
            case 'create':
                $teamId = (int)($input['team_id'] ?? 0);
                $name = trim($input['name'] ?? '');
                $number = !empty($input['number']) ? (int)$input['number'] : null;
                $position = trim($input['position'] ?? '');

                if ($teamId <= 0 || empty($name)) {
                    throw new Exception('Team und Name erforderlich');
                }

                // Check if number already exists for this team
                if ($number !== null) {
                    $check = $pdo->prepare("SELECT id FROM players WHERE team_id = ? AND number = ?");
                    $check->execute([$teamId, $number]);
                    if ($check->fetch()) {
                        throw new Exception('Diese Rückennummer existiert bereits');
                    }
                }

                $stmt = $pdo->prepare("
                    INSERT INTO players (team_id, name, number, position)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$teamId, $name, $number, $position ?: null]);

                echo json_encode(['success' => true, 'player_id' => $pdo->lastInsertId()]);
                break;

            case 'update':
                $playerId = (int)($input['player_id'] ?? 0);
                $name = trim($input['name'] ?? '');
                $number = !empty($input['number']) ? (int)$input['number'] : null;
                $position = trim($input['position'] ?? '');

                if ($playerId <= 0 || empty($name)) {
                    throw new Exception('Player ID und Name erforderlich');
                }

                $stmt = $pdo->prepare("
                    UPDATE players
                    SET name = ?, number = ?, position = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $number, $position ?: null, $playerId]);

                echo json_encode(['success' => true]);
                break;

            case 'delete':
                $playerId = (int)($input['player_id'] ?? 0);

                if ($playerId <= 0) {
                    throw new Exception('Player ID erforderlich');
                }

                $stmt = $pdo->prepare("DELETE FROM players WHERE id = ?");
                $stmt->execute([$playerId]);

                echo json_encode(['success' => true]);
                break;

            case 'bulk_import':
                // Mehrere Spieler auf einmal importieren (CSV Format)
                $teamId = (int)($input['team_id'] ?? 0);
                $players = $input['players'] ?? []; // Array mit [{name, number, position}, ...]

                if ($teamId <= 0 || empty($players)) {
                    throw new Exception('Team und Spielerliste erforderlich');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO players (team_id, name, number, position)
                    VALUES (?, ?, ?, ?)
                ");

                $pdo->beginTransaction();
                $count = 0;
                foreach ($players as $p) {
                    if (!empty($p['name'])) {
                        $stmt->execute([
                            $teamId,
                            trim($p['name']),
                            !empty($p['number']) ? (int)$p['number'] : null,
                            !empty($p['position']) ? trim($p['position']) : null
                        ]);
                        $count++;
                    }
                }
                $pdo->commit();

                echo json_encode(['success' => true, 'count' => $count]);
                break;

            default:
                http_response_code(400);
                echo json_encode(['error' => 'Unknown action']);
        }
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
