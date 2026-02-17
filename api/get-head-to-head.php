<?php
/**
 * Head-to-Head Statistiken API
 * GET /api/get-head-to-head.php?liga=file.lmo&team1=id&team2=id
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/LmoDatabase.php';

try {
    $ligaFile = basename($_GET['liga'] ?? '');
    $team1Id = (int)($_GET['team1'] ?? 0);
    $team2Id = (int)($_GET['team2'] ?? 0);
    
    if (empty($ligaFile) || !preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid league file format']);
        exit;
    }

    if ($team1Id <= 0 || $team2Id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Team IDs erforderlich']);
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

    // Get Team Names
    $teams = $pdo->prepare("SELECT id, name FROM teams WHERE league_id = ? AND (id = ? OR id = ?)");
    $teams->execute([$leagueId, $team1Id, $team2Id]);
    $teamData = [];
    foreach ($teams->fetchAll() as $t) {
        $teamData[$t['id']] = $t['name'];
    }

    if (count($teamData) < 2) {
        http_response_code(404);
        echo json_encode(['error' => 'Ein oder beide Teams nicht gefunden']);
        exit;
    }

    // === H2H MATCHES ===
    $h2hMatches = $pdo->prepare("
        SELECT 
            m.id, m.round_nr, m.match_date, m.match_time,
            m.home_team_id, m.guest_team_id,
            m.home_goals, m.guest_goals,
            ht.name as home_name,
            gt.name as guest_name
        FROM matches m
        JOIN teams ht ON m.home_team_id = ht.id
        JOIN teams gt ON m.guest_team_id = gt.id
        WHERE m.league_id = ?
            AND ((m.home_team_id = ? AND m.guest_team_id = ?) 
                OR (m.home_team_id = ? AND m.guest_team_id = ?))
        ORDER BY m.round_nr DESC
    ");
    $h2hMatches->execute([$leagueId, $team1Id, $team2Id, $team2Id, $team1Id]);
    $matches = $h2hMatches->fetchAll();

    // === CALCULATE H2H STATISTICS ===
    $team1Wins = 0;
    $team1Draws = 0;
    $team1Losses = 0;
    $team1GoalsFor = 0;
    $team1GoalsAgainst = 0;

    $team2Wins = 0;
    $team2Draws = 0;
    $team2Losses = 0;
    $team2GoalsFor = 0;
    $team2GoalsAgainst = 0;

    foreach ($matches as $match) {
        $home = $match['home_team_id'];
        $guest = $match['guest_team_id'];
        $hGoals = (int)$match['home_goals'];
        $gGoals = (int)$match['guest_goals'];

        if ($home == $team1Id) {
            $team1GoalsFor += $hGoals;
            $team1GoalsAgainst += $gGoals;

            if ($hGoals > $gGoals) {
                $team1Wins++;
                $team2Losses++;
            } elseif ($hGoals < $gGoals) {
                $team1Losses++;
                $team2Wins++;
            } else {
                $team1Draws++;
                $team2Draws++;
            }

            $team2GoalsFor += $gGoals;
            $team2GoalsAgainst += $hGoals;
        } else {
            // team1 is guest
            $team1GoalsFor += $gGoals;
            $team1GoalsAgainst += $hGoals;

            if ($gGoals > $hGoals) {
                $team1Wins++;
                $team2Losses++;
            } elseif ($gGoals < $hGoals) {
                $team1Losses++;
                $team2Wins++;
            } else {
                $team1Draws++;
                $team2Draws++;
            }

            $team2GoalsFor += $hGoals;
            $team2GoalsAgainst += $gGoals;
        }
    }

    // === HOME ADVANTAGE STATS ===
    $homeStats = $pdo->prepare("
        SELECT 
            home_team_id,
            COUNT(*) as matches,
            SUM(CASE WHEN home_goals > guest_goals THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN home_goals = guest_goals THEN 1 ELSE 0 END) as draws,
            SUM(CASE WHEN home_goals < guest_goals THEN 1 ELSE 0 END) as losses,
            SUM(home_goals) as goals_for,
            SUM(guest_goals) as goals_against
        FROM matches
        WHERE league_id = ? AND home_team_id = ?
    ");
    $homeStats->execute([$leagueId, $team1Id]);
    $team1Home = $homeStats->fetch();

    $homeStats->execute([$leagueId, $team2Id]);
    $team2Home = $homeStats->fetch();

    // === AWAY STATS ===
    $awayStats = $pdo->prepare("
        SELECT 
            guest_team_id,
            COUNT(*) as matches,
            SUM(CASE WHEN guest_goals > home_goals THEN 1 ELSE 0 END) as wins,
            SUM(CASE WHEN guest_goals = home_goals THEN 1 ELSE 0 END) as draws,
            SUM(CASE WHEN guest_goals < home_goals THEN 1 ELSE 0 END) as losses,
            SUM(guest_goals) as goals_for,
            SUM(home_goals) as goals_against
        FROM matches
        WHERE league_id = ? AND guest_team_id = ?
    ");
    $awayStats->execute([$leagueId, $team1Id]);
    $team1Away = $awayStats->fetch();

    $awayStats->execute([$leagueId, $team2Id]);
    $team2Away = $awayStats->fetch();

    // === RECENT FORM (last 5 matches) ===
    $recentMatches = $pdo->prepare("
        SELECT 
            m.id, m.round_nr, m.match_date,
            m.home_team_id, m.guest_team_id,
            m.home_goals, m.guest_goals
        FROM matches m
        WHERE m.league_id = ? 
            AND (m.home_team_id = ? OR m.guest_team_id = ?)
        ORDER BY m.round_nr DESC
        LIMIT 5
    ");

    $recentMatches->execute([$leagueId, $team1Id, $team1Id]);
    $team1Recent = $recentMatches->fetchAll();

    $recentMatches->execute([$leagueId, $team2Id, $team2Id]);
    $team2Recent = $recentMatches->fetchAll();

    echo json_encode([
        'success' => true,
        'teams' => [
            $team1Id => $teamData[$team1Id],
            $team2Id => $teamData[$team2Id]
        ],
        'head_to_head' => [
            'matches' => count($matches),
            'team1' => [
                'wins' => $team1Wins,
                'draws' => $team1Draws,
                'losses' => $team1Losses,
                'goals_for' => $team1GoalsFor,
                'goals_against' => $team1GoalsAgainst,
                'goal_difference' => $team1GoalsFor - $team1GoalsAgainst,
                'points' => ($team1Wins * 3) + ($team1Draws * 1)
            ],
            'team2' => [
                'wins' => $team2Wins,
                'draws' => $team2Draws,
                'losses' => $team2Losses,
                'goals_for' => $team2GoalsFor,
                'goals_against' => $team2GoalsAgainst,
                'goal_difference' => $team2GoalsFor - $team2GoalsAgainst,
                'points' => ($team2Wins * 3) + ($team2Draws * 1)
            ],
            'match_history' => $matches
        ],
        'home_advantage' => [
            'team1' => [
                'matches' => $team1Home['matches'] ?? 0,
                'wins' => $team1Home['wins'] ?? 0,
                'draws' => $team1Home['draws'] ?? 0,
                'losses' => $team1Home['losses'] ?? 0,
                'goals_for' => $team1Home['goals_for'] ?? 0,
                'goals_against' => $team1Home['goals_against'] ?? 0
            ],
            'team2' => [
                'matches' => $team2Home['matches'] ?? 0,
                'wins' => $team2Home['wins'] ?? 0,
                'draws' => $team2Home['draws'] ?? 0,
                'losses' => $team2Home['losses'] ?? 0,
                'goals_for' => $team2Home['goals_for'] ?? 0,
                'goals_against' => $team2Home['goals_against'] ?? 0
            ]
        ],
        'away_performance' => [
            'team1' => [
                'matches' => $team1Away['matches'] ?? 0,
                'wins' => $team1Away['wins'] ?? 0,
                'draws' => $team1Away['draws'] ?? 0,
                'losses' => $team1Away['losses'] ?? 0,
                'goals_for' => $team1Away['goals_for'] ?? 0,
                'goals_against' => $team1Away['goals_against'] ?? 0
            ],
            'team2' => [
                'matches' => $team2Away['matches'] ?? 0,
                'wins' => $team2Away['wins'] ?? 0,
                'draws' => $team2Away['draws'] ?? 0,
                'losses' => $team2Away['losses'] ?? 0,
                'goals_for' => $team2Away['goals_for'] ?? 0,
                'goals_against' => $team2Away['goals_against'] ?? 0
            ]
        ],
        'recent_form' => [
            'team1' => $team1Recent,
            'team2' => $team2Recent
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
