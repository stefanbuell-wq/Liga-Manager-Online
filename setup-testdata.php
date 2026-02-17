<?php
/**
 * Setup-Skript für Test-Daten
 * Fügt Spieler und Match-Events ein
 * Erreichbar unter: /setup-testdata.php
 */

require_once __DIR__ . '/lib/LmoDatabase.php';

$pdo = LmoDatabase::getInstance();

// Initialize ONLY the player and match_events tables (don't drop existing data)
$pdo->exec("CREATE TABLE IF NOT EXISTS players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    team_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    number INTEGER,
    position TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE(team_id, number)
)");

$pdo->exec("CREATE TABLE IF NOT EXISTS match_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    match_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    player_id INTEGER,
    player_name TEXT,
    team_id INTEGER NOT NULL,
    minute INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY(player_id) REFERENCES players(id),
    FOREIGN KEY(team_id) REFERENCES teams(id)
)");

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_players_team ON players(team_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_match ON match_events(match_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_player ON match_events(player_id)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_type ON match_events(event_type)");

// Get first league
$stmt = $pdo->query("SELECT id, name FROM leagues LIMIT 1");
$league = $stmt->fetch();

if (!$league) {
    die("Keine Liga gefunden. Bitte erst Ligen hochladen.");
}

$leagueId = $league['id'];

// Get teams
$stmt = $pdo->query("SELECT id, name FROM teams WHERE league_id = ? ORDER BY id LIMIT 4");
$stmt->execute([$leagueId]);
$teams = $stmt->fetchAll();

if (count($teams) < 2) {
    die("Mindestens 2 Teams nötig für Test-Daten.");
}

echo "
<style>
    body { font-family: Arial; background: #f5f5f5; padding: 20px; }
    .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
    h1 { color: #333; }
    .success { color: #28a745; font-weight: bold; }
    .info { color: #0056b3; margin: 10px 0; }
    button { padding: 10px 20px; background: #0056b3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
    button:hover { background: #003d82; }
    .step { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0056b3; }
</style>
<div class='container'>
    <h1>⚽ Test-Daten Setup</h1>
";

try {
    // Step 1: Add Players
    echo "<div class='step'><h2>1️⃣ Füge Spieler ein...</h2>";
    
    $playerNames = [
        ['name' => 'Max Mustermann', 'number' => 10, 'position' => 'Mittelfeld'],
        ['name' => 'Thomas Schmidt', 'number' => 9, 'position' => 'Stürmer'],
        ['name' => 'Hans Weber', 'number' => 1, 'position' => 'Torwart'],
        ['name' => 'Klaus Müller', 'number' => 4, 'position' => 'Abwehr'],
        ['name' => 'Peter Fischer', 'number' => 7, 'position' => 'Außen'],
    ];

    $addedPlayers = [];
    
    foreach ($teams as $team) {
        foreach ($playerNames as $idx => $player) {
            $stmt = $pdo->prepare("
                INSERT OR IGNORE INTO players (team_id, name, number, position)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$team['id'], $player['name'], $player['number'] + $idx, $player['position']]);
            
            // Get inserted player
            $stmt = $pdo->prepare("SELECT id FROM players WHERE team_id = ? AND number = ?");
            $stmt->execute([$team['id'], $player['number'] + $idx]);
            $p = $stmt->fetch();
            if ($p) {
                $addedPlayers[$team['id']][] = $p['id'];
            }
        }
        echo "<div class='info'>✓ " . count($playerNames) . " Spieler für " . $team['name'] . " hinzugefügt</div>";
    }
    echo "</div>";

    // Step 2: Get matches
    echo "<div class='step'><h2>2️⃣ Suche Spiele...</h2>";
    
    $stmt = $pdo->query("SELECT id, home_team_id, guest_team_id, round_nr FROM matches WHERE league_id = ? LIMIT 3");
    $stmt->execute([$leagueId]);
    $matches = $stmt->fetchAll();
    
    echo "<div class='info'>✓ " . count($matches) . " Spiele gefunden</div>";
    echo "</div>";

    // Step 3: Add match events
    echo "<div class='step'><h2>3️⃣ Füge Match-Events ein...</h2>";
    
    $eventCount = 0;
    
    foreach ($matches as $match) {
        $homeTeam = $match['home_team_id'];
        $guestTeam = $match['guest_team_id'];
        
        // Add 2-3 goals for home team
        $homeGoals = rand(1, 3);
        for ($i = 0; $i < $homeGoals; $i++) {
            if (isset($addedPlayers[$homeTeam]) && count($addedPlayers[$homeTeam]) > 0) {
                $playerId = $addedPlayers[$homeTeam][array_rand($addedPlayers[$homeTeam])];
                $minute = 15 + ($i * 20) + rand(0, 10);
                
                $stmt = $pdo->prepare("
                    INSERT INTO match_events (match_id, event_type, player_id, team_id, minute)
                    VALUES (?, 'goal', ?, ?, ?)
                ");
                $stmt->execute([$match['id'], $playerId, $homeTeam, $minute]);
                $eventCount++;
                
                // Random assist
                if (rand(0, 1)) {
                    $assistId = $addedPlayers[$homeTeam][array_rand($addedPlayers[$homeTeam])];
                    if ($assistId !== $playerId) {
                        $stmt = $pdo->prepare("
                            INSERT INTO match_events (match_id, event_type, player_id, team_id)
                            VALUES (?, 'assist', ?, ?)
                        ");
                        $stmt->execute([$match['id'], $assistId, $homeTeam]);
                        $eventCount++;
                    }
                }
            }
        }
        
        // Add 0-2 goals for guest team
        $guestGoals = rand(0, 2);
        for ($i = 0; $i < $guestGoals; $i++) {
            if (isset($addedPlayers[$guestTeam]) && count($addedPlayers[$guestTeam]) > 0) {
                $playerId = $addedPlayers[$guestTeam][array_rand($addedPlayers[$guestTeam])];
                $minute = 25 + ($i * 25) + rand(0, 10);
                
                $stmt = $pdo->prepare("
                    INSERT INTO match_events (match_id, event_type, player_id, team_id, minute)
                    VALUES (?, 'goal', ?, ?, ?)
                ");
                $stmt->execute([$match['id'], $playerId, $guestTeam, $minute]);
                $eventCount++;
            }
        }
        
        // Add random cards
        $cardCount = rand(2, 4);
        for ($i = 0; $i < $cardCount; $i++) {
            $randomTeam = rand(0, 1) ? $homeTeam : $guestTeam;
            if (isset($addedPlayers[$randomTeam]) && count($addedPlayers[$randomTeam]) > 0) {
                $playerId = $addedPlayers[$randomTeam][array_rand($addedPlayers[$randomTeam])];
                $cardType = rand(0, 3) ? 'yellow_card' : 'red_card';
                $minute = rand(10, 85);
                
                $stmt = $pdo->prepare("
                    INSERT INTO match_events (match_id, event_type, player_id, team_id, minute)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$match['id'], $cardType, $playerId, $randomTeam, $minute]);
                $eventCount++;
            }
        }
    }
    
    echo "<div class='success'>✓ " . $eventCount . " Match-Events hinzugefügt</div>";
    echo "</div>";

    // Summary
    echo "
    <div class='step' style='border-left-color: #28a745; background: #f0fff4;'>
        <h2 style='color: #28a745;'>✅ Setup abgeschlossen!</h2>
        <p>Die Datenbank wurde mit Test-Daten gefüllt.</p>
        <p><strong>Was nun?</strong></p>
        <ol>
            <li>Gehe zu <strong>index.html</strong></li>
            <li>Wähle eine Liga aus</li>
            <li>Klick auf <strong>\"Statistiken\"</strong> Tab</li>
            <li>Klick auf <strong>\"⚽ Spielerstatistiken\"</strong></li>
            <li>Dort siehst du die Torschützenliste, Karten usw.</li>
        </ol>
        <p style='margin-top: 20px;'>
            <button onclick=\"window.location.href='index.html'\">🏠 Zur Hauptseite</button>
        </p>
    </div>
    ";

} catch (Exception $e) {
    echo "<div style='color: red; font-weight: bold;'>❌ Fehler: " . $e->getMessage() . "</div>";
}

echo "</div>";
