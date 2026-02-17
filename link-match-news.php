<?php
/**
 * Verknüpft Spiele mit Spielberichten basierend auf Datum und Teamnamen
 */

require_once __DIR__ . '/lib/LmoDatabase.php';

$pdo = LmoDatabase::getInstance();

echo "=== Match-News Verknüpfung ===" . PHP_EOL . PHP_EOL;

// Alle Matches mit Datum laden
$matches = $pdo->query("
    SELECT m.id, m.match_date, m.league_id, m.round_nr,
           t1.name as home_name, t2.name as guest_name
    FROM matches m
    JOIN teams t1 ON m.home_team_id = t1.id
    JOIN teams t2 ON m.guest_team_id = t2.id
    WHERE m.match_date IS NOT NULL AND m.match_date != ''
")->fetchAll();

echo "Matches mit Datum: " . count($matches) . PHP_EOL;

// Alle News mit Datum laden
$news = $pdo->query("
    SELECT id, title, short_content, match_date, timestamp
    FROM news
    WHERE (match_date IS NOT NULL AND match_date != '') OR timestamp > 0
")->fetchAll();

echo "News mit Datum: " . count($news) . PHP_EOL . PHP_EOL;

// News nach Datum indexieren
$newsByDate = [];
foreach ($news as $n) {
    $date = $n['match_date'];
    if (!$date && $n['timestamp'] > 0) {
        $date = date('d.m.Y', $n['timestamp']);
    }
    if ($date) {
        if (!isset($newsByDate[$date])) $newsByDate[$date] = [];
        $newsByDate[$date][] = $n;
    }
}

echo "Unique Datums mit News: " . count($newsByDate) . PHP_EOL . PHP_EOL;

// Bestehende Verknüpfungen löschen
$pdo->exec("DELETE FROM match_news");
echo "Bestehende Verknüpfungen gelöscht." . PHP_EOL . PHP_EOL;

// Verknüpfungen erstellen
$stmt = $pdo->prepare("INSERT OR IGNORE INTO match_news (match_id, news_id, confidence) VALUES (?, ?, ?)");

$linked = 0;
$checked = 0;

foreach ($matches as $match) {
    $checked++;
    $matchDate = $match['match_date'];

    // Auch 1-2 Tage danach prüfen (Spielberichte erscheinen oft am nächsten Tag)
    $dates = [$matchDate];
    if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $matchDate, $dm)) {
        $ts = mktime(0, 0, 0, $dm[2], $dm[1], $dm[3]);
        $dates[] = date('d.m.Y', $ts + 86400); // +1 Tag
        $dates[] = date('d.m.Y', $ts + 172800); // +2 Tage
    }

    foreach ($dates as $checkDate) {
        if (!isset($newsByDate[$checkDate])) continue;

        foreach ($newsByDate[$checkDate] as $n) {
            // Prüfen ob Teamnamen in Titel oder Short-Content vorkommen
            $searchText = strtolower($n['title'] . ' ' . $n['short_content']);

            // Teamnamen normalisieren für Suche
            $home = strtolower($match['home_name']);
            $guest = strtolower($match['guest_name']);

            // Kurznamen extrahieren (erstes Wort nach Vereinspräfix)
            $homeShort = preg_replace('/^(fc|sv|sc|tsv|tus|vfb|vfl|1\.|fsv|hsv|\d+\.?\s*)/i', '', $home);
            $homeShort = explode(' ', trim($homeShort))[0];
            $guestShort = preg_replace('/^(fc|sv|sc|tsv|tus|vfb|vfl|1\.|fsv|hsv|\d+\.?\s*)/i', '', $guest);
            $guestShort = explode(' ', trim($guestShort))[0];

            $confidence = 0;

            // Voller Name Match
            if (strpos($searchText, $home) !== false) $confidence += 0.4;
            if (strpos($searchText, $guest) !== false) $confidence += 0.4;

            // Kurzname Match (nur wenn lang genug um eindeutig zu sein)
            if (strlen($homeShort) > 3 && strpos($searchText, $homeShort) !== false) $confidence += 0.2;
            if (strlen($guestShort) > 3 && strpos($searchText, $guestShort) !== false) $confidence += 0.2;

            // Nur wenn mindestens ein Team gefunden wurde
            if ($confidence >= 0.4) {
                // Bonus wenn genau am Spieltag
                if ($checkDate === $matchDate) $confidence += 0.1;

                $stmt->execute([$match['id'], $n['id'], min(1.0, $confidence)]);
                $linked++;
            }
        }
    }

    if ($checked % 2000 === 0) {
        echo "$checked Matches geprüft, $linked Verknüpfungen..." . PHP_EOL;
    }
}

echo PHP_EOL . "=== Ergebnis ===" . PHP_EOL;
echo "Matches geprüft: $checked" . PHP_EOL;
echo "Verknüpfungen erstellt: $linked" . PHP_EOL;

$total = $pdo->query("SELECT COUNT(*) FROM match_news")->fetchColumn();
echo "Total in match_news: $total" . PHP_EOL;

// Matches mit News zählen
$matchesWithNews = $pdo->query("SELECT COUNT(DISTINCT match_id) FROM match_news")->fetchColumn();
echo "Matches mit Spielberichten: $matchesWithNews" . PHP_EOL;

// Beispiele anzeigen
echo PHP_EOL . "Beispiel-Verknüpfungen (höchste Confidence):" . PHP_EOL;
$examples = $pdo->query("
    SELECT m.match_date, t1.name as home, t2.name as guest, n.title, mn.confidence
    FROM match_news mn
    JOIN matches m ON mn.match_id = m.id
    JOIN teams t1 ON m.home_team_id = t1.id
    JOIN teams t2 ON m.guest_team_id = t2.id
    JOIN news n ON mn.news_id = n.id
    ORDER BY mn.confidence DESC
    LIMIT 5
")->fetchAll();

foreach ($examples as $ex) {
    echo "  [" . round($ex['confidence'] * 100) . "%] " . $ex['home'] . " vs " . $ex['guest'] . PHP_EOL;
    echo "       -> " . substr($ex['title'], 0, 60) . "..." . PHP_EOL;
}

echo PHP_EOL . "=== Verknüpfung abgeschlossen! ===" . PHP_EOL;
