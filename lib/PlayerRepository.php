<?php
/**
 * Spielerprofile: Stammdaten + Vereinshistorie.
 * Felder, die im Admin von Hand gepflegt wurden, stehen in players.manual_fields
 * und werden von automatischen Importen nicht ueberschrieben.
 */
require_once __DIR__ . '/LmoDatabase.php';

class PlayerRepository
{
    /** Profilfelder, die im Admin bzw. per Import gepflegt werden */
    const PROFILE_FIELDS = ['birth_date', 'nationality', 'joined_at', 'foot', 'height_cm', 'notes', 'external_url'];

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
        $cols = array_column($pdo->query("PRAGMA table_info(players)")->fetchAll(), 'name');
        $add = [
            'birth_date' => 'TEXT',          // JJJJ-MM-TT oder nur JJJJ
            'nationality' => 'TEXT',
            'joined_at' => 'TEXT',           // "im Verein seit", JJJJ-MM oder JJJJ-MM-TT
            'foot' => 'TEXT',                // links/rechts/beidfuessig
            'height_cm' => 'INTEGER',
            'notes' => 'TEXT',
            'external_url' => 'TEXT',        // Profil-Link (Quelle)
            'source' => 'TEXT',              // z. B. "FuPa", "manuell"
            'source_updated_at' => 'TEXT',   // Stand der Daten
            'manual_fields' => 'TEXT',       // kommagetrennt: von Hand gepflegte Felder
            'updated_at' => 'TEXT',
        ];
        foreach ($add as $c => $type) {
            if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE players ADD COLUMN $c $type");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            player_id INTEGER NOT NULL,
            club TEXT NOT NULL,
            from_date TEXT,
            to_date TEXT,
            note TEXT,
            source TEXT,
            manual INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_player_history_player ON player_history(player_id)");
        $done = true;
    }

    // ------------------------------------------------------------ Lesen
    public function getPlayer(int $id, bool $public = true): array
    {
        $st = $this->pdo->prepare("SELECT p.*, t.name AS team_name, t.logo_file AS team_logo, l.name AS league_name, l.file AS league_file
                                   FROM players p LEFT JOIN teams t ON t.id = p.team_id LEFT JOIN leagues l ON l.id = t.league_id
                                   WHERE p.id = ?");
        $st->execute([$id]);
        $p = $st->fetch();
        if (!$p) throw new InvalidArgumentException('Spieler nicht gefunden');
        $p['age'] = self::age($p['birth_date']);
        $p['history'] = $this->getHistory($id);
        if ($public) {
            // Datensparsamkeit: oeffentlich nur Alter/Jahrgang, kein exaktes Geburtsdatum, keine internen Notizen
            $p['birth_year'] = $p['birth_date'] ? substr($p['birth_date'], 0, 4) : null;
            unset($p['birth_date'], $p['notes'], $p['manual_fields']);
        }
        return $p;
    }

    public function getHistory(int $playerId): array
    {
        $st = $this->pdo->prepare("SELECT id, club, from_date, to_date, note, source, manual FROM player_history
                                   WHERE player_id = ? ORDER BY COALESCE(from_date, '0000') DESC, id DESC");
        $st->execute([$playerId]);
        return $st->fetchAll();
    }

    public static function age(?string $birth): ?int
    {
        if (!$birth || !preg_match('/^(\d{4})(?:-(\d{2})-(\d{2}))?$/', $birth, $m)) return null;
        $now = new DateTime('today');
        if (empty($m[2])) return (int)$now->format('Y') - (int)$m[1]; // nur Jahrgang: ungefaehr
        return (new DateTime("$m[1]-$m[2]-$m[3]"))->diff($now)->y;
    }

    // ------------------------------------------------------------ Schreiben (Admin)
    /** Profilfelder aus dem Admin speichern; geaenderte Felder werden als "manuell" markiert */
    public function saveProfile(int $id, array $in): void
    {
        $cur = $this->pdo->prepare("SELECT * FROM players WHERE id = ?");
        $cur->execute([$id]);
        $old = $cur->fetch();
        if (!$old) throw new InvalidArgumentException('Spieler nicht gefunden');

        $vals = [];
        foreach (self::PROFILE_FIELDS as $f) {
            if (!array_key_exists($f, $in)) continue;
            $vals[$f] = self::clean($f, $in[$f]);
        }
        if (!$vals) return;
        $manual = array_filter(explode(',', (string)$old['manual_fields']));
        foreach ($vals as $f => $v) {
            if ((string)$v !== (string)$old[$f]) $manual[] = $f;
        }
        $manual = implode(',', array_unique($manual));
        $set = implode(', ', array_map(fn($f) => "$f = :$f", array_keys($vals)));
        $st = $this->pdo->prepare("UPDATE players SET $set, manual_fields = :mf, updated_at = datetime('now') WHERE id = :id");
        $params = [':mf' => $manual, ':id' => $id];
        foreach ($vals as $f => $v) $params[":$f"] = $v;
        $st->execute($params);
    }

    public function addHistory(int $playerId, array $in, bool $manual = true, ?string $source = null): int
    {
        $club = trim((string)($in['club'] ?? ''));
        if ($club === '' || mb_strlen($club) > 120) throw new InvalidArgumentException('Verein angeben (max. 120 Zeichen).');
        $st = $this->pdo->prepare("INSERT INTO player_history (player_id, club, from_date, to_date, note, source, manual) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$playerId, $club, self::clean('from_date', $in['from_date'] ?? null), self::clean('to_date', $in['to_date'] ?? null),
            self::clean('notes', $in['note'] ?? null), $source ?? ($manual ? 'manuell' : null), $manual ? 1 : 0]);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateHistory(int $historyId, array $in): void
    {
        $club = trim((string)($in['club'] ?? ''));
        if ($club === '') throw new InvalidArgumentException('Verein angeben.');
        $this->pdo->prepare("UPDATE player_history SET club = ?, from_date = ?, to_date = ?, note = ?, manual = 1 WHERE id = ?")
            ->execute([$club, self::clean('from_date', $in['from_date'] ?? null), self::clean('to_date', $in['to_date'] ?? null),
                self::clean('notes', $in['note'] ?? null), $historyId]);
    }

    public function deleteHistory(int $historyId): void
    {
        $this->pdo->prepare("DELETE FROM player_history WHERE id = ?")->execute([$historyId]);
    }

    // ------------------------------------------------------------ Import (fuer Scraper)
    /**
     * Uebernimmt Daten aus einer externen Quelle. Von Hand gepflegte Felder bleiben unberuehrt,
     * manuelle Historien-Eintraege ebenso; automatische Eintraege derselben Quelle werden ersetzt.
     */
    public function importProfile(int $id, array $data, array $history, string $source): array
    {
        $cur = $this->pdo->prepare("SELECT manual_fields FROM players WHERE id = ?");
        $cur->execute([$id]);
        $manual = array_filter(explode(',', (string)$cur->fetchColumn()));
        $set = [];
        $params = [':id' => $id, ':src' => $source];
        foreach (self::PROFILE_FIELDS as $f) {
            if (!array_key_exists($f, $data) || in_array($f, $manual, true)) continue;
            $v = self::clean($f, $data[$f]);
            if ($v === null) continue;
            $set[] = "$f = :$f";
            $params[":$f"] = $v;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare("UPDATE players SET " . implode(', ', array_merge($set, ["source = :src", "source_updated_at = date('now')", "updated_at = datetime('now')"])) . " WHERE id = :id")
                ->execute($params);
            if ($history) {
                $this->pdo->prepare("DELETE FROM player_history WHERE player_id = ? AND manual = 0 AND source = ?")->execute([$id, $source]);
                foreach ($history as $h) $this->addHistory($id, $h, false, $source);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['fields' => count($set), 'history' => count($history), 'skipped_manual' => $manual];
    }

    // ------------------------------------------------------------ Kader aus Vorsaison
    /** Saison-Startjahr aus Liganamen ("… 26/27" -> 2026, "… 2004/2005" -> 2004) */
    private static function seasonYear(string $leagueName): int
    {
        if (!preg_match('/(\d{2}|\d{4})\/(\d{2}|\d{4})\s*$/', $leagueName, $m)) return 0;
        $y = (int)$m[1];
        return $y < 100 ? 2000 + $y : $y;
    }

    /** Findet das gleichnamige Team der juengsten Vorsaison mit Spielern */
    public function findPreviousTeam(int $teamId): ?array
    {
        $st = $this->pdo->prepare("SELECT t.id, t.name, l.name AS league_name FROM teams t JOIN leagues l ON l.id = t.league_id WHERE t.id = ?");
        $st->execute([$teamId]);
        $target = $st->fetch();
        if (!$target) throw new InvalidArgumentException('Team nicht gefunden');
        $year = self::seasonYear($target['league_name']);
        $st = $this->pdo->prepare("SELECT t.id, t.name, l.name AS league_name, (SELECT COUNT(*) FROM players p WHERE p.team_id = t.id) AS n
                                   FROM teams t JOIN leagues l ON l.id = t.league_id
                                   WHERE t.id <> ? AND t.name = ?");
        $st->execute([$teamId, $target['name']]);
        $best = null;
        foreach ($st->fetchAll() as $c) {
            $y = self::seasonYear($c['league_name']);
            if ($c['n'] == 0 || ($year && $y >= $year)) continue;
            if (!$best || $y > $best['year']) $best = $c + ['year' => $y];
        }
        return $best;
    }

    /** Kopiert Spieler (inkl. Profil und Stationen) aus der Vorsaison; vorhandene Namen werden uebersprungen */
    public function copySquadFromPrevious(int $teamId): array
    {
        $prev = $this->findPreviousTeam($teamId);
        if (!$prev) return ['copied' => 0, 'skipped' => 0, 'source' => null];
        $existing = $this->pdo->prepare("SELECT name FROM players WHERE team_id = ?");
        $existing->execute([$teamId]);
        // Vergleich in PHP: SQLite lower() kennt keine Umlaute
        $have = array_flip(array_map(fn($n) => mb_strtolower(trim($n)), $existing->fetchAll(PDO::FETCH_COLUMN)));
        $usedNumbers = $this->pdo->prepare("SELECT number FROM players WHERE team_id = ? AND number IS NOT NULL");
        $usedNumbers->execute([$teamId]);
        $numbers = array_flip($usedNumbers->fetchAll(PDO::FETCH_COLUMN));

        $src = $this->pdo->prepare("SELECT * FROM players WHERE team_id = ?");
        $src->execute([$prev['id']]);
        $cols = array_merge(['name', 'number', 'position'], self::PROFILE_FIELDS, ['source', 'source_updated_at', 'manual_fields']);
        $ins = $this->pdo->prepare("INSERT INTO players (team_id, " . implode(', ', $cols) . ", updated_at) VALUES (?" . str_repeat(', ?', count($cols)) . ", datetime('now'))");
        $hist = $this->pdo->prepare("INSERT INTO player_history (player_id, club, from_date, to_date, note, source, manual)
                                     SELECT ?, club, from_date, to_date, note, source, manual FROM player_history WHERE player_id = ?");
        $copied = $skipped = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($src->fetchAll() as $p) {
                $key = mb_strtolower(trim($p['name']));
                if (isset($have[$key])) { $skipped++; continue; }
                $have[$key] = true; // auch Doppelte innerhalb der Quelle nur einmal
                if ($p['number'] !== null && isset($numbers[$p['number']])) $p['number'] = null; // Nummer schon belegt
                $vals = [$teamId];
                foreach ($cols as $c) $vals[] = $p[$c];
                $ins->execute($vals);
                $newId = (int)$this->pdo->lastInsertId();
                $hist->execute([$newId, $p['id']]);
                if ($p['number'] !== null) $numbers[$p['number']] = true;
                $copied++;
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        return ['copied' => $copied, 'skipped' => $skipped, 'source' => $prev['league_name']];
    }

    // ------------------------------------------------------------ Validierung
    private static function clean(string $field, $v)
    {
        if ($v === null) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        switch ($field) {
            case 'birth_date':
                if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                if (!preg_match('/^(19|20)\d{2}(-\d{2}-\d{2})?$/', $v)) throw new InvalidArgumentException('Geburtsdatum als TT.MM.JJJJ oder Jahrgang JJJJ angeben.');
                return $v;
            case 'joined_at': case 'from_date': case 'to_date':
                if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                if (preg_match('/^(\d{1,2})\/(\d{4})$/', $v, $m)) $v = sprintf('%04d-%02d', $m[2], $m[1]);
                if (!preg_match('/^(19|20)\d{2}(-\d{2}(-\d{2})?)?$/', $v)) throw new InvalidArgumentException('Datum als JJJJ, MM/JJJJ oder TT.MM.JJJJ angeben.');
                return $v;
            case 'height_cm':
                $n = (int)$v; if ($n < 120 || $n > 230) throw new InvalidArgumentException('Größe in cm (120–230).'); return $n;
            case 'foot':
                return in_array($v, ['links', 'rechts', 'beidfüßig'], true) ? $v : null;
            case 'external_url':
                if (!preg_match('#^https://[^\s<>"]+$#', $v)) throw new InvalidArgumentException('Link muss mit https:// beginnen.');
                return mb_substr($v, 0, 300);
            default:
                return mb_substr(strip_tags($v), 0, $field === 'notes' ? 1000 : 80);
        }
    }
}
