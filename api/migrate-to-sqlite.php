<?php
// api/migrate-to-sqlite.php
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(300); // 5 minutes max

require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/LmoParser.php';

$pdo = LmoDatabase::getInstance();
LmoDatabase::createSchema();

echo "Database initialized.\n";

$ligaDir = __DIR__ . '/../ligen';
$files = scandir($ligaDir);

$countLeagues = 0;
$importedFiles = [];

$stmtInsertLeague = $pdo->prepare("INSERT OR IGNORE INTO leagues (file, name, options) VALUES (:f, :n, :o)");
$stmtInsertTeam = $pdo->prepare("INSERT INTO teams (league_id, original_id, name) VALUES (:lid, :oid, :name)");
$stmtInsertMatch = $pdo->prepare("INSERT INTO matches (league_id, round_nr, home_team_id, guest_team_id, home_goals, guest_goals, match_date, match_time, match_note, report_url) VALUES (:lid, :r, :hid, :gid, :hg, :gg, :md, :mt, :mn, :ru)");

$pdo->beginTransaction();

try {
    foreach ($files as $file) {
        if (!preg_match('/\.l98$/', $file))
            continue;

        echo "Processing $file... ";

        // 1. Parse File
        try {
            $parser = new LmoParser($ligaDir);
            $data = $parser->load($file);

            // Extract Name
            $leagueName = $data['options']['Name'] ?? ($data['options']['Title'] ?? $file);

            // 2. Insert League
            $stmtInsertLeague->execute([
                ':f' => $file,
                ':n' => $leagueName,
                ':o' => json_encode($data['options'])
            ]);

            // Get League ID
            $stmtGetLid = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
            $stmtGetLid->execute([$file]);
            $leagueId = $stmtGetLid->fetchColumn();

            if (!$leagueId) {
                echo "Error getting League ID.\n";
                continue;
            }

            // Clear existing data
            $pdo->exec("DELETE FROM matches WHERE league_id = $leagueId");
            $pdo->exec("DELETE FROM teams WHERE league_id = $leagueId");

            // 3. Insert Teams
            $teamMap = []; // originalId => dbId
            foreach ($data['teams'] as $team) {
                $stmtInsertTeam->execute([
                    ':lid' => $leagueId,
                    ':oid' => $team['id'],
                    ':name' => $team['name']
                ]);
                $teamMap[$team['id']] = $pdo->lastInsertId();
            }

            // 4. Insert Matches
            foreach ($data['matches'] as $roundNr => $matches) {
                foreach ($matches as $m) {
                    $uH = $teamMap[$m['home_id']] ?? null;
                    $uG = $teamMap[$m['guest_id']] ?? null;

                    if ($uH && $uG) {
                        $stmtInsertMatch->execute([
                            ':lid' => $leagueId,
                            ':r' => $roundNr,
                            ':hid' => $uH,
                            ':gid' => $uG,
                            ':hg' => $m['home_goals'],
                            ':gg' => $m['guest_goals'],
                            ':md' => $m['date'] ?? null,
                            ':mt' => $m['time'] ?? null,
                            ':mn' => $m['note'] ?? null,
                            ':ru' => $m['report_url'] ?? null
                        ]);
                    }
                }
            }

            echo "Done.\n";
            $countLeagues++;

        } catch (Exception $e) {
            echo "FAILED: " . $e->getMessage() . "\n";
        }
    }

    $pdo->commit();
    echo "\nMigration complete. Imported $countLeagues leagues.\n";

    // 5. Import News (Spielberichte)
    echo "\nImporting News (Spielberichte)...\n";

    // KORRIGIERTER PFAD: news/news/news.*.php
    $newsDir = __DIR__ . '/../news/news';
    $newsFiles = glob($newsDir . '/news.*.php');

    if (empty($newsFiles)) {
        echo "Keine News-Dateien in $newsDir gefunden.\n";
    }

    $countNews = 0;
    $newsData = []; // Speichern für spätere Verknüpfung

    $stmtInsertNews = $pdo->prepare("
        INSERT OR REPLACE INTO news (id, title, short_content, content, author, email, timestamp, match_date)
        VALUES (:id, :title, :short, :content, :author, :email, :ts, :match_date)
    ");

    $pdo->beginTransaction();

    foreach ($newsFiles as $file) {
        if (!preg_match('/news\.(\d+)\.php$/', basename($file), $matches))
            continue;
        $id = (int) $matches[1];

        // Datei als ISO-8859-1 lesen
        $rawContent = file_get_contents($file);

        // Erste Zeile (PHP die) entfernen
        $lines = explode("\n", $rawContent, 2);
        if (count($lines) < 2) continue;
        $dataLine = $lines[1];

        $parts = explode("|<|", $dataLine);
        if (count($parts) < 7) continue;

        // KORREKTES FELDMAPPING:
        // 0: short_content (Teaser)
        // 1: full_content (HTML)
        // 2: author
        // 3: title
        // 4: email (Format: =email@domain.com)
        // 5: icon
        // 6: timestamp
        // 7: comment_count

        $shortContent = trim($parts[0]);
        $fullContent = trim($parts[1]);
        $author = trim($parts[2]);
        $title = trim($parts[3]);
        $emailRaw = trim($parts[4]);
        $timestamp = isset($parts[6]) ? (int) $parts[6] : 0;

        // Email extrahieren (Format: =email@domain.com oder leer)
        $email = '';
        if (preg_match('/=?(.+@.+)/', $emailRaw, $emailMatch)) {
            $email = $emailMatch[1];
        }

        // Encoding: Windows-1252 → UTF-8 (robust gegen „smart quotes“)
        $fromEnc = 'Windows-1252';
        $title = mb_convert_encoding($title, 'UTF-8', $fromEnc);
        $author = mb_convert_encoding($author, 'UTF-8', $fromEnc);
        $shortContent = mb_convert_encoding($shortContent, 'UTF-8', $fromEnc);
        $fullContent = mb_convert_encoding($fullContent, 'UTF-8', $fromEnc);

        // HTML-Entities dekodieren und aufbereiten
        $fullContent = cleanNewsContent($fullContent);
        $shortContent = cleanNewsContent($shortContent);
        $title = cleanNewsContent($title);

        // Match-Datum aus Timestamp
        $matchDate = $timestamp > 0 ? date('Y-m-d', $timestamp) : null;

        $stmtInsertNews->execute([
            ':id' => $id,
            ':title' => $title,
            ':short' => $shortContent,
            ':content' => $fullContent,
            ':author' => $author,
            ':email' => $email,
            ':ts' => $timestamp,
            ':match_date' => $matchDate
        ]);

        // Für spätere Verknüpfung speichern
        $newsData[$id] = [
            'title' => $title,
            'content' => $fullContent,
            'timestamp' => $timestamp
        ];

        $countNews++;

        if ($countNews % 50 == 0)
            echo "$countNews... ";
    }

    $pdo->commit();
    echo "\nImported $countNews news items.\n";

    // 6. Auto-Verknüpfung: News mit Matches verbinden (optimiert + präzise)
    echo "\nVerknüpfe News mit Spielen...\n";

    $pdo->beginTransaction();

    // Matches laden und nach Timestamp indizieren für schnellen Zugriff
    $stmtMatches = $pdo->query("
        SELECT m.id, m.match_date, t1.name as home_name, t2.name as guest_name
        FROM matches m
        JOIN teams t1 ON m.home_team_id = t1.id
        JOIN teams t2 ON m.guest_team_id = t2.id
        WHERE m.match_date IS NOT NULL AND m.match_date != ''
    ");
    $allMatches = $stmtMatches->fetchAll();

    // Matches nach Datum gruppieren (Key = Unix-Timestamp des Tages)
    $matchesByDay = [];
    foreach ($allMatches as $match) {
        $ts = strtotime($match['match_date']);
        if (!$ts) continue;
        $dayKey = (int)($ts / 86400); // Tag-Nummer seit 1970
        if (!isset($matchesByDay[$dayKey])) {
            $matchesByDay[$dayKey] = [];
        }
        $matchesByDay[$dayKey][] = [
            'id' => $match['id'],
            'home' => strtolower($match['home_name']),
            'guest' => strtolower($match['guest_name']),
            'ts' => $ts
        ];
    }

    $newsToMatch = [];
    $processedNews = 0;

    foreach ($newsData as $newsId => $news) {
        $processedNews++;
        if ($processedNews % 1000 == 0) echo "$processedNews... ";

        $newsTimestamp = $news['timestamp'];
        if ($newsTimestamp <= 0) continue;

        $searchText = strtolower($news['title'] . ' ' . substr($news['content'], 0, 2000));
        $newsDayKey = (int)($newsTimestamp / 86400);

        $bestMatch = null;
        $bestConfidence = 0;

        // Nur Matches ±5 Tage prüfen
        for ($dayOffset = -5; $dayOffset <= 5; $dayOffset++) {
            $checkDay = $newsDayKey + $dayOffset;
            if (!isset($matchesByDay[$checkDay])) continue;

            foreach ($matchesByDay[$checkDay] as $match) {
                // Beide Teams im Text?
                if (strpos($searchText, $match['home']) === false) continue;
                if (strpos($searchText, $match['guest']) === false) continue;

                // Confidence: näher = besser
                $daysDiff = abs($dayOffset);
                $confidence = 1.0 - ($daysDiff * 0.15);

                // Bonus für Ergebnis im Titel
                if (preg_match('/\d+\s*[:\-]\s*\d+/', $news['title'])) {
                    $confidence += 0.15;
                }

                if ($confidence > $bestConfidence) {
                    $bestConfidence = $confidence;
                    $bestMatch = $match['id'];
                }
            }
        }

        if ($bestMatch && $bestConfidence >= 0.5) {
            $newsToMatch[$newsId] = ['match_id' => $bestMatch, 'conf' => min(1.0, $bestConfidence)];
        }
    }

    // Pro Match nur beste News behalten
    $matchToNews = [];
    foreach ($newsToMatch as $newsId => $data) {
        $mid = $data['match_id'];
        if (!isset($matchToNews[$mid]) || $data['conf'] > $matchToNews[$mid]['conf']) {
            $matchToNews[$mid] = ['news_id' => $newsId, 'conf' => $data['conf']];
        }
    }

    // Speichern
    $stmtInsertLink = $pdo->prepare("INSERT OR REPLACE INTO match_news (match_id, news_id, confidence) VALUES (:mid, :nid, :conf)");
    foreach ($matchToNews as $matchId => $data) {
        $stmtInsertLink->execute([':mid' => $matchId, ':nid' => $data['news_id'], ':conf' => round($data['conf'], 2)]);
    }

    $pdo->commit();
    echo "\nVerknüpfungen: " . count($matchToNews) . " (1:1 Match-News)\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nCRITICAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}

/**
 * Bereinigt News-Content: HTML-Entities dekodieren, &br; -> <br> etc.
 */
function cleanNewsContent($text) {
    $text = str_replace('&br;', '<br>', $text);
    $text = str_replace(' &br;', '<br>', $text);

    // HTML-Entities dekodieren
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // BBCode: [img], [url], [b], [i], [u]
    // [url=...]text[/url]
    $text = preg_replace_callback('/\\[url\\s*=\\s*([^\\]]+)\\](.*?)\\[\\/url\\]/is', function ($m) {
        $href = trim($m[1], " '\"");
        // Mixed-Content vermeiden: http -> https hochstufen
        if (stripos($href, 'http://') === 0) $href = 'https://' . substr($href, 7);
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
    }, $text);
    // [url]...[/url]
    $text = preg_replace_callback('/\\[url\\](.*?)\\[\\/url\\]/is', function ($m) {
        $href = trim($m[1]);
        if (stripos($href, 'http://') === 0) $href = 'https://' . substr($href, 7);
        $label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
        return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $label . '</a>';
    }, $text);
    // [img]...[/img]
    $text = preg_replace_callback('/\\[img\\]\\s*(https?:\\/\\/[^\\s\\[\\]]+)\\s*\\[\\/img\\]/i', function ($m) {
        $src = $m[1];
        if (stripos($src, 'http://') === 0) $src = 'https://' . substr($src, 7);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" style="max-height:60px;vertical-align:middle;">';
    }, $text);
    // [color=red]...[/color]
    $text = preg_replace_callback('/\\[color\\s*=\\s*([^\\]]+)\\](.*?)\\[\\/color\\]/is', function ($m) {
        $color = preg_replace('/[^#a-zA-Z0-9(),.%\\s-]/', '', trim($m[1]));
        return '<span style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
    }, $text);
    // [b], [i], [u]
    $text = preg_replace('/\\[b\\](.*?)\\[\\/b\\]/is', '<strong>$1</strong>', $text);
    $text = preg_replace('/\\[i\\](.*?)\\[\\/i\\]/is', '<em>$1</em>', $text);
    $text = preg_replace('/\\[u\\](.*?)\\[\\/u\\]/is', '<u>$1</u>', $text);

    // Gefährliche Tags entfernen
    $text = preg_replace('#</?(script|style|iframe|object|embed)[^>]*>#i', '', $text);

    // font color -> span style=color
    $text = preg_replace_callback('/<font\b([^>]*)>/i', function ($m) {
        $attrs = $m[1];
        $color = null;
        if (preg_match('/\bcolor\s*=\s*([\'"]?)(#[0-9a-fA-F]{3,6}|[a-zA-Z]+)\1/i', $attrs, $cm)) {
            $color = $cm[2];
        }
        return $color ? '<span style="color:' . $color . ';">' : '<span>';
    }, $text);
    $text = str_ireplace('</font>', '</span>', $text);

    // Event-Handler-Attribute entfernen (on*)
    $text = preg_replace('/\s+on\w+\s*=\s*([\'"]).*?\1/i', '', $text);
    // javascript:-Links neutralisieren
    $text = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $text);

    // Orphan <li> in <ul> verpacken
    $text = preg_replace_callback('/(?:^|\s)(<li[^>]*>.*?<\/li>)(?=\s|$)/is', function ($m) {
        return '<ul>' . $m[1] . '</ul>';
    }, $text);

    // Problematische Sonderzeichen vereinheitlichen/entfernen
    $replacements = [
        "\xC2\xA0" => ' ',            // &nbsp;
        "\xEF\xBF\xBD" => '',         // U+FFFD replacement char
        "\xE2\x96\xA0" => ' ',        // ■
        "\xE2\x96\xA1" => ' ',        // □
        "\xE2\x96\xAA" => ' ',        // ▪
        "\xE2\x96\xAB" => ' ',        // ▫
        "\xE2\x97\x8F" => ' ',        // ●
    ];
    $text = strtr($text, $replacements);
    // Mehrfache Leerzeichen normalisieren
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);

    // Doppelte <br> reduzieren
    $text = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $text);

    return trim($text);
}
