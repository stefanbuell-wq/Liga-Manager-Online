<?php
/**
 * Match Events API - Speichern von Tor-Ereignissen
 * POST /api/save-match-events.php
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/Security.php';

try {
    Security::checkAuth();
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $action = $input['action'] ?? '';
    $pdo = LmoDatabase::getInstance();

    switch ($action) {
        case 'add_goal':
            $matchId = (int)($input['match_id'] ?? 0);
            $playerId = (int)($input['player_id'] ?? 0);
            $playerName = trim($input['player_name'] ?? '');
            $teamId = (int)($input['team_id'] ?? 0);
            $minute = (int)($input['minute'] ?? 0);

            if ($matchId <= 0 || $teamId <= 0) {
                throw new Exception('Match und Team erforderlich');
            }

            $stmt = $pdo->prepare("
                INSERT INTO match_events (match_id, event_type, player_id, player_name, team_id, minute)
                VALUES (?, 'goal', ?, ?, ?, ?)
            ");
            $stmt->execute([$matchId, $playerId ?: null, $playerName, $teamId, $minute ?: null]);

            echo json_encode(['success' => true, 'event_id' => $pdo->lastInsertId()]);
            break;

        case 'add_card':
            $matchId = (int)($input['match_id'] ?? 0);
            $playerId = (int)($input['player_id'] ?? 0);
            $playerName = trim($input['player_name'] ?? '');
            $teamId = (int)($input['team_id'] ?? 0);
            $cardType = $input['card_type'] ?? 'yellow_card'; // 'yellow_card' or 'red_card'
            $minute = (int)($input['minute'] ?? 0);

            if ($matchId <= 0 || $teamId <= 0) {
                throw new Exception('Match und Team erforderlich');
            }

            if (!in_array($cardType, ['yellow_card', 'red_card'])) {
                throw new Exception('Ungültiger Kartentyp');
            }

            $stmt = $pdo->prepare("
                INSERT INTO match_events (match_id, event_type, player_id, player_name, team_id, minute)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$matchId, $cardType, $playerId ?: null, $playerName, $teamId, $minute ?: null]);

            echo json_encode(['success' => true, 'event_id' => $pdo->lastInsertId()]);
            break;

        case 'add_assist':
            $matchId = (int)($input['match_id'] ?? 0);
            $playerId = (int)($input['player_id'] ?? 0);
            $playerName = trim($input['player_name'] ?? '');
            $teamId = (int)($input['team_id'] ?? 0);

            if ($matchId <= 0 || $teamId <= 0) {
                throw new Exception('Match und Team erforderlich');
            }

            $stmt = $pdo->prepare("
                INSERT INTO match_events (match_id, event_type, player_id, player_name, team_id)
                VALUES (?, 'assist', ?, ?, ?)
            ");
            $stmt->execute([$matchId, $playerId ?: null, $playerName, $teamId]);

            echo json_encode(['success' => true, 'event_id' => $pdo->lastInsertId()]);
            break;

        case 'delete_event':
            $eventId = (int)($input['event_id'] ?? 0);

            if ($eventId <= 0) {
                throw new Exception('Event ID erforderlich');
            }

            $stmt = $pdo->prepare("DELETE FROM match_events WHERE id = ?");
            $stmt->execute([$eventId]);

            echo json_encode(['success' => true]);
            break;

        case 'get_match_events':
            $matchId = (int)($input['match_id'] ?? 0);

            if ($matchId <= 0) {
                throw new Exception('Match ID erforderlich');
            }

            $stmt = $pdo->prepare("
                SELECT 
                    me.id, me.event_type, me.player_id, me.player_name, me.minute,
                    p.name, p.number, t.name as team_name
                FROM match_events me
                LEFT JOIN players p ON me.player_id = p.id
                JOIN teams t ON me.team_id = t.id
                WHERE me.match_id = ?
                ORDER BY me.minute ASC, me.id ASC
            ");
            $stmt->execute([$matchId]);
            $events = $stmt->fetchAll();

            echo json_encode(['success' => true, 'events' => $events]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
