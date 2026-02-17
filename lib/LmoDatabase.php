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

                // SQLite Performance Optimierungen
                self::$pdo->exec("PRAGMA journal_mode = WAL;");      // Write-Ahead Logging
                self::$pdo->exec("PRAGMA synchronous = NORMAL;");    // Schnellere Writes
                self::$pdo->exec("PRAGMA cache_size = -64000;");     // 64MB Cache
                self::$pdo->exec("PRAGMA temp_store = MEMORY;");     // Temp-Tabellen im RAM
                self::$pdo->exec("PRAGMA mmap_size = 268435456;");   // 256MB Memory-Mapped I/O
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
            short_name TEXT,
            logo_file TEXT,
            match_count INTEGER DEFAULT 0,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_teams_name_nocase ON teams(name COLLATE NOCASE);");
        try { $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS teams_fts USING fts5(name, short_name, content='teams', content_rowid='id')"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_ai AFTER INSERT ON teams BEGIN INSERT INTO teams_fts(rowid, name, short_name) VALUES (new.id, new.name, new.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_au AFTER UPDATE ON teams BEGIN INSERT INTO teams_fts(teams_fts, rowid, name, short_name) VALUES('delete', old.id, old.name, old.short_name); INSERT INTO teams_fts(rowid, name, short_name) VALUES (new.id, new.name, new.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_ad AFTER DELETE ON teams BEGIN INSERT INTO teams_fts(teams_fts, rowid, name, short_name) VALUES('delete', old.id, old.name, old.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("INSERT INTO teams_fts(teams_fts) VALUES('rebuild')"); } catch (Exception $e) {}

        // Neue Tabelle: Spieler
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

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_players_team ON players(team_id);");

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
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_date ON matches(match_date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_home_date ON matches(home_team_id, match_date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_guest_date ON matches(guest_team_id, match_date);");

        // Neue Tabelle: Match Events (Tore, Karten, etc.)
        $pdo->exec("CREATE TABLE IF NOT EXISTS match_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id INTEGER NOT NULL,
            event_type TEXT NOT NULL, -- 'goal', 'yellow_card', 'red_card', 'assist'
            player_id INTEGER,
            player_name TEXT, -- Fallback wenn Spieler nicht in DB
            team_id INTEGER NOT NULL,
            minute INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY(player_id) REFERENCES players(id),
            FOREIGN KEY(team_id) REFERENCES teams(id)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_match ON match_events(match_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_player ON match_events(player_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_events_type ON match_events(event_type);");

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
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_title ON news(title);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_author ON news(author);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_title_nocase ON news(title COLLATE NOCASE);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_author_nocase ON news(author COLLATE NOCASE);");
        try { $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS news_fts USING fts5(title, short_content, content, author, content='news', content_rowid='id')"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ai AFTER INSERT ON news BEGIN INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_au AFTER UPDATE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ad AFTER DELETE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("INSERT INTO news_fts(news_fts) VALUES('rebuild')"); } catch (Exception $e) {}

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

        // Forum-Tabellen erstellen
        self::createForumSchema();
    }

    /**
     * Forum-Tabellen erstellen (ohne bestehende Daten zu löschen)
     */
    public static function createForumSchema()
    {
        $pdo = self::getInstance();

        // Forum-Kategorien (entspricht Boards)
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            sort_order INTEGER DEFAULT 0,
            topic_count INTEGER DEFAULT 0,
            post_count INTEGER DEFAULT 0,
            last_post_id INTEGER,
            league_id INTEGER,
            view_permission TEXT DEFAULT 'public',
            reply_permission TEXT DEFAULT 'registered',
            create_permission TEXT DEFAULT 'registered',
            show_on_homepage INTEGER DEFAULT 0,
            is_archived INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_categories_order ON forum_categories(sort_order);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_categories_league ON forum_categories(league_id);");

        // Forum-Topics (Threads)
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_topics (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            user_id INTEGER,
            title TEXT NOT NULL,
            is_sticky INTEGER DEFAULT 0,
            is_locked INTEGER DEFAULT 0,
            view_count INTEGER DEFAULT 0,
            post_count INTEGER DEFAULT 0,
            last_post_id INTEGER,
            last_post_at DATETIME,
            last_post_user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(category_id) REFERENCES forum_categories(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_category ON forum_topics(category_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_user ON forum_topics(user_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_sticky ON forum_topics(is_sticky, last_post_at);");

        // Forum-Posts (Beiträge)
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic_id INTEGER NOT NULL,
            user_id INTEGER,
            guest_name TEXT,
            guest_email TEXT,
            content TEXT NOT NULL,
            is_first_post INTEGER DEFAULT 0,
            edited_at DATETIME,
            edited_by INTEGER,
            ip_address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_posts_topic ON forum_posts(topic_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_posts_user ON forum_posts(user_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_posts_created ON forum_posts(created_at);");

        // Legacy-Mapping für SMF-Migration
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_legacy_map (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            entity_type TEXT NOT NULL,
            old_id INTEGER NOT NULL,
            new_id INTEGER NOT NULL,
            UNIQUE(entity_type, old_id)
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_legacy_type ON forum_legacy_map(entity_type, old_id);");

        // Liga-Manager <-> Forum Verknüpfung
        self::createMatchForumSchema();
    }

    /**
     * Verknüpfungstabellen Liga-Manager <-> Forum erstellen
     */
    public static function createMatchForumSchema()
    {
        $pdo = self::getInstance();

        // Verknüpfungstabelle: Matches/Spieltage <-> Forum-Topics
        $pdo->exec("CREATE TABLE IF NOT EXISTS match_forum_links (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            league_id INTEGER,
            round_nr INTEGER,
            match_id INTEGER,
            forum_topic_id INTEGER NOT NULL,
            link_type TEXT DEFAULT 'discussion',
            auto_generated INTEGER DEFAULT 0,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE,
            FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE,
            FOREIGN KEY(forum_topic_id) REFERENCES forum_topics(id) ON DELETE CASCADE,
            FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
        )");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_forum_league ON match_forum_links(league_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_forum_round ON match_forum_links(league_id, round_nr);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_forum_match ON match_forum_links(match_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_match_forum_topic ON match_forum_links(forum_topic_id);");
    }

    /**
     * Schema-Migration: Neue Spalten zu bestehenden Tabellen hinzufügen
     * Diese Methode kann mehrfach aufgerufen werden (idempotent)
     */
    public static function migrateSchema()
    {
        $pdo = self::getInstance();

        // Helper: Prüfen ob Spalte existiert
        $hasColumn = function($table, $column) use ($pdo) {
            $result = $pdo->query("PRAGMA table_info($table)")->fetchAll();
            foreach ($result as $col) {
                if ($col['name'] === $column) return true;
            }
            return false;
        };

        // forum_categories erweitern
        if (!$hasColumn('forum_categories', 'league_id')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN league_id INTEGER");
        }
        if (!$hasColumn('forum_categories', 'view_permission')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN view_permission TEXT DEFAULT 'public'");
        }
        if (!$hasColumn('forum_categories', 'reply_permission')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN reply_permission TEXT DEFAULT 'registered'");
        }
        if (!$hasColumn('forum_categories', 'create_permission')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN create_permission TEXT DEFAULT 'registered'");
        }
        if (!$hasColumn('forum_categories', 'show_on_homepage')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN show_on_homepage INTEGER DEFAULT 0");
        }
        if (!$hasColumn('forum_categories', 'is_archived')) {
            $pdo->exec("ALTER TABLE forum_categories ADD COLUMN is_archived INTEGER DEFAULT 0");
        }

        // forum_topics erweitern für Liga-Verknüpfung
        if (!$hasColumn('forum_topics', 'league_id')) {
            $pdo->exec("ALTER TABLE forum_topics ADD COLUMN league_id INTEGER");
        }
        if (!$hasColumn('forum_topics', 'round_nr')) {
            $pdo->exec("ALTER TABLE forum_topics ADD COLUMN round_nr INTEGER");
        }
        if (!$hasColumn('forum_topics', 'match_id')) {
            $pdo->exec("ALTER TABLE forum_topics ADD COLUMN match_id INTEGER");
        }
        if (!$hasColumn('forum_topics', 'topic_type')) {
            $pdo->exec("ALTER TABLE forum_topics ADD COLUMN topic_type TEXT DEFAULT 'discussion'");
        }

        // match_forum_links Tabelle erstellen falls nicht vorhanden
        self::createMatchForumSchema();

        // Indizes für neue Spalten
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_league ON forum_topics(league_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_match ON forum_topics(match_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_topics_round ON forum_topics(league_id, round_nr);");

        // Zusätzliche Indizes für bestehende Tabellen (idempotent)
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_date ON matches(match_date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_home_date ON matches(home_team_id, match_date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_matches_guest_date ON matches(guest_team_id, match_date);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_title ON news(title);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_author ON news(author);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_teams_name_nocase ON teams(name COLLATE NOCASE);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_title_nocase ON news(title COLLATE NOCASE);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_author_nocase ON news(author COLLATE NOCASE);");
        try { $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS teams_fts USING fts5(name, short_name, content='teams', content_rowid='id')"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS news_fts USING fts5(title, short_content, content, author, content='news', content_rowid='id')"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_ai AFTER INSERT ON teams BEGIN INSERT INTO teams_fts(rowid, name, short_name) VALUES (new.id, new.name, new.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_au AFTER UPDATE ON teams BEGIN INSERT INTO teams_fts(teams_fts, rowid, name, short_name) VALUES('delete', old.id, old.name, old.short_name); INSERT INTO teams_fts(rowid, name, short_name) VALUES (new.id, new.name, new.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS teams_ad AFTER DELETE ON teams BEGIN INSERT INTO teams_fts(teams_fts, rowid, name, short_name) VALUES('delete', old.id, old.name, old.short_name); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ai AFTER INSERT ON news BEGIN INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_au AFTER UPDATE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); INSERT INTO news_fts(rowid, title, short_content, content, author) VALUES (new.id, new.title, new.short_content, new.content, new.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("CREATE TRIGGER IF NOT EXISTS news_ad AFTER DELETE ON news BEGIN INSERT INTO news_fts(news_fts, rowid, title, short_content, content, author) VALUES('delete', old.id, old.title, old.short_content, old.content, old.author); END"); } catch (Exception $e) {}
        try { $pdo->exec("INSERT INTO teams_fts(teams_fts) VALUES('rebuild')"); } catch (Exception $e) {}
        try { $pdo->exec("INSERT INTO news_fts(news_fts) VALUES('rebuild')"); } catch (Exception $e) {}
    }
}
