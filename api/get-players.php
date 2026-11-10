<?php
/**
 * Spieler API - Verwaltung von Spielern pro Team
 * GET /api/get-players.php?team_id=1
 * POST /api/get-players.php - CRUD Operationen
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/PlayerRepository.php';

try {
    $pdo = LmoDatabase::getInstance();
    $players = new PlayerRepository(); // legt Profil-Spalten/Historie an

    // GET - Einzelner Spieler (Profil + Vereinshistorie)
    //   oeffentlich: Alter/Jahrgang statt Geburtsdatum; mit &admin=1 (nur Redaktion) alle Felder
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
        $full = !empty($_GET['admin']) && Security::isLoggedIn();
        echo json_encode(['success' => true, 'player' => $players->getPlayer((int)$_GET['id'], !$full)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // GET - Spieler pro Team abrufen
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['team_id'])) {
        $teamId = (int)$_GET['team_id'];

        if ($teamId <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid team ID']);
            exit;
        }

        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.number, p.position, p.team_id, p.birth_date, p.nationality, p.joined_at,
                   p.source, p.source_updated_at,
                   (SELECT COUNT(*) FROM player_history h WHERE h.player_id = p.id) AS history_count
            FROM players p
            WHERE p.team_id = ?
            ORDER BY p.number ASC NULLS LAST, p.name ASC
        ");
        $stmt->execute([$teamId]);
        $list = $stmt->fetchAll();
        $isStaff = Security::isLoggedIn();
        foreach ($list as &$row) {
            $row['age'] = PlayerRepository::age($row['birth_date']);
            if (!$isStaff) unset($row['birth_date']); // oeffentlich nur Alter
        }
        unset($row);

        echo json_encode(['success' => true, 'players' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Aenderungen nur fuer Redaktion (editor/admin) und mit gueltigem CSRF-Token
    // (vorher Aufruf einer nicht existierenden Funktion -> jede Aenderung brach mit Fehler ab)
    if (!Security::isLoggedIn()) {
        http_response_code(403);
        echo json_encode(['error' => 'Nicht autorisiert']);
        exit;
    }
    Security::requireCsrf();

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

                $newId = (int)$pdo->lastInsertId();
                if (isset($input['profile']) && is_array($input['profile'])) {
                    $players->saveProfile($newId, $input['profile']);
                }
                echo json_encode(['success' => true, 'player_id' => $newId]);
                break;

            case 'update':
                $playerId = (int)($input['player_id'] ?? 0);
                $name = trim($input['name'] ?? '');
                $number = !empty($input['number']) ? (int)$input['number'] : null;
                $position = trim($input['position'] ?? '');

                if ($playerId <= 0 || empty($name)) {
                    throw new Exception('Player ID und Name erforderlich');
                }

                if ($number !== null) {
                    $check = $pdo->prepare("SELECT p2.name FROM players p1 JOIN players p2 ON p2.team_id = p1.team_id AND p2.number = ? AND p2.id <> p1.id WHERE p1.id = ?");
                    $check->execute([$number, $playerId]);
                    if ($other = $check->fetchColumn()) {
                        throw new InvalidArgumentException("Rückennummer $number ist im Team bereits an $other vergeben.");
                    }
                }

                $stmt = $pdo->prepare("
                    UPDATE players
                    SET name = ?, number = ?, position = ?, updated_at = datetime('now')
                    WHERE id = ?
                ");
                $stmt->execute([$name, $number, $position ?: null, $playerId]);
                if (isset($input['profile']) && is_array($input['profile'])) {
                    $players->saveProfile($playerId, $input['profile']);
                }

                echo json_encode(['success' => true]);
                break;

            case 'copy_previous':
                // Kader eines Teams aus der Vorsaison uebernehmen
                $r = $players->copySquadFromPrevious((int)($input['team_id'] ?? 0));
                echo json_encode(['success' => true] + $r, JSON_UNESCAPED_UNICODE);
                break;

            case 'copy_previous_league':
                // Alle Teams einer Liga
                $st = $pdo->prepare("SELECT t.id, t.name FROM teams t JOIN leagues l ON l.id = t.league_id WHERE l.file = ?");
                $st->execute([basename((string)($input['liga'] ?? ''))]);
                $res = [];
                foreach ($st->fetchAll() as $t) {
                    $res[] = ['team' => $t['name']] + $players->copySquadFromPrevious((int)$t['id']);
                }
                echo json_encode(['success' => true, 'teams' => $res,
                    'copied' => array_sum(array_column($res, 'copied'))], JSON_UNESCAPED_UNICODE);
                break;

            case 'history_add':
                $id = $players->addHistory((int)($input['player_id'] ?? 0), $input);
                echo json_encode(['success' => true, 'id' => $id]);
                break;

            case 'history_update':
                $players->updateHistory((int)($input['history_id'] ?? 0), $input);
                echo json_encode(['success' => true]);
                break;

            case 'history_delete':
                $players->deleteHistory((int)($input['history_id'] ?? 0));
                echo json_encode(['success' => true]);
                break;

            case 'delete':
                $playerId = (int)($input['player_id'] ?? 0);

                if ($playerId <= 0) {
                    throw new Exception('Player ID erforderlich');
                }

                $pdo->prepare("DELETE FROM player_history WHERE player_id = ?")->execute([$playerId]);
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

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    error_log('get-players: ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
