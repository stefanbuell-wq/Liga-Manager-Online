<?php
/**
 * Spielerstatistiken API
 * GET /api/get-player-stats.php?liga=file.lmo
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/LmoRepository.php';

try {
    $ligaFile = basename($_GET['liga'] ?? '');
    
    if (empty($ligaFile) || !preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid league file format']);
        exit;
    }

    $pdo = LmoDatabase::getInstance();
    
    // Get League ID
    $stmt = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
    $stmt->execute([$ligaFile]);
    $leagueId = $stmt->fetchColumn();

    if (!$leagueId) {
        http_response_code(404);
        echo json_encode(['error' => 'Liga nicht gefunden']);
        exit;
    }

    // === TORSCHÜTZENLISTE (Top Scorers) ===
    $topScorers = $pdo->prepare("
        SELECT 
            p.id, p.name, p.number,
            t.id as team_id, t.name as team_name,
            COUNT(*) as goals,
            SUM(CASE WHEN me2.event_type = 'assist' THEN 1 ELSE 0 END) as assists
        FROM match_events me
        LEFT JOIN players p ON me.player_id = p.id
        LEFT JOIN teams t ON me.team_id = t.id
        LEFT JOIN match_events me2 ON me.match_id = me2.match_id 
            AND me2.event_type = 'assist' 
            AND me2.player_id = p.id
        WHERE me.event_type = 'goal' 
            AND t.league_id = ?
            AND p.id IS NOT NULL
        GROUP BY p.id
        ORDER BY goals DESC, assists DESC
        LIMIT 20
    ");
    $topScorers->execute([$leagueId]);
    $topScorersData = $topScorers->fetchAll();

    // === GELBE KARTEN ===
    $yellowCards = $pdo->prepare("
        SELECT 
            p.id, p.name, p.number,
            t.id as team_id, t.name as team_name,
            COUNT(*) as yellow_cards
        FROM match_events me
        LEFT JOIN players p ON me.player_id = p.id
        LEFT JOIN teams t ON me.team_id = t.id
        WHERE me.event_type = 'yellow_card' 
            AND t.league_id = ?
            AND p.id IS NOT NULL
        GROUP BY p.id
        ORDER BY yellow_cards DESC
        LIMIT 15
    ");
    $yellowCards->execute([$leagueId]);
    $yellowCardsData = $yellowCards->fetchAll();

    // === ROTE KARTEN ===
    $redCards = $pdo->prepare("
        SELECT 
            p.id, p.name, p.number,
            t.id as team_id, t.name as team_name,
            COUNT(*) as red_cards
        FROM match_events me
        LEFT JOIN players p ON me.player_id = p.id
        LEFT JOIN teams t ON me.team_id = t.id
        WHERE me.event_type = 'red_card' 
            AND t.league_id = ?
            AND p.id IS NOT NULL
        GROUP BY p.id
        ORDER BY red_cards DESC
        LIMIT 10
    ");
    $redCards->execute([$leagueId]);
    $redCardsData = $redCards->fetchAll();

    // === ASSISTS RANGLISTE ===
    $topAssists = $pdo->prepare("
        SELECT 
            p.id, p.name, p.number,
            t.id as team_id, t.name as team_name,
            COUNT(*) as assists
        FROM match_events me
        LEFT JOIN players p ON me.player_id = p.id
        LEFT JOIN teams t ON me.team_id = t.id
        WHERE me.event_type = 'assist' 
            AND t.league_id = ?
            AND p.id IS NOT NULL
        GROUP BY p.id
        ORDER BY assists DESC
        LIMIT 10
    ");
    $topAssists->execute([$leagueId]);
    $topAssistsData = $topAssists->fetchAll();

    // === SPIELERSTATISTIKEN PRO TEAM ===
    $teams = $pdo->prepare("SELECT id, name FROM teams WHERE league_id = ? ORDER BY name");
    $teams->execute([$leagueId]);
    $teamsData = $teams->fetchAll();

    $teamStats = [];
    foreach ($teamsData as $team) {
        $playerStats = $pdo->prepare("
            SELECT 
                p.id, p.name, p.number, p.position,
                COALESCE(
                    (SELECT COUNT(*) FROM match_events WHERE player_id = p.id AND event_type = 'goal'),
                    0
                ) as goals,
                COALESCE(
                    (SELECT COUNT(*) FROM match_events WHERE player_id = p.id AND event_type = 'assist'),
                    0
                ) as assists,
                COALESCE(
                    (SELECT COUNT(*) FROM match_events WHERE player_id = p.id AND event_type = 'yellow_card'),
                    0
                ) as yellow_cards,
                COALESCE(
                    (SELECT COUNT(*) FROM match_events WHERE player_id = p.id AND event_type = 'red_card'),
                    0
                ) as red_cards
            FROM players p
            WHERE p.team_id = ?
            ORDER BY goals DESC, assists DESC
        ");
        $playerStats->execute([$team['id']]);
        
        $teamStats[] = [
            'team_id' => $team['id'],
            'team_name' => $team['name'],
            'players' => $playerStats->fetchAll()
        ];
    }

    echo json_encode([
        'success' => true,
        'league_id' => $leagueId,
        'top_scorers' => $topScorersData,
        'yellow_cards' => $yellowCardsData,
        'red_cards' => $redCardsData,
        'top_assists' => $topAssistsData,
        'team_statistics' => $teamStats
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
