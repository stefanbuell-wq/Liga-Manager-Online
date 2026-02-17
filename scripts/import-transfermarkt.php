<?php
/**
 * Transfermarkt-Import: Kader für Oberliga Hamburg 25/26
 *
 * Einmal-Script: Fetcht Spielerdaten von Transfermarkt.de und importiert sie in die DB.
 * Aufruf: php scripts/import-transfermarkt.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/LmoDatabase.php';
$pdo = LmoDatabase::getInstance();

// Team-Mapping: DB team_id => Transfermarkt Verein-ID
$teamMapping = [
    13881 => ['tm_id' => 7538,  'slug' => 'sv-curslack-neuengamme'],
    13882 => ['tm_id' => 24733, 'slug' => 'fk-nikola-tesla-hamburg'],
    13883 => ['tm_id' => 8759,  'slug' => 'hamburger-turnerschaft-von-1816-r-v-'],
    13884 => ['tm_id' => 4610,  'slug' => 'sv-halstenbek-rellingen'],
    13885 => ['tm_id' => 3861,  'slug' => 'tura-harksheide'],
    13886 => ['tm_id' => 21580, 'slug' => 'fc-turkiye-hamburg'],
    13887 => ['tm_id' => 2538,  'slug' => 'tsv-sasel'],
    13888 => ['tm_id' => 431,   'slug' => 'sc-victoria-hamburg'],
    13889 => ['tm_id' => 6233,  'slug' => 'fc-suderelbe'],
    13890 => ['tm_id' => 2636,  'slug' => 'tsv-buchholz-08'],
    13891 => ['tm_id' => 1266,  'slug' => 'sc-vorwarts-wacker-04-billstedt'],
    13892 => ['tm_id' => 1478,  'slug' => 'hebc-hamburg'],
    13893 => ['tm_id' => 3417,  'slug' => 'niendorfer-tsv'],
    13894 => ['tm_id' => 18887, 'slug' => 'etsv-hamburg'],
    13895 => ['tm_id' => 6150,  'slug' => 'uhlenhorster-sc-paloma'],
    13896 => ['tm_id' => 803,   'slug' => 'eimsbuetteler-tv'],
    13897 => ['tm_id' => 907,   'slug' => 'tus-dassendorf'],
    13898 => ['tm_id' => 14361, 'slug' => 'fc-teutonia-05-ottensen'],
];

// Position-Mapping von Transfermarkt-Deutsch
$positionMap = [
    'Torwart'                   => 'Torwart',
    'Innenverteidiger'          => 'Abwehr',
    'Linker Verteidiger'        => 'Abwehr',
    'Rechter Verteidiger'       => 'Abwehr',
    'Abwehr'                    => 'Abwehr',
    'Defensives Mittelfeld'     => 'Mittelfeld',
    'Zentrales Mittelfeld'      => 'Mittelfeld',
    'Offensives Mittelfeld'     => 'Mittelfeld',
    'Linkes Mittelfeld'         => 'Mittelfeld',
    'Rechtes Mittelfeld'        => 'Mittelfeld',
    'Mittelfeld'                => 'Mittelfeld',
    'Linksaußen'                => 'Sturm',
    'Rechtsaußen'               => 'Sturm',
    'Mittelstürmer'             => 'Sturm',
    'Hängende Spitze'           => 'Sturm',
    'Sturm'                     => 'Sturm',
    'Rechter Mittelstürmer'     => 'Sturm',
    'Linker Mittelstürmer'      => 'Sturm',
];

// Verify teams exist
echo "=== Transfermarkt Import - Oberliga Hamburg 25/26 ===\n\n";
$teamCheck = $pdo->prepare("SELECT id, name FROM teams WHERE id = ?");
foreach ($teamMapping as $teamId => $info) {
    $teamCheck->execute([$teamId]);
    $team = $teamCheck->fetch();
    if (!$team) {
        echo "WARNUNG: Team ID $teamId nicht in DB gefunden!\n";
    }
}

// Check existing players
$existingCount = $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
if ($existingCount > 0) {
    echo "ACHTUNG: Es existieren bereits $existingCount Spieler in der Datenbank.\n";
    echo "Moechtest du alle bestehenden Spieler loeschen und neu importieren? (j/n): ";
    $input = trim(fgets(STDIN));
    if (strtolower($input) !== 'j') {
        echo "Abgebrochen.\n";
        exit(0);
    }
    // Delete all existing players for these teams
    $teamIds = array_keys($teamMapping);
    $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
    $pdo->prepare("DELETE FROM players WHERE team_id IN ($placeholders)")->execute($teamIds);
    echo "Bestehende Spieler geloescht.\n\n";
}

$insertStmt = $pdo->prepare("INSERT INTO players (team_id, name, number, position) VALUES (?, ?, ?, ?)");
$totalImported = 0;
$errors = [];

foreach ($teamMapping as $teamId => $info) {
    $teamCheck->execute([$teamId]);
    $team = $teamCheck->fetch();
    $teamName = $team ? $team['name'] : "Team $teamId";

    $url = "https://www.transfermarkt.de/{$info['slug']}/kader/verein/{$info['tm_id']}/saison_id/2025";

    echo "Lade: $teamName (TM-ID: {$info['tm_id']})...\n";
    echo "  URL: $url\n";

    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                       "Accept: text/html,application/xhtml+xml\r\n" .
                       "Accept-Language: de-DE,de;q=0.9\r\n",
            'timeout' => 30,
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html === false) {
        $error = "Konnte $teamName nicht laden (URL: $url)";
        echo "  FEHLER: $error\n\n";
        $errors[] = $error;
        sleep(3);
        continue;
    }

    echo "  HTML geladen (" . strlen($html) . " Bytes)\n";

    // Parse player data from the HTML
    $players = parseTransfermarktKader($html);

    if (empty($players)) {
        $error = "Keine Spieler gefunden fuer $teamName";
        echo "  WARNUNG: $error\n\n";
        $errors[] = $error;
        sleep(3);
        continue;
    }

    $count = 0;
    foreach ($players as $player) {
        $position = $player['position'];
        // Map position
        foreach ($positionMap as $key => $mapped) {
            if (stripos($position, $key) !== false) {
                $position = $mapped;
                break;
            }
        }
        // If position not in our map, try to categorize
        if (!in_array($position, ['Torwart', 'Abwehr', 'Mittelfeld', 'Sturm'])) {
            $position = 'Mittelfeld'; // fallback
        }

        try {
            $insertStmt->execute([$teamId, $player['name'], $player['number'], $position]);
            $count++;
        } catch (Exception $e) {
            // Duplicate number? Try with NULL number
            if (strpos($e->getMessage(), 'UNIQUE constraint') !== false && $player['number'] !== null) {
                try {
                    $insertStmt->execute([$teamId, $player['name'], null, $position]);
                    $count++;
                    echo "  Info: {$player['name']} - Nr. {$player['number']} doppelt, ohne Nr. importiert\n";
                } catch (Exception $e2) {
                    echo "  Fehler bei {$player['name']}: {$e2->getMessage()}\n";
                }
            } else {
                echo "  Fehler bei {$player['name']}: {$e->getMessage()}\n";
            }
        }
    }

    echo "  -> $count Spieler importiert\n\n";
    $totalImported += $count;

    // Rate limiting - be nice to Transfermarkt
    sleep(3);
}

echo "=== Import abgeschlossen ===\n";
echo "Gesamt importiert: $totalImported Spieler\n";

if (!empty($errors)) {
    echo "\nFehler:\n";
    foreach ($errors as $e) {
        echo "  - $e\n";
    }
}

/**
 * Parse Transfermarkt Kader-Seite und extrahiere Spielerdaten
 */
function parseTransfermarktKader($html) {
    $players = [];

    // Transfermarkt uses a responsive table with class "items"
    // Each player row has:
    // - Rückennummer in <div class="rn_nummer">
    // - Name in the player link
    // - Position in a cell

    // Strategy: Use regex to find player rows in the squad table

    // Method 1: Find the main squad table rows
    // Pattern: Look for spielprofil links with player names

    // Find all table rows that contain player data
    // The structure is typically:
    // <td class="zentriert rueckennummer bg_..."><div class="rn_nummer">XX</div></td>
    // ... <a ... href="/spieler-name/profil/spieler/XXXXX">Player Name</a> ...
    // ... <td ...>Position</td>

    // Try to extract using the responsive table
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($dom);

    // Find all player rows in the items table
    $rows = $xpath->query("//table[contains(@class, 'items')]//tbody//tr[contains(@class, 'odd') or contains(@class, 'even')]");

    if ($rows->length === 0) {
        // Fallback: try inline-table
        $rows = $xpath->query("//div[contains(@class, 'responsive-table')]//tbody//tr");
    }

    foreach ($rows as $row) {
        $player = ['name' => '', 'number' => null, 'position' => ''];

        // Number - look for rn_nummer div
        $numNodes = $xpath->query(".//div[contains(@class, 'rn_nummer')]", $row);
        if ($numNodes->length > 0) {
            $num = trim($numNodes->item(0)->textContent);
            if (is_numeric($num)) {
                $player['number'] = (int)$num;
            }
        }

        // Name - look for hauptlink or spielprofil link
        $nameNodes = $xpath->query(".//td[contains(@class, 'hauptlink')]//a[contains(@href, '/profil/spieler/')]", $row);
        if ($nameNodes->length === 0) {
            $nameNodes = $xpath->query(".//a[contains(@href, '/profil/spieler/')]", $row);
        }
        if ($nameNodes->length > 0) {
            $player['name'] = trim($nameNodes->item(0)->textContent);
        }

        // Position - look for the position cell (usually the last inline-table or a specific td)
        $posNodes = $xpath->query(".//td[contains(@class, 'posrela')]//tr[last()]//td", $row);
        if ($posNodes->length > 0) {
            $player['position'] = trim($posNodes->item(0)->textContent);
        }

        // Alternative: position in separate cells
        if (empty($player['position'])) {
            $cells = $xpath->query(".//td", $row);
            foreach ($cells as $cell) {
                $text = trim($cell->textContent);
                if (in_array($text, ['Torwart', 'Innenverteidiger', 'Linker Verteidiger', 'Rechter Verteidiger',
                    'Defensives Mittelfeld', 'Zentrales Mittelfeld', 'Offensives Mittelfeld',
                    'Linkes Mittelfeld', 'Rechtes Mittelfeld', 'Linksaußen', 'Rechtsaußen',
                    'Mittelstürmer', 'Hängende Spitze', 'Sturm', 'Abwehr', 'Mittelfeld'])) {
                    $player['position'] = $text;
                    break;
                }
            }
        }

        if (!empty($player['name'])) {
            $players[] = $player;
        }
    }

    // Deduplicate by name (some players appear twice in responsive tables)
    $seen = [];
    $unique = [];
    foreach ($players as $p) {
        $key = $p['name'] . '_' . ($p['number'] ?? 'x');
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $p;
        }
    }

    return $unique;
}
