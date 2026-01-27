<?php

class LmoDatabase
{
    private static $pdo;

    public static function getInstance()
    {
        if (self::$pdo === null) {
            $dbPath = __DIR__ . '/../data/database.sqlite';
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            try {
                self::$pdo = new PDO('sqlite:' . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Enable foreign keys
                self::$pdo->exec("PRAGMA foreign_keys = ON;");

            } catch (PDOException $e) {
                die("Database connection failed: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    public static function createSchema()
    {
        $pdo = self::getInstance();

        $pdo->exec("CREATE TABLE IF NOT EXISTS leagues (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file TEXT UNIQUE,
            name TEXT,
            options TEXT
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            league_id INTEGER,
            original_id INTEGER,
            name TEXT,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )");

        // Dropping matches to ensure schema update if it exists (simple migration strategy for now)
        // OR we execute ALTER TABLE and ignore errors. 
        // Given we re-migrate data anyway, recreating is cleaner.
        $pdo->exec("DROP TABLE IF EXISTS matches");

        $pdo->exec("CREATE TABLE matches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            league_id INTEGER,
            round_nr INTEGER,
            home_team_id INTEGER,
            guest_team_id INTEGER,
            home_goals INTEGER,
            guest_goals INTEGER,
            match_date TEXT, -- New
            match_time TEXT, -- New
            match_note TEXT,
            report_url TEXT,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY(home_team_id) REFERENCES teams(id),
            FOREIGN KEY(guest_team_id) REFERENCES teams(id)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_league ON matches(league_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_round ON matches(league_id, round_nr);");

        // News Table - erweitert für Spielberichte
        $pdo->exec("DROP TABLE IF EXISTS news");
        $pdo->exec("CREATE TABLE news (
            id INTEGER PRIMARY KEY,
            title TEXT,
            short_content TEXT,
            content TEXT,
            author TEXT,
            email TEXT,
            timestamp INTEGER,
            match_date TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_timestamp ON news(timestamp);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_match_date ON news(match_date);");

        // Verknüpfungstabelle: News <-> Matches
        $pdo->exec("DROP TABLE IF EXISTS match_news");
        $pdo->exec("CREATE TABLE match_news (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id INTEGER NOT NULL,
            news_id INTEGER NOT NULL,
            confidence REAL DEFAULT 1.0,
            FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY(news_id) REFERENCES news(id) ON DELETE CASCADE,
            UNIQUE(match_id, news_id)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_news_match ON match_news(match_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_news_news ON match_news(news_id);");
    }
}
