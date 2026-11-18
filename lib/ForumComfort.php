<?php
require_once __DIR__ . '/Instance.php';
/**
 * Forum-Komfort: Ungelesen-Markierung und Themen-Abos mit E-Mail-Benachrichtigung.
 *
 * Tabellen (Zeiten UTC wie ueberall im Forum):
 *   forum_user_state(user_id, read_all_at)        – "Alles gelesen" bzw. Startpunkt beim ersten Besuch
 *   forum_reads(user_id, topic_id, read_at)        – zuletzt gelesen je Thema
 *   forum_subscriptions(user_id, topic_id, notified, created_at)
 *       notified = 1: seit dem letzten Besuch schon eine Mail geschickt -> keine weitere bis zum naechsten Besuch
 *
 * Ungelesen = last_post_at > max(read_at, read_all_at) und letzter Beitrag nicht vom Nutzer selbst.
 */

require_once __DIR__ . '/LmoDatabase.php';

class ForumComfort
{
    private const MAX_MAILS_PER_POST = 50;

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_user_state (
            user_id INTEGER PRIMARY KEY,
            read_all_at TEXT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_reads (
            user_id INTEGER NOT NULL,
            topic_id INTEGER NOT NULL,
            read_at TEXT NOT NULL,
            PRIMARY KEY (user_id, topic_id)
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_subscriptions (
            user_id INTEGER NOT NULL,
            topic_id INTEGER NOT NULL,
            notified INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, topic_id)
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_forum_subscriptions_topic ON forum_subscriptions(topic_id)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS forum_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            post_id INTEGER NOT NULL,
            reporter_id INTEGER NOT NULL,
            reason TEXT,
            status TEXT NOT NULL DEFAULT 'open',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            handled_by INTEGER,
            handled_at TEXT,
            UNIQUE (post_id, reporter_id)
        )");
        $done = true;
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /* ---------- Gelesen / Ungelesen ---------- */

    /** Startpunkt des Nutzers; beim ersten Aufruf = jetzt (sonst waere das ganze Altforum "neu") */
    public function readAllAt(int $userId): string
    {
        $st = $this->pdo->prepare("SELECT read_all_at FROM forum_user_state WHERE user_id = ?");
        $st->execute([$userId]);
        $v = $st->fetchColumn();
        if ($v) return $v;
        $now = self::now();
        $this->pdo->prepare("INSERT OR IGNORE INTO forum_user_state (user_id, read_all_at) VALUES (?, ?)")->execute([$userId, $now]);
        return $now;
    }

    /** Ergaenzt Themenlisten um unread (bool) */
    public function annotateTopics(array $topics, int $userId): array
    {
        if (!$topics) return $topics;
        $base = $this->readAllAt($userId);
        $ids = array_map(fn($t) => (int)$t['id'], $topics);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $this->pdo->prepare("SELECT topic_id, read_at FROM forum_reads WHERE user_id = ? AND topic_id IN ($in)");
        $st->execute(array_merge([$userId], $ids));
        $reads = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($topics as &$t) {
            $cut = max($base, $reads[$t['id']] ?? '');
            $last = (string)($t['last_post_at'] ?? $t['created_at'] ?? '');
            $t['unread'] = $last !== '' && $last > $cut && (int)($t['last_post_user_id'] ?? 0) !== $userId;
        }
        unset($t);
        return $topics;
    }

    /**
     * Forum-Startseite: ungelesene Themen je Kategorie und die neuesten davon
     * (nur in den uebergebenen, fuer den Nutzer sichtbaren Kategorien)
     * @return array{counts: array<int,int>, topics: array}
     */
    public function unreadOverview(int $userId, array $categoryIds, int $limit = 10): array
    {
        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));
        if (!$categoryIds) return ['counts' => [], 'topics' => []];
        $base = $this->readAllAt($userId);
        $in = implode(',', $categoryIds); // nur Ganzzahlen
        $where = "t.category_id IN ($in)
              AND t.last_post_at > :base
              AND t.last_post_at > COALESCE(r.read_at, '')
              AND COALESCE(t.last_post_user_id, 0) <> :uid";
        $from = "FROM forum_topics t
            LEFT JOIN forum_reads r ON r.topic_id = t.id AND r.user_id = :uid";

        $st = $this->pdo->prepare("SELECT t.category_id, COUNT(*) $from WHERE $where GROUP BY t.category_id");
        $st->execute([':uid' => $userId, ':base' => $base]);
        $counts = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$cat, $n]) $counts[(int)$cat] = (int)$n;

        $topics = [];
        if ($counts) {
            $st = $this->pdo->prepare("SELECT t.id, t.title, t.category_id, t.post_count, t.last_post_at,
                       c.name AS category_name, lu.username AS last_post_author, lu.display_name AS last_post_display_name
                $from
                LEFT JOIN forum_categories c ON c.id = t.category_id
                LEFT JOIN users lu ON lu.id = t.last_post_user_id
                WHERE $where
                ORDER BY t.last_post_at DESC LIMIT " . max(1, min(50, $limit)));
            $st->execute([':uid' => $userId, ':base' => $base]);
            $topics = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        return ['counts' => $counts, 'topics' => $topics];
    }

    /**
     * Thema wird angesehen: bisherigen Lesestand zurueckgeben (fuer "Neu"-Markierung),
     * danach als gelesen speichern und Abo-Sperre aufheben.
     */
    public function markTopicRead(int $userId, int $topicId): string
    {
        $base = $this->readAllAt($userId);
        $st = $this->pdo->prepare("SELECT read_at FROM forum_reads WHERE user_id = ? AND topic_id = ?");
        $st->execute([$userId, $topicId]);
        $prev = max($base, (string)$st->fetchColumn());
        $this->pdo->prepare("INSERT INTO forum_reads (user_id, topic_id, read_at) VALUES (?, ?, ?)
            ON CONFLICT(user_id, topic_id) DO UPDATE SET read_at = excluded.read_at")->execute([$userId, $topicId, self::now()]);
        $this->pdo->prepare("UPDATE forum_subscriptions SET notified = 0 WHERE user_id = ? AND topic_id = ?")->execute([$userId, $topicId]);
        return $prev;
    }

    /** Anzahl Beitraege bis einschliesslich Lesestand (fuer den Sprung zur ersten ungelesenen Seite) */
    public function countReadPosts(int $topicId, string $readSince): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM forum_posts WHERE topic_id = ? AND created_at <= ?");
        $st->execute([$topicId, $readSince]);
        return (int)$st->fetchColumn();
    }

    public function markAllRead(int $userId): void
    {
        $now = self::now();
        $this->readAllAt($userId);
        $this->pdo->prepare("UPDATE forum_user_state SET read_all_at = ? WHERE user_id = ?")->execute([$now, $userId]);
        $this->pdo->prepare("DELETE FROM forum_reads WHERE user_id = ?")->execute([$userId]);
        $this->pdo->prepare("UPDATE forum_subscriptions SET notified = 0 WHERE user_id = ?")->execute([$userId]);
    }

    /* ---------- Abos ---------- */

    public function isSubscribed(int $userId, int $topicId): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM forum_subscriptions WHERE user_id = ? AND topic_id = ?");
        $st->execute([$userId, $topicId]);
        return (bool)$st->fetchColumn();
    }

    public function subscribe(int $userId, int $topicId): void
    {
        $this->pdo->prepare("INSERT OR IGNORE INTO forum_subscriptions (user_id, topic_id) VALUES (?, ?)")->execute([$userId, $topicId]);
    }

    public function unsubscribe(int $userId, int $topicId): void
    {
        $this->pdo->prepare("DELETE FROM forum_subscriptions WHERE user_id = ? AND topic_id = ?")->execute([$userId, $topicId]);
    }

    /** Signierter Abmeldelink (kein Login noetig) */
    public function unsubscribeUrl(int $userId, int $topicId): string
    {
        return $this->baseUrl() . '/api/forum-abo.php?u=' . $userId . '&t=' . $topicId . '&s=' . $this->sign($userId, $topicId);
    }

    public function verify(int $userId, int $topicId, string $sig): bool
    {
        return hash_equals($this->sign($userId, $topicId), $sig);
    }

    private function sign(int $userId, int $topicId): string
    {
        return substr(hash_hmac('sha256', "abo:$userId:$topicId", $this->secret()), 0, 32);
    }

    private function secret(): string
    {
        require_once __DIR__ . '/SettingsRepository.php';
        $s = new SettingsRepository();
        $v = $s->get('forum_abo_secret');
        if ($v === '') {
            $v = bin2hex(random_bytes(32));
            $s->set('forum_abo_secret', $v);
        }
        return $v;
    }

    private function baseUrl(): string
    {
        $file = __DIR__ . '/../config/mail.php';
        $cfg = file_exists($file) ? (require $file) : [];
        return Instance::baseUrl($cfg['base_url'] ?? null);
    }

    /**
     * Nach einer neuen Antwort: Abonnenten (ausser dem Autor) benachrichtigen,
     * die seit ihrem letzten Besuch noch keine Mail zu diesem Thema bekommen haben.
     * Fehler beim Versand bremsen das Antworten nicht.
     */
    public function notifyNewPost(int $topicId, int $postId, ?int $authorId): int
    {
        try {
            $st = $this->pdo->prepare("SELECT s.user_id, u.email, COALESCE(NULLIF(u.display_name, ''), u.username) AS name
                FROM forum_subscriptions s JOIN users u ON u.id = s.user_id
                WHERE s.topic_id = ? AND s.notified = 0 AND s.user_id <> ?
                  AND u.email IS NOT NULL AND u.email <> '' AND u.active = 1 AND COALESCE(u.status, 'active') = 'active'
                LIMIT " . self::MAX_MAILS_PER_POST);
            $st->execute([$topicId, (int)$authorId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) return 0;

            $p = $this->pdo->prepare("SELECT p.content, t.title, COALESCE(NULLIF(u.display_name, ''), u.username, p.guest_name, 'Jemand') AS author
                FROM forum_posts p JOIN forum_topics t ON t.id = p.topic_id LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?");
            $p->execute([$postId]);
            $post = $p->fetch(PDO::FETCH_ASSOC);
            if (!$post) return 0;

            $text = self::plainText((string)$post['content']);
            if (mb_strlen($text) > 400) $text = mb_substr($text, 0, 397) . '…';
            $link = $this->baseUrl() . '/forum.html?topic=' . $topicId . '&unread=1';

            require_once __DIR__ . '/Mailer.php';
            $done = $this->pdo->prepare("UPDATE forum_subscriptions SET notified = 1 WHERE user_id = ? AND topic_id = ?");
            $n = 0;
            foreach ($rows as $r) {
                $body = "Hallo {$r['name']},\n\n{$post['author']} hat im Thema „{$post['title']}“ geantwortet:\n\n"
                    . preg_replace('/^/m', '> ', $text) . "\n\n"
                    . "Zum Beitrag:\n$link\n\n"
                    . "Weitere Mails zu diesem Thema bekommst du erst wieder, nachdem du es besucht hast.\n"
                    . "Keine Mails mehr zu diesem Thema:\n" . $this->unsubscribeUrl((int)$r['user_id'], $topicId) . "\n\n"
                    . Instance::teamSignature();
                try {
                    (new Mailer())->send($r['email'], Instance::siteName() . ' Forum: Neue Antwort in „' . mb_substr($post['title'], 0, 80) . '“', $body);
                    $done->execute([(int)$r['user_id'], $topicId]);
                    $n++;
                } catch (Throwable $e) {
                    error_log('Forum-Abo-Mail an Nutzer ' . $r['user_id'] . ' fehlgeschlagen: ' . $e->getMessage());
                }
            }
            return $n;
        } catch (Throwable $e) {
            error_log('ForumComfort::notifyNewPost: ' . $e->getMessage());
            return 0;
        }
    }

    /* ---------- Meldungen ---------- */

    /** Beitrag melden; false, wenn dieser Nutzer ihn schon gemeldet hat */
    public function report(int $postId, int $userId, string $reason): bool
    {
        $st = $this->pdo->prepare("SELECT user_id FROM forum_posts WHERE id = ?");
        $st->execute([$postId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new InvalidArgumentException('Beitrag nicht gefunden');
        if ((int)$row['user_id'] === $userId) throw new InvalidArgumentException('Eigene Beiträge kannst du nicht melden.');
        $reason = trim(mb_substr(preg_replace('/\s+/u', ' ', $reason), 0, 500));
        $ins = $this->pdo->prepare("INSERT OR IGNORE INTO forum_reports (post_id, reporter_id, reason) VALUES (?, ?, ?)");
        $ins->execute([$postId, $userId, $reason !== '' ? $reason : null]);
        if ($ins->rowCount() === 0) {
            // schon gemeldet: erledigte Meldung wieder oeffnen, falls erneut gemeldet
            $this->pdo->prepare("UPDATE forum_reports SET status = 'open', reason = COALESCE(?, reason), created_at = CURRENT_TIMESTAMP
                WHERE post_id = ? AND reporter_id = ? AND status <> 'open'")->execute([$reason !== '' ? $reason : null, $postId, $userId]);
            return false;
        }
        return true;
    }

    /** Offene Meldungen, je Beitrag zusammengefasst */
    public function openReports(): array
    {
        $rows = $this->pdo->query("SELECT r.id, r.post_id, r.reason, r.created_at,
                COALESCE(NULLIF(ru.display_name, ''), ru.username) AS reporter,
                p.content, p.topic_id, p.created_at AS post_created, t.title,
                COALESCE(NULLIF(au.display_name, ''), au.username, p.guest_name, 'Gast') AS author
            FROM forum_reports r
            LEFT JOIN forum_posts p ON p.id = r.post_id
            LEFT JOIN forum_topics t ON t.id = p.topic_id
            LEFT JOIN users ru ON ru.id = r.reporter_id
            LEFT JOIN users au ON au.id = p.user_id
            WHERE r.status = 'open'
            ORDER BY r.created_at")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $k = (int)$r['post_id'];
            if (!isset($out[$k])) {
                $out[$k] = ['post_id' => $k, 'topic_id' => (int)$r['topic_id'], 'title' => $r['title'], 'author' => $r['author'],
                    'post_created' => $r['post_created'], 'deleted' => $r['content'] === null,
                    'excerpt' => mb_substr(self::plainText((string)$r['content']), 0, 300), 'reports' => []];
            }
            $out[$k]['reports'][] = ['reporter' => $r['reporter'], 'reason' => $r['reason'], 'created_at' => $r['created_at']];
        }
        return array_values($out);
    }

    /** Alle Meldungen zu einem Beitrag als erledigt markieren */
    public function closeReports(int $postId, int $adminId): void
    {
        $this->pdo->prepare("UPDATE forum_reports SET status = 'done', handled_by = ?, handled_at = CURRENT_TIMESTAMP
            WHERE post_id = ? AND status = 'open'")->execute([$adminId, $postId]);
    }

    /** Beitragstext ohne BBCode/HTML fuer Mails */
    public static function plainText(string $content): string
    {
        $t = preg_replace('#\[quote(?:=[^\]]*)?\].*?\[/quote\]#is', '[Zitat] ', $content);
        $t = preg_replace('#\[img\][^\[]*\[/img\]#i', '[Bild] ', $t);
        $t = preg_replace('#\[url=([^\]]+)\](.*?)\[/url\]#is', '$2 ($1)', $t);
        $t = preg_replace('#\[/?(?:b|i|u|s|url|list|\*)\]#i', '', $t);
        $t = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $t)), ENT_QUOTES, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }
}
