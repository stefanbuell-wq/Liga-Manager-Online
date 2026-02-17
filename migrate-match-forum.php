<?php
/**
 * Migration: Liga-Manager <-> Forum Integration
 *
 * Fügt neue Spalten und Tabellen für die Verknüpfung hinzu.
 * Kann mehrfach ausgeführt werden (idempotent).
 */

require_once __DIR__ . '/lib/LmoDatabase.php';

echo "=== Liga-Manager <-> Forum Integration ===\n\n";

try {
    // Schema-Migration ausführen
    echo "1. Schema-Migration wird ausgeführt...\n";
    LmoDatabase::migrateSchema();
    echo "   OK: Schema aktualisiert\n\n";

    $pdo = LmoDatabase::getInstance();

    // Prüfen welche Tabellen/Spalten existieren
    echo "2. Überprüfe Datenbankstruktur...\n";

    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    echo "   Tabellen: " . implode(', ', $tables) . "\n";

    // forum_categories Spalten prüfen
    $columns = $pdo->query("PRAGMA table_info(forum_categories)")->fetchAll();
    $colNames = array_column($columns, 'name');
    echo "   forum_categories Spalten: " . implode(', ', $colNames) . "\n";

    // forum_topics Spalten prüfen
    $columns = $pdo->query("PRAGMA table_info(forum_topics)")->fetchAll();
    $colNames = array_column($columns, 'name');
    echo "   forum_topics Spalten: " . implode(', ', $colNames) . "\n";

    // match_forum_links prüfen
    if (in_array('match_forum_links', $tables)) {
        $columns = $pdo->query("PRAGMA table_info(match_forum_links)")->fetchAll();
        $colNames = array_column($columns, 'name');
        echo "   match_forum_links Spalten: " . implode(', ', $colNames) . "\n";
    }

    echo "\n3. Statistiken:\n";

    // Kategorien
    $catCount = $pdo->query("SELECT COUNT(*) FROM forum_categories")->fetchColumn();
    echo "   Forum-Kategorien: $catCount\n";

    // Topics
    $topicCount = $pdo->query("SELECT COUNT(*) FROM forum_topics")->fetchColumn();
    echo "   Forum-Topics: $topicCount\n";

    // Posts
    $postCount = $pdo->query("SELECT COUNT(*) FROM forum_posts")->fetchColumn();
    echo "   Forum-Posts: $postCount\n";

    // Ligen
    $leagueCount = $pdo->query("SELECT COUNT(*) FROM leagues")->fetchColumn();
    echo "   Ligen: $leagueCount\n";

    // Matches
    $matchCount = $pdo->query("SELECT COUNT(*) FROM matches")->fetchColumn();
    echo "   Spiele: $matchCount\n";

    // Verknüpfungen
    $linkCount = $pdo->query("SELECT COUNT(*) FROM match_forum_links")->fetchColumn();
    echo "   Match-Forum-Verknüpfungen: $linkCount\n";

    echo "\n=== Migration erfolgreich! ===\n";

} catch (Exception $e) {
    echo "FEHLER: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
