<?php
/**
 * Live-Ticker von Zuschauern (zuschaltbar: app_settings.feature_live = '1')
 *
 * Zeitfenster je Spiel (Europe/Berlin):
 *   mit Anstosszeit: 60 min vor Anpfiff bis 60 min nach Spielende
 *                    (Spielende = Meldung "Abpfiff", sonst Anpfiff + 110 min)
 *   ohne Anstosszeit: am Spieltag 10:00 bis 22:00 (bzw. 60 min nach "Abpfiff")
 * Tickern darf, wer angemeldet ist, "Ich bin bei diesem Spiel" geklickt hat und nicht gesperrt ist (user_blocks scope 'live').
 * Der Ticker-Spielstand ist inoffiziell; die Redaktion kann ihn als Ergebnis uebernehmen.
 */
require_once __DIR__ . '/LmoDatabase.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/CommentService.php';

class LiveService
{
    public const TZ = 'Europe/Berlin';
    public const TYPES = [
        'kickoff' => 'Anpfiff', 'goal' => 'Tor', 'yellow' => 'Gelbe Karte', 'yellowred' => 'Gelb-Rote Karte', 'red' => 'Rote Karte',
        'sub' => 'Wechsel', 'half' => 'Halbzeit', 'second' => 'Anpfiff 2. Halbzeit', 'end' => 'Abpfiff', 'score' => 'Spielstand', 'note' => 'Meldung',
    ];
    private const BEFORE = 60;      // Minuten vor Anpfiff
    private const DURATION = 110;   // angenommene Spieldauer inkl. Pause
    private const AFTER = 60;       // Minuten nach Spielende

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS live_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            match_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            minute INTEGER,
            team TEXT,                 -- home | guest | NULL
            player TEXT,
            text TEXT,
            score_home INTEGER,
            score_guest INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted INTEGER NOT NULL DEFAULT 0,
            deleted_by INTEGER
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_live_events_match ON live_events(match_id, deleted, id)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_live_events_user ON live_events(user_id, created_at)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS live_presence (
            match_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (match_id, user_id)
        )");
        CommentService::ensureBlocks($pdo);
    }

    public static function enabled(): bool
    {
        return (new SettingsRepository())->get('feature_live') === '1';
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    private static function parseDate(?string $date): ?array
    {
        if (!$date || !preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($date), $d)) return null;
        return [(int)$d[3], (int)$d[2], (int)$d[1]];
    }

    /** Anpfiff (null ohne Uhrzeit) */
    public static function kickoff(?string $date, ?string $time): ?DateTimeImmutable
    {
        $d = self::parseDate($date);
        if (!$d || !$time || !preg_match('/^(\d{1,2})[:.](\d{2})/', trim($time), $t)) return null;
        return self::now()->setDate($d[0], $d[1], $d[2])->setTime((int)$t[1], (int)$t[2]);
    }

    /** [oeffnet, schliesst] oder null ohne Datum */
    private function window(array $m, ?string $endAt): ?array
    {
        $d = self::parseDate($m['match_date'] ?? null);
        if (!$d) return null;
        $ko = self::kickoff($m['match_date'], $m['match_time'] ?? null);
        $day = self::now()->setDate($d[0], $d[1], $d[2]);
        $open = $ko ? $ko->modify('-' . self::BEFORE . ' minutes') : $day->setTime(10, 0);
        $close = $ko ? $ko->modify('+' . (self::DURATION + self::AFTER) . ' minutes') : $day->setTime(22, 0);
        if ($endAt) {
            $end = (new DateTimeImmutable($endAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TZ))->modify('+' . self::AFTER . ' minutes');
            if ($end > $open) $close = $end;
        }
        return [$open, $close];
    }

    private function matchRow(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT m.id, m.league_id, m.round_nr, m.match_date, m.match_time, m.home_goals, m.guest_goals,
                l.name AS league, l.file AS league_file, th.name AS home, tg.name AS guest
            FROM matches m JOIN leagues l ON l.id = m.league_id
            LEFT JOIN teams th ON th.id = m.home_team_id LEFT JOIN teams tg ON tg.id = m.guest_team_id
            WHERE m.id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Spiele mit Datum heute oder gestern (spaete Spiele ueber Mitternacht) */
    private function recentMatches(): array
    {
        $vals = [];
        foreach ([self::now(), self::now()->modify('-1 day')] as $d) {
            $vals[] = $d->format('d.m.Y');
            $vals[] = $d->format('j.n.Y');
            $vals[] = $d->format('j.m.Y');
            $vals[] = $d->format('d.n.Y');
        }
        $vals = array_values(array_unique($vals));
        $st = $this->pdo->prepare("SELECT m.id, m.league_id, m.round_nr, m.match_date, m.match_time, m.home_goals, m.guest_goals,
                l.name AS league, l.file AS league_file, th.name AS home, tg.name AS guest
            FROM matches m JOIN leagues l ON l.id = m.league_id
            LEFT JOIN teams th ON th.id = m.home_team_id LEFT JOIN teams tg ON tg.id = m.guest_team_id
            WHERE m.match_date IN (" . implode(',', array_fill(0, count($vals), '?')) . ")
            ORDER BY l.id DESC, m.match_time, m.id");
        $st->execute($vals);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Zusammenfassung je Spiel aus den Meldungen */
    private function summaries(array $ids): array
    {
        $out = [];
        if (!$ids) return $out;
        $in = implode(',', array_map('intval', $ids));
        foreach ($this->pdo->query("SELECT match_id, type, score_home, score_guest, created_at FROM live_events
                WHERE deleted = 0 AND match_id IN ($in) ORDER BY id") as $e) {
            $s = &$out[(int)$e['match_id']];
            $s ??= ['events' => 0, 'score' => null, 'phase' => 'pre', 'end_at' => null, 'last_at' => null];
            $s['events']++;
            $s['last_at'] = $e['created_at'];
            if ($e['score_home'] !== null && $e['score_guest'] !== null) $s['score'] = [(int)$e['score_home'], (int)$e['score_guest']];
            $phase = ['kickoff' => 'first', 'half' => 'half', 'second' => 'second', 'end' => 'end'][$e['type']] ?? null;
            if ($phase) $s['phase'] = $phase;
            if ($e['type'] === 'end') $s['end_at'] = $e['created_at'];
            elseif ($phase) $s['end_at'] = null;
            unset($s);
        }
        foreach ($this->pdo->query("SELECT match_id, COUNT(*) FROM live_presence WHERE match_id IN ($in) GROUP BY match_id")->fetchAll(PDO::FETCH_KEY_PAIR) as $mid => $n) {
            $out[(int)$mid] ??= ['events' => 0, 'score' => null, 'phase' => 'pre', 'end_at' => null, 'last_at' => null];
            $out[(int)$mid]['reporters'] = (int)$n;
        }
        return $out;
    }

    private static function played(array $m): bool
    {
        return $m['home_goals'] !== null && $m['guest_goals'] !== null && (int)$m['home_goals'] >= 0 && (int)$m['guest_goals'] >= 0;
    }

    private function brief(array $m, ?array $s, array $win, DateTimeImmutable $now): array
    {
        $s ??= ['events' => 0, 'score' => null, 'phase' => 'pre', 'end_at' => null];
        $crest = fn($n) => preg_replace('/[^a-zA-Z0-9]/', '', (string)$n);
        return [
            'id' => (int)$m['id'], 'league' => $m['league'], 'league_file' => $m['league_file'], 'round' => (int)$m['round_nr'],
            'home' => $m['home'], 'guest' => $m['guest'], 'home_crest' => $crest($m['home']), 'guest_crest' => $crest($m['guest']),
            'date' => $m['match_date'], 'time' => $m['match_time'] ?: null,
            'score' => $s['score'], 'phase' => $s['phase'], 'events' => (int)$s['events'], 'reporters' => (int)($s['reporters'] ?? 0),
            'result' => self::played($m) ? [(int)$m['home_goals'], (int)$m['guest_goals']] : null,
            'open' => $now >= $win[0] && $now <= $win[1],
            'opens_at' => $win[0]->format('H:i'), 'closes_at' => $win[1]->format('H:i'),
        ];
    }

    /**
     * Spiele im Zeitfenster.
     * $onlyActive: nur Spiele, bei denen jemand dabei ist oder schon getickert hat
     */
    public function current(bool $onlyActive): array
    {
        $now = self::now();
        $ms = $this->recentMatches();
        $sum = $this->summaries(array_column($ms, 'id'));
        $out = [];
        foreach ($ms as $m) {
            $s = $sum[(int)$m['id']] ?? null;
            $win = $this->window($m, $s['end_at'] ?? null);
            if (!$win || $now < $win[0] || $now > $win[1]) continue;
            if ($onlyActive && !$s) continue;
            $out[] = $this->brief($m, $s, $win, $now);
        }
        return $out;
    }

    /** Ticker eines Spiels */
    public function match(int $id, ?int $userId, bool $moderator): ?array
    {
        $m = $this->matchRow($id);
        if (!$m) return null;
        $s = $this->summaries([$id])[$id] ?? null;
        $win = $this->window($m, $s['end_at'] ?? null);
        $now = self::now();
        $data = $win ? $this->brief($m, $s, $win, $now)
            : $this->brief($m, $s, [$now->modify('+1 day'), $now->modify('-1 day')], $now) + ['no_date' => true];
        $st = $this->pdo->prepare("SELECT e.id, e.user_id, e.type, e.minute, e.team, e.player, e.text, e.score_home, e.score_guest, e.created_at, e.deleted,
                COALESCE(NULLIF(u.display_name, ''), u.username, 'Unbekannt') AS name
            FROM live_events e LEFT JOIN users u ON u.id = e.user_id
            WHERE e.match_id = ?" . ($moderator ? '' : ' AND e.deleted = 0') . " ORDER BY e.id DESC");
        $st->execute([$id]);
        $fiveMin = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s');
        $data['feed'] = array_map(function ($e) use ($userId, $moderator, $fiveMin) {
            $own = $userId && (int)$e['user_id'] === $userId;
            $row = [
                'id' => (int)$e['id'], 'type' => $e['type'], 'label' => self::TYPES[$e['type']] ?? $e['type'],
                'minute' => $e['minute'] !== null ? (int)$e['minute'] : null, 'team' => $e['team'], 'player' => $e['player'], 'text' => $e['text'],
                'score' => $e['score_home'] !== null ? [(int)$e['score_home'], (int)$e['score_guest']] : null,
                'name' => $e['name'], 'created_at' => $e['created_at'], 'own' => $own,
                'can_delete' => $moderator || ($own && $e['created_at'] >= $fiveMin),
            ];
            if ($moderator) { $row['user_id'] = (int)$e['user_id']; $row['deleted'] = (bool)$e['deleted']; }
            return $row;
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $this->pdo->prepare("SELECT COALESCE(NULLIF(u.display_name, ''), u.username) FROM live_presence p JOIN users u ON u.id = p.user_id
            WHERE p.match_id = ? ORDER BY p.created_at");
        $st->execute([$id]);
        $data['reporter_names'] = $st->fetchAll(PDO::FETCH_COLUMN);
        $data['present'] = false;
        if ($userId) {
            $st = $this->pdo->prepare("SELECT 1 FROM live_presence WHERE match_id = ? AND user_id = ?");
            $st->execute([$id, $userId]);
            $data['present'] = (bool)$st->fetchColumn();
        }
        return $data;
    }

    private function assertCanTick(array $m, int $userId): void
    {
        if (!self::enabled()) throw new InvalidArgumentException('Der Live-Ticker ist ausgeschaltet.');
        if (!CommentService::activeMember($this->pdo, $userId)) throw new InvalidArgumentException('Dein Konto ist nicht freigeschaltet.');
        if (CommentService::isBlocked($this->pdo, $userId, 'live')) throw new InvalidArgumentException('Du kannst zurzeit nicht tickern.');
        $s = $this->summaries([(int)$m['id']])[(int)$m['id']] ?? null;
        $win = $this->window($m, $s['end_at'] ?? null);
        $now = self::now();
        if (!$win || $now < $win[0]) throw new InvalidArgumentException('Der Ticker öffnet eine Stunde vor Anpfiff' . ($win ? ' (' . $win[0]->format('d.m. H:i') . ' Uhr)' : '') . '.');
        if ($now > $win[1]) throw new InvalidArgumentException('Der Ticker für dieses Spiel ist geschlossen.');
    }

    public function setPresent(int $matchId, int $userId, bool $on): void
    {
        $m = $this->matchRow($matchId);
        if (!$m) throw new InvalidArgumentException('Spiel nicht gefunden.');
        if ($on) {
            $this->assertCanTick($m, $userId);
            $this->pdo->prepare("INSERT OR IGNORE INTO live_presence (match_id, user_id) VALUES (?, ?)")->execute([$matchId, $userId]);
        } else {
            $this->pdo->prepare("DELETE FROM live_presence WHERE match_id = ? AND user_id = ?")->execute([$matchId, $userId]);
        }
    }

    public function addEvent(int $matchId, int $userId, array $in): int
    {
        $m = $this->matchRow($matchId);
        if (!$m) throw new InvalidArgumentException('Spiel nicht gefunden.');
        $this->assertCanTick($m, $userId);
        $st = $this->pdo->prepare("SELECT 1 FROM live_presence WHERE match_id = ? AND user_id = ?");
        $st->execute([$matchId, $userId]);
        if (!$st->fetchColumn()) throw new InvalidArgumentException('Bitte zuerst „Ich bin bei diesem Spiel“ anklicken.');

        $type = (string)($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) throw new InvalidArgumentException('Unbekannte Meldung.');
        $minute = isset($in['minute']) && $in['minute'] !== '' && $in['minute'] !== null ? (int)$in['minute'] : null;
        if ($minute !== null && ($minute < 0 || $minute > 130)) throw new InvalidArgumentException('Spielminute 0–130.');
        $team = in_array($in['team'] ?? null, ['home', 'guest'], true) ? $in['team'] : null;
        if (in_array($type, ['goal', 'yellow', 'yellowred', 'red', 'sub'], true) && !$team) throw new InvalidArgumentException('Bitte die Mannschaft wählen.');
        $player = mb_substr(trim((string)($in['player'] ?? '')), 0, 60) ?: null;
        $text = trim(preg_replace('/\s+/u', ' ', (string)($in['text'] ?? '')));
        if (mb_strlen($text) > 280) throw new InvalidArgumentException('Text höchstens 280 Zeichen.');
        if ($type === 'note' && $text === '') throw new InvalidArgumentException('Bitte einen Text eingeben.');

        $sh = $sg = null;
        if (in_array($type, ['goal', 'score', 'half', 'end'], true) && isset($in['score_home'], $in['score_guest']) && $in['score_home'] !== '' && $in['score_guest'] !== '') {
            $sh = (int)$in['score_home']; $sg = (int)$in['score_guest'];
            if ($sh < 0 || $sg < 0 || $sh > 40 || $sg > 40) throw new InvalidArgumentException('Ungültiger Spielstand.');
        }
        if (in_array($type, ['goal', 'score'], true) && $sh === null) throw new InvalidArgumentException('Bitte den Spielstand angeben.');

        // Bremse: 10 s Abstand, hoechstens 60 Meldungen je Stunde
        $st = $this->pdo->prepare("SELECT SUM(created_at >= datetime('now', '-10 seconds')), SUM(created_at >= datetime('now', '-1 hour'))
            FROM live_events WHERE user_id = ?");
        $st->execute([$userId]);
        [$recent, $hour] = array_map('intval', $st->fetch(PDO::FETCH_NUM) ?: [0, 0]);
        if ($recent > 0) throw new InvalidArgumentException('Bitte kurz warten (10 Sekunden zwischen zwei Meldungen).');
        if ($hour >= 60) throw new InvalidArgumentException('Zu viele Meldungen in kurzer Zeit.');

        $this->pdo->prepare("INSERT INTO live_events (match_id, user_id, type, minute, team, player, text, score_home, score_guest)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute([$matchId, $userId, $type, $minute, $team, $player, $text !== '' ? $text : null, $sh, $sg]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Eigene Meldung binnen 5 Minuten oder jede (Redaktion) loeschen */
    public function deleteEvent(int $eventId, int $userId, bool $moderator): void
    {
        $st = $this->pdo->prepare("SELECT user_id, created_at, deleted FROM live_events WHERE id = ?");
        $st->execute([$eventId]);
        $e = $st->fetch(PDO::FETCH_ASSOC);
        if (!$e || $e['deleted']) throw new InvalidArgumentException('Meldung nicht gefunden.');
        if (!$moderator) {
            $limit = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s');
            if ((int)$e['user_id'] !== $userId || $e['created_at'] < $limit) throw new InvalidArgumentException('Eigene Meldungen lassen sich 5 Minuten lang löschen.');
        }
        $this->pdo->prepare("UPDATE live_events SET deleted = 1, deleted_by = ? WHERE id = ?")->execute([$userId ?: null, $eventId]);
    }

    public function restoreEvent(int $eventId): void
    {
        $this->pdo->prepare("UPDATE live_events SET deleted = 0, deleted_by = NULL WHERE id = ?")->execute([$eventId]);
    }

    /** Ticker-Spielstand als Ergebnis uebernehmen (Redaktion) */
    public function adoptResult(int $matchId): array
    {
        $s = $this->summaries([$matchId])[$matchId] ?? null;
        if (!$s || !$s['score']) throw new InvalidArgumentException('Im Ticker gibt es keinen Spielstand.');
        $this->pdo->prepare("UPDATE matches SET home_goals = ?, guest_goals = ? WHERE id = ?")->execute([$s['score'][0], $s['score'][1], $matchId]);
        foreach (glob(__DIR__ . '/../data/cache/*.json') ?: [] as $f) @unlink($f);
        return $s['score'];
    }

    /** Uebersicht fuer die Redaktion: Spiele mit Meldungen der letzten Tage */
    public function adminOverview(int $days = 14): array
    {
        $st = $this->pdo->prepare("SELECT match_id, COUNT(*) AS events, SUM(deleted) AS deleted, MAX(created_at) AS last_at, COUNT(DISTINCT user_id) AS users
            FROM live_events WHERE created_at >= datetime('now', ?) GROUP BY match_id ORDER BY last_at DESC LIMIT 100");
        $st->execute(['-' . max(1, $days) . ' days']);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $sum = $this->summaries(array_column($rows, 'match_id'));
        $out = [];
        foreach ($rows as $r) {
            $m = $this->matchRow((int)$r['match_id']);
            if (!$m) continue;
            $out[] = [
                'id' => (int)$m['id'], 'league' => $m['league'], 'home' => $m['home'], 'guest' => $m['guest'], 'date' => $m['match_date'], 'time' => $m['match_time'],
                'events' => (int)$r['events'] - (int)$r['deleted'], 'deleted' => (int)$r['deleted'], 'users' => (int)$r['users'], 'last_at' => $r['last_at'],
                'score' => $sum[(int)$m['id']]['score'] ?? null, 'phase' => $sum[(int)$m['id']]['phase'] ?? 'pre',
                'result' => self::played($m) ? [(int)$m['home_goals'], (int)$m['guest_goals']] : null,
            ];
        }
        return $out;
    }
}
