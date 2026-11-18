<?php
/**
 * Tippspiel: Mitglieder tippen Spiele einer Liga (Tipprunde = Liga einer Saison).
 *
 * Regeln
 *  - Tippen und Ändern bis zum Anstoß (Datum + Uhrzeit des Spiels, Zeitzone Europe/Berlin).
 *    Ohne Uhrzeit: bis 0:00 Uhr am Spieltag; ohne Datum: bis ein Ergebnis eingetragen ist.
 *  - Punkte: exaktes Ergebnis 3, richtige Tordifferenz 2, richtige Tendenz 1.
 *  - Tipps anderer sind erst nach Anstoß sichtbar.
 * Zuschaltbar unter Admin -> Einstellungen (feature_tipp).
 */
require_once __DIR__ . '/LmoDatabase.php';

class TippService
{
    public const TZ = 'Europe/Berlin';
    public const POINTS_EXACT = 3;
    public const POINTS_DIFF = 2;
    public const POINTS_TENDENCY = 1;

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
        $pdo->exec("CREATE TABLE IF NOT EXISTS tipp_rounds (
            id INTEGER PRIMARY KEY AUTOINCREMENT, league_id INTEGER NOT NULL, name TEXT NOT NULL,
            start_round INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(league_id) REFERENCES leagues(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS tipps (
            id INTEGER PRIMARY KEY AUTOINCREMENT, round_id INTEGER NOT NULL, user_id INTEGER NOT NULL, match_id INTEGER NOT NULL,
            home INTEGER NOT NULL, guest INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(round_id, user_id, match_id),
            FOREIGN KEY(round_id) REFERENCES tipp_rounds(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tipps_match ON tipps(match_id)");
        $done = true;
    }

    public static function enabled(): bool
    {
        require_once __DIR__ . '/SettingsRepository.php';
        return (new SettingsRepository())->get('feature_tipp') === '1';
    }

    // ------------------------------------------------------------------ Tipprunden

    public function rounds(bool $onlyActive = true): array
    {
        $sql = "SELECT r.id, r.name, r.start_round, r.active, r.league_id, l.file, l.name AS league_name,
                       (SELECT COUNT(DISTINCT user_id) FROM tipps t WHERE t.round_id = r.id) AS players
                FROM tipp_rounds r JOIN leagues l ON l.id = r.league_id" . ($onlyActive ? " WHERE r.active = 1" : "") . " ORDER BY r.id DESC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function round(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT r.*, l.file, l.name AS league_name, l.options FROM tipp_rounds r JOIN leagues l ON l.id = r.league_id WHERE r.id = ?");
        $st->execute([$id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $opt = json_decode((string)$r['options'], true) ?: [];
        $r['rounds'] = (int)($opt['Rounds'] ?? 0);
        unset($r['options']);
        return $r;
    }

    public function createRound(string $leagueFile, string $name, int $startRound): int
    {
        $st = $this->pdo->prepare("SELECT id, name FROM leagues WHERE file = ?");
        $st->execute([$leagueFile]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) throw new InvalidArgumentException('Liga nicht gefunden.');
        $name = trim($name) !== '' ? trim($name) : 'Tippspiel ' . $l['name'];
        if (mb_strlen($name) > 100) throw new InvalidArgumentException('Name zu lang.');
        $this->pdo->prepare("INSERT INTO tipp_rounds (league_id, name, start_round) VALUES (?, ?, ?)")->execute([$l['id'], $name, max(1, $startRound)]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Ligen fuer die Auswahl im Admin (neueste zuerst) */
    public function leagues(): array
    {
        return $this->pdo->query("SELECT file, name FROM leagues ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare("UPDATE tipp_rounds SET active = ? WHERE id = ?")->execute([$active ? 1 : 0, $id]);
    }

    // ------------------------------------------------------------------ Spiele, Sperre, Punkte

    /** Zeitpunkt, ab dem nicht mehr getippt werden kann (null = kein Datum bekannt) */
    public static function lockTime(?string $date, ?string $time): ?DateTimeImmutable
    {
        if (!$date || !preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', trim($date), $d)) return null;
        $h = 0; $m = 0;
        if ($time && preg_match('/^(\d{1,2})[:.](\d{2})/', trim($time), $t)) { $h = (int)$t[1]; $m = (int)$t[2]; }
        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->setDate((int)$d[3], (int)$d[2], (int)$d[1])->setTime($h, $m);
    }

    private static function played(array $m): bool
    {
        return $m['home_goals'] !== null && $m['guest_goals'] !== null && (int)$m['home_goals'] >= 0 && (int)$m['guest_goals'] >= 0;
    }

    public static function isLocked(array $m, ?DateTimeImmutable $now = null): bool
    {
        if (self::played($m)) return true;
        $lock = self::lockTime($m['match_date'] ?? null, $m['match_time'] ?? null);
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        return $lock !== null && $now >= $lock;
    }

    public static function points(int $th, int $tg, int $rh, int $rg): int
    {
        if ($th === $rh && $tg === $rg) return self::POINTS_EXACT;
        if ($th - $tg === $rh - $rg) return self::POINTS_DIFF;
        if (($th <=> $tg) === ($rh <=> $rg)) return self::POINTS_TENDENCY;
        return 0;
    }

    private function matches(int $leagueId, ?int $matchday = null): array
    {
        $sql = "SELECT m.id, m.round_nr, m.match_date, m.match_time, m.home_goals, m.guest_goals, th.name AS home, tg.name AS guest
                FROM matches m JOIN teams th ON th.id = m.home_team_id JOIN teams tg ON tg.id = m.guest_team_id
                WHERE m.league_id = ?" . ($matchday ? " AND m.round_nr = ?" : "") . " ORDER BY m.round_nr, m.id";
        $st = $this->pdo->prepare($sql);
        $st->execute($matchday ? [$leagueId, $matchday] : [$leagueId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Erster Spieltag (ab start_round) mit noch tippbaren Spielen; sonst der letzte mit offenen Spielen, sonst der letzte */
    public function currentMatchday(array $round): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $lastOpen = 0; $last = 0;
        foreach ($this->matches((int)$round['league_id']) as $m) {
            $nr = (int)$m['round_nr'];
            if ($nr < (int)$round['start_round']) continue;
            $last = max($last, $nr);
            if (self::played($m)) continue;
            if (!self::isLocked($m, $now)) return $nr;
            $lastOpen = max($lastOpen, $nr);
        }
        return $lastOpen ?: ($last ?: max(1, (int)$round['start_round']));
    }

    /** Spiele eines Spieltags mit eigenem Tipp, Sperre, Ergebnis und Punkten */
    public function matchday(array $round, int $md, ?int $userId): array
    {
        $tips = [];
        if ($userId) {
            $st = $this->pdo->prepare("SELECT match_id, home, guest FROM tipps WHERE round_id = ? AND user_id = ?");
            $st->execute([$round['id'], $userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $tips[(int)$t['match_id']] = $t;
        }
        $cnt = $this->pdo->prepare("SELECT match_id, COUNT(*) FROM tipps WHERE round_id = ? GROUP BY match_id");
        $cnt->execute([$round['id']]);
        $counts = array_map('intval', $cnt->fetchAll(PDO::FETCH_KEY_PAIR));
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $out = [];
        foreach ($this->matches((int)$round['league_id'], $md) as $m) {
            $played = self::played($m);
            $tip = $tips[(int)$m['id']] ?? null;
            $lock = self::lockTime($m['match_date'], $m['match_time']);
            $out[] = [
                'id' => (int)$m['id'], 'home' => $m['home'], 'guest' => $m['guest'], 'date' => $m['match_date'], 'time' => $m['match_time'],
                'locked' => self::isLocked($m, $now), 'lock_at' => $lock ? $lock->format('c') : null,
                'result' => $played ? [(int)$m['home_goals'], (int)$m['guest_goals']] : null,
                'tip' => $tip ? [(int)$tip['home'], (int)$tip['guest']] : null,
                'points' => ($tip && $played) ? self::points((int)$tip['home'], (int)$tip['guest'], (int)$m['home_goals'], (int)$m['guest_goals']) : null,
                'tip_count' => $counts[(int)$m['id']] ?? 0,
            ];
        }
        return $out;
    }

    /** Tipps speichern; gesperrte Spiele werden abgewiesen (Pruefung auf dem Server) */
    public function save(array $round, int $userId, array $tips): array
    {
        $saved = 0; $rejected = [];
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TZ));
        $get = $this->pdo->prepare("SELECT id, round_nr, match_date, match_time, home_goals, guest_goals FROM matches WHERE id = ? AND league_id = ?");
        $up = $this->pdo->prepare("INSERT INTO tipps (round_id, user_id, match_id, home, guest) VALUES (?,?,?,?,?)
            ON CONFLICT(round_id, user_id, match_id) DO UPDATE SET home = excluded.home, guest = excluded.guest, updated_at = CURRENT_TIMESTAMP");
        $del = $this->pdo->prepare("DELETE FROM tipps WHERE round_id = ? AND user_id = ? AND match_id = ?");
        foreach (array_slice($tips, 0, 50) as $t) {
            $mid = (int)($t['match_id'] ?? 0);
            $get->execute([$mid, $round['league_id']]);
            $m = $get->fetch(PDO::FETCH_ASSOC);
            if (!$m || (int)$m['round_nr'] < (int)$round['start_round']) { $rejected[] = $mid; continue; }
            if (self::isLocked($m, $now)) { $rejected[] = $mid; continue; }
            $h = $t['home'] ?? null; $g = $t['guest'] ?? null;
            if (($h === null || $h === '') && ($g === null || $g === '')) { $del->execute([$round['id'], $userId, $mid]); continue; }
            if (!is_numeric($h) || !is_numeric($g) || $h < 0 || $g < 0 || $h > 30 || $g > 30) { $rejected[] = $mid; continue; }
            $up->execute([$round['id'], $userId, $mid, (int)$h, (int)$g]);
            $saved++;
        }
        return ['saved' => $saved, 'rejected' => $rejected];
    }

    /** Tipps aller Mitglieder zu einem Spiel – erst nach Anstoss */
    public function matchTips(array $round, int $matchId): array
    {
        $st = $this->pdo->prepare("SELECT id, match_date, match_time, home_goals, guest_goals FROM matches WHERE id = ? AND league_id = ?");
        $st->execute([$matchId, $round['league_id']]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new InvalidArgumentException('Spiel nicht gefunden.');
        if (!self::isLocked($m)) throw new InvalidArgumentException('Die Tipps der anderen sind erst nach Anstoß sichtbar.');
        $st = $this->pdo->prepare("SELECT COALESCE(NULLIF(u.display_name, ''), u.username) AS name, t.home, t.guest
            FROM tipps t JOIN users u ON u.id = t.user_id WHERE t.round_id = ? AND t.match_id = ? ORDER BY name COLLATE NOCASE");
        $st->execute([$round['id'], $matchId]);
        $played = self::played($m);
        return array_map(fn($r) => ['name' => $r['name'], 'tip' => [(int)$r['home'], (int)$r['guest']],
            'points' => $played ? self::points((int)$r['home'], (int)$r['guest'], (int)$m['home_goals'], (int)$m['guest_goals']) : null],
            $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Rangliste der Saison oder eines Spieltags */
    public function ranking(array $round, ?int $md = null): array
    {
        $sql = "SELECT t.user_id, COALESCE(NULLIF(u.display_name, ''), u.username) AS name, u.avatar,
                       t.home, t.guest, m.home_goals, m.guest_goals
                FROM tipps t JOIN matches m ON m.id = t.match_id JOIN users u ON u.id = t.user_id
                WHERE t.round_id = ? AND m.home_goals IS NOT NULL AND m.guest_goals IS NOT NULL AND m.home_goals >= 0"
                . ($md ? " AND m.round_nr = ?" : "");
        $st = $this->pdo->prepare($sql);
        $st->execute($md ? [$round['id'], $md] : [$round['id']]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $u = (int)$r['user_id'];
            $rows[$u] ??= ['uid' => $u, 'name' => $r['name'], 'avatar' => $r['avatar'], 'points' => 0, 'exact' => 0, 'diff' => 0, 'tendency' => 0, 'tips' => 0];
            $p = self::points((int)$r['home'], (int)$r['guest'], (int)$r['home_goals'], (int)$r['guest_goals']);
            $rows[$u]['points'] += $p;
            $rows[$u]['tips']++;
            if ($p === self::POINTS_EXACT) $rows[$u]['exact']++;
            elseif ($p === self::POINTS_DIFF) $rows[$u]['diff']++;
            elseif ($p === self::POINTS_TENDENCY) $rows[$u]['tendency']++;
        }
        $rows = array_values($rows);
        usort($rows, fn($a, $b) => [$b['points'], $b['exact'], $b['diff']] <=> [$a['points'], $a['exact'], $a['diff']] ?: strcasecmp($a['name'], $b['name']));
        $rank = 0; $prev = null;
        foreach ($rows as $i => &$r) {
            $key = $r['points'] . '-' . $r['exact'] . '-' . $r['diff'];
            if ($key !== $prev) { $rank = $i + 1; $prev = $key; }
            $r['rank'] = $rank;
        }
        return $rows;
    }
}
