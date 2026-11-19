<?php
/**
 * Kommentare unter Artikeln (zuschaltbar: Admin -> Einstellungen -> Zusatzfunktionen)
 *
 * Einstellung app_settings.feature_comments:
 *   'off'      – keine Kommentare (Standard)
 *   'open'     – Kommentare erscheinen sofort
 *   'approval' – Kommentare erscheinen erst nach Freigabe durch die Redaktion
 * Pro Artikel (news_comment_mode): 'open' (Standard), 'closed' (alte bleiben sichtbar), 'off' (ausgeblendet)
 *
 * Nur angemeldete, aktive Mitglieder kommentieren. Gesperrte Nutzer (user_blocks, scope 'comments') nicht.
 */
require_once __DIR__ . '/LmoDatabase.php';
require_once __DIR__ . '/SettingsRepository.php';

class CommentService
{
    public const MODES = ['off', 'open', 'approval'];
    public const ARTICLE_MODES = ['open', 'closed', 'off'];
    public const MAX_LEN = 2000;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS news_comments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            news_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'visible',   -- visible | pending | hidden
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            moderated_by INTEGER,
            moderated_at DATETIME
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_comments_news ON news_comments(news_id, status)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_news_comments_user ON news_comments(user_id, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS news_comment_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            comment_id INTEGER NOT NULL,
            reporter_id INTEGER NOT NULL,
            reason TEXT,
            status TEXT NOT NULL DEFAULT 'open',      -- open | done
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (comment_id, reporter_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS news_comment_mode (
            news_id INTEGER PRIMARY KEY,
            mode TEXT NOT NULL,
            changed_by INTEGER,
            changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        self::ensureBlocks($pdo);
    }

    /** Sperren fuer Mitmach-Funktionen (scope: comments | live) */
    public static function ensureBlocks(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_blocks (
            user_id INTEGER NOT NULL,
            scope TEXT NOT NULL,
            blocked_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, scope)
        )");
    }

    public static function isBlocked(PDO $pdo, int $userId, string $scope): bool
    {
        self::ensureBlocks($pdo);
        $st = $pdo->prepare("SELECT 1 FROM user_blocks WHERE user_id = ? AND scope = ?");
        $st->execute([$userId, $scope]);
        return (bool)$st->fetchColumn();
    }

    public static function setBlocked(PDO $pdo, int $userId, string $scope, bool $blocked, ?int $by): void
    {
        self::ensureBlocks($pdo);
        if ($blocked) {
            $pdo->prepare("INSERT OR IGNORE INTO user_blocks (user_id, scope, blocked_by) VALUES (?, ?, ?)")->execute([$userId, $scope, $by]);
        } else {
            $pdo->prepare("DELETE FROM user_blocks WHERE user_id = ? AND scope = ?")->execute([$userId, $scope]);
        }
    }

    public static function blockedUsers(PDO $pdo, string $scope): array
    {
        self::ensureBlocks($pdo);
        $st = $pdo->prepare("SELECT b.user_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, b.created_at
            FROM user_blocks b LEFT JOIN users u ON u.id = b.user_id WHERE b.scope = ? ORDER BY b.created_at DESC");
        $st->execute([$scope]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Aktives Mitglied (nicht gesperrt, nicht deaktiviert)? */
    public static function activeMember(PDO $pdo, int $userId): bool
    {
        $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        $sql = "SELECT 1 FROM users WHERE id = ? AND COALESCE(active, 1) = 1" . (in_array('status', $cols, true) ? " AND COALESCE(status, 'active') = 'active'" : '');
        $st = $pdo->prepare($sql);
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    }

    public static function globalMode(): string
    {
        $v = (string)(new SettingsRepository())->get('feature_comments');
        return in_array($v, self::MODES, true) ? $v : 'off';
    }

    public function articleMode(int $newsId): string
    {
        $st = $this->pdo->prepare("SELECT mode FROM news_comment_mode WHERE news_id = ?");
        $st->execute([$newsId]);
        $m = $st->fetchColumn();
        return in_array($m, self::ARTICLE_MODES, true) ? $m : 'open';
    }

    public function setArticleMode(int $newsId, string $mode, ?int $by): void
    {
        if (!in_array($mode, self::ARTICLE_MODES, true)) throw new InvalidArgumentException('Ungültiger Modus.');
        if (!$this->newsExists($newsId)) throw new InvalidArgumentException('Artikel nicht gefunden.');
        $this->pdo->prepare("INSERT INTO news_comment_mode (news_id, mode, changed_by, changed_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(news_id) DO UPDATE SET mode = excluded.mode, changed_by = excluded.changed_by, changed_at = CURRENT_TIMESTAMP")
            ->execute([$newsId, $mode, $by]);
    }

    private function newsExists(int $newsId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM news WHERE id = ?");
        $st->execute([$newsId]);
        return (bool)$st->fetchColumn();
    }

    /**
     * Kommentare eines Artikels fuer die Anzeige.
     * Wartende und ausgeblendete sieht nur die Redaktion; wartende eigene sieht der Verfasser.
     */
    public function forArticle(int $newsId, ?int $userId, bool $moderator): array
    {
        $st = $this->pdo->prepare("SELECT c.id, c.user_id, c.content, c.status, c.created_at,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unbekannt') AS name, u.avatar,
                (SELECT COUNT(*) FROM news_comment_reports r WHERE r.comment_id = c.id AND r.status = 'open') AS reports
            FROM news_comments c LEFT JOIN users u ON u.id = c.user_id
            WHERE c.news_id = ? ORDER BY c.created_at, c.id");
        $st->execute([$newsId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $own = $userId && (int)$r['user_id'] === $userId;
            if ($r['status'] !== 'visible' && !$moderator && !($own && $r['status'] === 'pending')) continue;
            $row = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'avatar' => $r['avatar'] ?: null,
                'content' => $r['content'], 'created_at' => $r['created_at'], 'status' => $r['status'], 'own' => $own,
            ];
            if ($moderator) { $row['user_id'] = (int)$r['user_id']; $row['reports'] = (int)$r['reports']; }
            $out[] = $row;
        }
        return $out;
    }

    /** @return array{id:int, status:string} */
    public function add(int $newsId, int $userId, string $content): array
    {
        $mode = self::globalMode();
        if ($mode === 'off') throw new InvalidArgumentException('Kommentare sind ausgeschaltet.');
        if (!$this->newsExists($newsId)) throw new InvalidArgumentException('Artikel nicht gefunden.');
        if ($this->articleMode($newsId) !== 'open') throw new InvalidArgumentException('Für diesen Artikel sind Kommentare geschlossen.');
        if (!self::activeMember($this->pdo, $userId)) throw new InvalidArgumentException('Dein Konto ist nicht freigeschaltet.');
        if (self::isBlocked($this->pdo, $userId, 'comments')) throw new InvalidArgumentException('Du kannst zurzeit nicht kommentieren.');

        $content = trim(str_replace("\r\n", "\n", $content));
        $content = preg_replace("/\n{3,}/", "\n\n", $content);
        if (mb_strlen($content) < 2) throw new InvalidArgumentException('Der Kommentar ist zu kurz.');
        if (mb_strlen($content) > self::MAX_LEN) throw new InvalidArgumentException('Der Kommentar ist zu lang (höchstens ' . self::MAX_LEN . ' Zeichen).');

        // Bremse: 30 s Abstand, hoechstens 20 je Stunde
        $st = $this->pdo->prepare("SELECT
                SUM(created_at >= datetime('now', '-30 seconds')), SUM(created_at >= datetime('now', '-1 hour'))
            FROM news_comments WHERE user_id = ?");
        $st->execute([$userId]);
        [$recent, $hour] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        if ($recent > 0) throw new InvalidArgumentException('Bitte einen Moment warten, bevor du erneut kommentierst.');
        if ($hour >= 20) throw new InvalidArgumentException('Zu viele Kommentare in kurzer Zeit. Bitte später erneut versuchen.');

        $status = $mode === 'approval' ? 'pending' : 'visible';
        $this->pdo->prepare("INSERT INTO news_comments (news_id, user_id, content, status) VALUES (?, ?, ?, ?)")
            ->execute([$newsId, $userId, $content, $status]);
        return ['id' => (int)$this->pdo->lastInsertId(), 'status' => $status];
    }

    public function get(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM news_comments WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Eigenen Kommentar loeschen (Verfasser) oder beliebigen (Redaktion) */
    public function delete(int $id, int $userId, bool $moderator): void
    {
        $c = $this->get($id);
        if (!$c) throw new InvalidArgumentException('Kommentar nicht gefunden.');
        if (!$moderator && (int)$c['user_id'] !== $userId) throw new InvalidArgumentException('Nicht erlaubt.');
        $this->pdo->prepare("DELETE FROM news_comment_reports WHERE comment_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM news_comments WHERE id = ?")->execute([$id]);
    }

    /** Freigeben / ausblenden (Redaktion); erledigt offene Meldungen */
    public function setStatus(int $id, string $status, int $by): void
    {
        if (!in_array($status, ['visible', 'hidden'], true)) throw new InvalidArgumentException('Ungültiger Status.');
        if (!$this->get($id)) throw new InvalidArgumentException('Kommentar nicht gefunden.');
        $this->pdo->prepare("UPDATE news_comments SET status = ?, moderated_by = ?, moderated_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$status, $by, $id]);
        $this->pdo->prepare("UPDATE news_comment_reports SET status = 'done' WHERE comment_id = ?")->execute([$id]);
    }

    /** Meldung; false, wenn schon gemeldet */
    public function report(int $id, int $userId, string $reason): bool
    {
        $c = $this->get($id);
        if (!$c || $c['status'] !== 'visible') throw new InvalidArgumentException('Kommentar nicht gefunden.');
        if ((int)$c['user_id'] === $userId) throw new InvalidArgumentException('Eigene Kommentare kannst du löschen.');
        $reason = mb_substr(trim($reason), 0, 300);
        $st = $this->pdo->prepare("INSERT OR IGNORE INTO news_comment_reports (comment_id, reporter_id, reason) VALUES (?, ?, ?)");
        $st->execute([$id, $userId, $reason !== '' ? $reason : null]);
        return $st->rowCount() > 0;
    }

    /** Liste fuer die Redaktion: pending | reported | all */
    public function moderationList(string $filter, int $limit = 100): array
    {
        $where = match ($filter) {
            'pending' => "c.status = 'pending'",
            'reported' => "EXISTS (SELECT 1 FROM news_comment_reports r WHERE r.comment_id = c.id AND r.status = 'open')",
            'hidden' => "c.status = 'hidden'",
            default => '1',
        };
        $st = $this->pdo->prepare("SELECT c.id, c.news_id, c.user_id, c.content, c.status, c.created_at, n.title,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unbekannt') AS name,
                (SELECT COUNT(*) FROM news_comment_reports r WHERE r.comment_id = c.id AND r.status = 'open') AS reports,
                (SELECT GROUP_CONCAT(r.reason, ' / ') FROM news_comment_reports r WHERE r.comment_id = c.id AND r.status = 'open') AS reasons,
                EXISTS (SELECT 1 FROM user_blocks b WHERE b.user_id = c.user_id AND b.scope = 'comments') AS blocked
            FROM news_comments c LEFT JOIN users u ON u.id = c.user_id LEFT JOIN news n ON n.id = c.news_id
            WHERE $where ORDER BY c.created_at DESC LIMIT " . max(1, min(500, $limit)));
        $st->execute();
        return array_map(function ($r) {
            foreach (['id', 'news_id', 'user_id', 'reports', 'blocked'] as $k) $r[$k] = (int)$r[$k];
            return $r;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    }

    public function counts(): array
    {
        $r = $this->pdo->query("SELECT
                (SELECT COUNT(*) FROM news_comments WHERE status = 'pending'),
                (SELECT COUNT(DISTINCT comment_id) FROM news_comment_reports WHERE status = 'open')")->fetch(PDO::FETCH_NUM);
        return ['pending' => (int)$r[0], 'reported' => (int)$r[1]];
    }

    /** Artikel mit abweichendem Kommentar-Modus */
    public function articleModes(): array
    {
        return $this->pdo->query("SELECT m.news_id, m.mode, n.title FROM news_comment_mode m LEFT JOIN news n ON n.id = m.news_id
            WHERE m.mode <> 'open' ORDER BY m.changed_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Kommentarzahlen fuer Artikellisten */
    public function visibleCounts(array $newsIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $newsIds)));
        if (!$ids) return [];
        $st = $this->pdo->prepare("SELECT news_id, COUNT(*) FROM news_comments WHERE status = 'visible' AND news_id IN (" .
            implode(',', array_fill(0, count($ids), '?')) . ") GROUP BY news_id");
        $st->execute($ids);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }
}
