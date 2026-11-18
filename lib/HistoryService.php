<?php
/**
 * Historie je Wettbewerb: Ewige Tabelle, Meister/Pokalsieger, Platzierungen, Rekorde.
 *
 * Grundlage ist ein importierter Altbestand (scripts/history-import.php, JSON) bis zur Saison
 * `baseline_until` (Jahr, in dem die Saison endet: 2026 = 2025/26). Spaetere Saisons werden im Admin
 * "uebernommen": Bilanz, Platz und Meister werden dann aus den Spielen der Ligen berechnet, deren
 * Dateiname auf `league_pattern` passt. Rekorde werden immer aus den erfassten Einzelspielen berechnet.
 */
require_once __DIR__ . '/LmoDatabase.php';

class HistoryService
{
    private PDO $pdo;
    private string $cacheDir;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->cacheDir = __DIR__ . '/../data/cache';
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_competitions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
            league_pattern TEXT NOT NULL, baseline_until INTEGER NOT NULL DEFAULT 0, source TEXT, notes TEXT,
            sort_order INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $cols = array_column($pdo->query("PRAGMA table_info(history_competitions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('title_counts', $cols, true)) $pdo->exec("ALTER TABLE history_competitions ADD COLUMN title_counts TEXT");
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_clubs (
            id INTEGER PRIMARY KEY AUTOINCREMENT, competition_id INTEGER NOT NULL, name TEXT NOT NULL,
            played INTEGER DEFAULT 0, won INTEGER DEFAULT 0, drawn INTEGER DEFAULT 0, lost INTEGER DEFAULT 0,
            gf INTEGER DEFAULT 0, ga INTEGER DEFAULT 0, points INTEGER DEFAULT 0,
            years TEXT, placements TEXT,
            FOREIGN KEY(competition_id) REFERENCES history_competitions(id) ON DELETE CASCADE)");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_history_clubs_name ON history_clubs(competition_id, name)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_team_map (
            competition_id INTEGER NOT NULL, team_name TEXT NOT NULL, club_id INTEGER NOT NULL,
            PRIMARY KEY(competition_id, team_name))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_titles (
            id INTEGER PRIMARY KEY AUTOINCREMENT, competition_id INTEGER NOT NULL, season INTEGER NOT NULL,
            champion TEXT, cup TEXT, note TEXT, record TEXT, source TEXT DEFAULT 'import')");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_history_titles ON history_titles(competition_id, season)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_season_stats (
            competition_id INTEGER NOT NULL, season INTEGER NOT NULL, club_id INTEGER NOT NULL,
            played INTEGER, won INTEGER, drawn INTEGER, lost INTEGER, gf INTEGER, ga INTEGER, points INTEGER, place INTEGER,
            PRIMARY KEY(competition_id, season, club_id))");
        $pdo->exec("CREATE TABLE IF NOT EXISTS history_adopted (
            competition_id INTEGER NOT NULL, season INTEGER NOT NULL, adopted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            adopted_by TEXT, PRIMARY KEY(competition_id, season))");
        $done = true;
    }

    // ------------------------------------------------------------------ Wettbewerbe

    public function competitions(): array
    {
        return $this->pdo->query("SELECT id, slug, name, baseline_until, source FROM history_competitions ORDER BY sort_order, name")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function competition(?string $slug): ?array
    {
        $st = $slug
            ? $this->pdo->prepare("SELECT * FROM history_competitions WHERE slug = ?")
            : $this->pdo->prepare("SELECT * FROM history_competitions ORDER BY sort_order, name LIMIT 1");
        $st->execute($slug ? [$slug] : []);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;
        $c['notes'] = json_decode((string)$c['notes'], true) ?: [];
        return $c;
    }

    /** Saison-Endjahr aus dem Liganamen ("... 26/27" oder "... 2001/2002") */
    public static function seasonOf(string $leagueName): ?int
    {
        if (preg_match('#(\d{2})/(\d{2})\s*$#', $leagueName, $m)) {
            $end = (int)$m[2];
            return $end + ($end >= 50 ? 1900 : 2000);
        }
        if (preg_match('#\d{2}(\d{2})/(\d{2})(\d{2})\s*$#', $leagueName, $m)) return (int)($m[2] . $m[3]);
        return null;
    }

    public static function seasonLabel(int $end): string
    {
        return sprintf('%d/%02d', $end - 1, $end % 100);
    }

    /** Ligen des Wettbewerbs, gruppiert nach Saison-Endjahr */
    public function leaguesBySeason(array $comp): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT id, file, name FROM leagues") as $l) {
            if (!@preg_match('#' . str_replace('#', '\#', $comp['league_pattern']) . '#i', $l['file'])) continue;
            $s = self::seasonOf($l['name']);
            if ($s) $out[$s][] = $l;
        }
        ksort($out);
        return $out;
    }

    // ------------------------------------------------------------------ Vereine / Zuordnung

    private function clubsById(int $compId): array
    {
        $st = $this->pdo->prepare("SELECT * FROM history_clubs WHERE competition_id = ?");
        $st->execute([$compId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $c['years'] = json_decode((string)$c['years'], true) ?: [];
            $c['placements'] = json_decode((string)$c['placements'], true) ?: [];
            $out[(int)$c['id']] = $c;
        }
        return $out;
    }

    private function teamMap(int $compId): array
    {
        $st = $this->pdo->prepare("SELECT team_name, club_id FROM history_team_map WHERE competition_id = ?");
        $st->execute([$compId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Vereinsnamen aus den Ligen ohne Zuordnung zu einem Historie-Verein */
    public function unmappedTeams(array $comp): array
    {
        $map = $this->teamMap((int)$comp['id']);
        $names = [];
        foreach ($this->leaguesBySeason($comp) as $season => $leagues) {
            foreach ($leagues as $l) {
                $st = $this->pdo->prepare("SELECT name FROM teams WHERE league_id = ?");
                $st->execute([$l['id']]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                    if (!isset($map[$n])) $names[$n] = max($names[$n] ?? 0, $season);
                }
            }
        }
        ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return array_map(fn($n, $s) => ['team_name' => $n, 'last_season' => self::seasonLabel($s)], array_keys($names), $names);
    }

    public function mapTeam(array $comp, string $teamName, ?int $clubId, ?string $newClub): void
    {
        $cid = (int)$comp['id'];
        if ($newClub !== null && trim($newClub) !== '') {
            $this->pdo->prepare("INSERT OR IGNORE INTO history_clubs (competition_id, name, years, placements) VALUES (?, ?, '[]', '{}')")
                ->execute([$cid, trim($newClub)]);
            $st = $this->pdo->prepare("SELECT id FROM history_clubs WHERE competition_id = ? AND name = ?");
            $st->execute([$cid, trim($newClub)]);
            $clubId = (int)$st->fetchColumn();
        }
        if (!$clubId) throw new InvalidArgumentException('Bitte einen Verein wählen.');
        $chk = $this->pdo->prepare("SELECT 1 FROM history_clubs WHERE id = ? AND competition_id = ?");
        $chk->execute([$clubId, $cid]);
        if (!$chk->fetchColumn()) throw new InvalidArgumentException('Unbekannter Verein.');
        $this->pdo->prepare("INSERT OR REPLACE INTO history_team_map (competition_id, team_name, club_id) VALUES (?, ?, ?)")
            ->execute([$cid, $teamName, $clubId]);
        $this->clearCache($comp);
    }

    // ------------------------------------------------------------------ Saison-Bilanz aus Spielen

    /**
     * Bilanz je Historie-Verein fuer eine Saison (alle Ligen des Wettbewerbs mit diesem Endjahr addiert).
     * Platz nur, wenn die Saison aus genau einer Liga besteht (sonst null).
     */
    public function seasonStats(array $comp, int $season): array
    {
        $leagues = $this->leaguesBySeason($comp)[$season] ?? [];
        $map = $this->teamMap((int)$comp['id']);
        $stats = []; $open = 0; $total = 0; $unmapped = [];
        foreach ($leagues as $l) {
            $teams = $this->pdo->prepare("SELECT id, name FROM teams WHERE league_id = ?");
            $teams->execute([$l['id']]);
            $tclub = [];
            foreach ($teams->fetchAll(PDO::FETCH_ASSOC) as $t) {
                if (!isset($map[$t['name']])) { $unmapped[$t['name']] = true; continue; }
                $tclub[(int)$t['id']] = $map[$t['name']];
                $stats[$map[$t['name']]] ??= ['played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'gf' => 0, 'ga' => 0, 'points' => 0, 'correction' => 0];
            }
            $ms = $this->pdo->prepare("SELECT home_team_id h, guest_team_id g, home_goals hg, guest_goals gg FROM matches WHERE league_id = ?");
            $ms->execute([$l['id']]);
            foreach ($ms->fetchAll(PDO::FETCH_ASSOC) as $m) {
                $total++;
                if ($m['hg'] === null || $m['gg'] === null || (int)$m['hg'] < 0 || (int)$m['gg'] < 0) { $open++; continue; }
                foreach ([[$m['h'], $m['hg'], $m['gg']], [$m['g'], $m['gg'], $m['hg']]] as [$t, $f, $a]) {
                    if (!isset($tclub[(int)$t])) continue;
                    $s = &$stats[$tclub[(int)$t]];
                    $s['played']++; $s['gf'] += (int)$f; $s['ga'] += (int)$a;
                    if ($f > $a) { $s['won']++; $s['points'] += 3; } elseif ($f == $a) { $s['drawn']++; $s['points']++; } else { $s['lost']++; }
                    unset($s);
                }
            }
            $pc = $this->pdo->prepare("SELECT team_id, SUM(points) FROM point_corrections WHERE league_id = ? GROUP BY team_id");
            try { $pc->execute([$l['id']]); $corr = $pc->fetchAll(PDO::FETCH_KEY_PAIR); } catch (Throwable $e) { $corr = []; }
            foreach ($corr as $tid => $p) if (isset($tclub[(int)$tid])) $stats[$tclub[(int)$tid]]['correction'] += (int)$p;
        }
        // Platz nach Punkten (inkl. Korrektur), Tordifferenz, Toren – nur bei einer Liga je Saison
        $ids = array_keys($stats);
        usort($ids, fn($a, $b) => [($stats[$b]['points'] + $stats[$b]['correction']), $stats[$b]['gf'] - $stats[$b]['ga'], $stats[$b]['gf']]
            <=> [($stats[$a]['points'] + $stats[$a]['correction']), $stats[$a]['gf'] - $stats[$a]['ga'], $stats[$a]['gf']]);
        foreach ($ids as $i => $id) $stats[$id]['place'] = count($leagues) === 1 ? $i + 1 : null;
        return ['stats' => $stats, 'order' => $ids, 'leagues' => $leagues, 'open' => $open, 'total' => $total, 'unmapped' => array_keys($unmapped)];
    }

    // ------------------------------------------------------------------ Saison uebernehmen

    public function adoptableSeasons(array $comp): array
    {
        $adopted = $this->adoptedSeasons((int)$comp['id']);
        $out = [];
        foreach (array_keys($this->leaguesBySeason($comp)) as $s) {
            if ($s <= (int)$comp['baseline_until']) continue;
            $info = $this->seasonStats($comp, $s);
            $out[] = ['season' => $s, 'label' => self::seasonLabel($s), 'adopted' => isset($adopted[$s]) ? $adopted[$s] : null,
                'open_matches' => $info['open'], 'total_matches' => $info['total'], 'unmapped' => $info['unmapped'],
                'leagues' => array_column($info['leagues'], 'name')];
        }
        return $out;
    }

    private function adoptedSeasons(int $compId): array
    {
        $st = $this->pdo->prepare("SELECT season, adopted_at, adopted_by FROM history_adopted WHERE competition_id = ?");
        $st->execute([$compId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['season']] = $r;
        return $out;
    }

    public function adopt(array $comp, int $season, ?string $cup, string $by, bool $force = false): array
    {
        if ($season <= (int)$comp['baseline_until']) throw new InvalidArgumentException('Diese Saison ist bereits im Altbestand enthalten.');
        $info = $this->seasonStats($comp, $season);
        if (!$info['leagues']) throw new InvalidArgumentException('Keine Liga für diese Saison gefunden.');
        if ($info['unmapped']) throw new InvalidArgumentException('Erst alle Vereine zuordnen: ' . implode(', ', $info['unmapped']));
        if ($info['open'] > 0 && !$force) throw new InvalidArgumentException("Es sind noch {$info['open']} Spiele ohne Ergebnis.");
        $cid = (int)$comp['id'];
        $this->pdo->beginTransaction();
        try {
            $this->removeSeason($cid, $season);
            $ins = $this->pdo->prepare("INSERT INTO history_season_stats (competition_id, season, club_id, played, won, drawn, lost, gf, ga, points, place) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            foreach ($info['stats'] as $club => $s) {
                $ins->execute([$cid, $season, $club, $s['played'], $s['won'], $s['drawn'], $s['lost'], $s['gf'], $s['ga'], $s['points'], $s['place']]);
            }
            $champ = null;
            if (count($info['leagues']) === 1 && $info['order']) {
                $cs = $this->pdo->prepare("SELECT name FROM history_clubs WHERE id = ?");
                $cs->execute([$info['order'][0]]);
                $champ = $cs->fetchColumn() ?: null;
                $s = $info['stats'][$info['order'][0]];
                $rec = json_encode(['won' => $s['won'], 'drawn' => $s['drawn'], 'lost' => $s['lost'], 'points' => $s['points'] + $s['correction'], 'gf' => $s['gf'], 'ga' => $s['ga']]);
            }
            $this->pdo->prepare("INSERT INTO history_titles (competition_id, season, champion, cup, note, record, source) VALUES (?,?,?,?,?,?, 'db')")
                ->execute([$cid, $season, $champ, ($cup !== null && trim($cup) !== '') ? trim($cup) : null, null, $champ ? $rec : null]);
            $this->pdo->prepare("INSERT INTO history_adopted (competition_id, season, adopted_by) VALUES (?,?,?)")->execute([$cid, $season, $by]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->clearCache($comp);
        return ['season' => $season, 'clubs' => count($info['stats']), 'champion' => $champ];
    }

    public function unadopt(array $comp, int $season): void
    {
        $this->pdo->beginTransaction();
        $this->removeSeason((int)$comp['id'], $season);
        $this->pdo->commit();
        $this->clearCache($comp);
    }

    private function removeSeason(int $cid, int $season): void
    {
        foreach (["DELETE FROM history_season_stats WHERE competition_id = ? AND season = ?",
                  "DELETE FROM history_titles WHERE competition_id = ? AND season = ? AND source = 'db'",
                  "DELETE FROM history_adopted WHERE competition_id = ? AND season = ?"] as $sql) {
            $this->pdo->prepare($sql)->execute([$cid, $season]);
        }
    }

    // ------------------------------------------------------------------ Ansichten

    /** Ewige Tabelle: Altbestand + uebernommene Saisons (+ optional laufende Saison, nicht gespeichert) */
    public function eternalTable(array $comp, bool $live = false): array
    {
        $cid = (int)$comp['id'];
        $clubs = $this->clubsById($cid);
        $rows = [];
        foreach ($clubs as $id => $c) {
            $rows[$id] = ['id' => $id, 'name' => $c['name'], 'seasons' => count($c['years']), 'first' => $c['years'] ? min($c['years']) : null,
                'last' => $c['years'] ? max($c['years']) : null, 'played' => (int)$c['played'], 'won' => (int)$c['won'], 'drawn' => (int)$c['drawn'],
                'lost' => (int)$c['lost'], 'gf' => (int)$c['gf'], 'ga' => (int)$c['ga'], 'points' => (int)$c['points'], 'live' => false];
        }
        $st = $this->pdo->prepare("SELECT * FROM history_season_stats WHERE competition_id = ?");
        $st->execute([$cid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $this->addSeason($rows[(int)$s['club_id']], $s, (int)$s['season']);
        }
        $current = null;
        if ($live) {
            $adopted = $this->adoptedSeasons($cid);
            $seasons = array_keys($this->leaguesBySeason($comp));
            $cur = $seasons ? max($seasons) : null;
            if ($cur && $cur > (int)$comp['baseline_until'] && !isset($adopted[$cur])) {
                $current = self::seasonLabel($cur);
                foreach ($this->seasonStats($comp, $cur)['stats'] as $club => $s) {
                    if (!isset($rows[$club])) continue;
                    $this->addSeason($rows[$club], $s, $cur);
                    $rows[$club]['live'] = true;
                }
            }
        }
        // Vereine der aktuellen Saison markieren
        $seasons = $this->leaguesBySeason($comp);
        $curSeason = $seasons ? max(array_keys($seasons)) : null;
        $inCurrent = [];
        if ($curSeason) {
            $map = $this->teamMap($cid);
            foreach ($seasons[$curSeason] as $l) {
                $t = $this->pdo->prepare("SELECT name FROM teams WHERE league_id = ?");
                $t->execute([$l['id']]);
                foreach ($t->fetchAll(PDO::FETCH_COLUMN) as $n) if (isset($map[$n])) $inCurrent[$map[$n]] = true;
            }
        }
        $rows = array_values(array_filter($rows, fn($r) => $r['played'] > 0 || $r['seasons'] > 0 || isset($inCurrent[$r['id']])));
        $crests = $this->crestKeys($cid);
        foreach ($rows as &$r) {
            $r['diff'] = $r['gf'] - $r['ga'];
            $r['avg'] = $r['played'] ? round($r['points'] / $r['played'], 3) : 0;
            $r['current'] = isset($inCurrent[$r['id']]);
            $r['crest'] = $crests[$r['id']] ?? null;
            $r['first_label'] = $r['first'] ? self::seasonLabel($r['first']) : null;
            $r['last_label'] = $r['last'] ? self::seasonLabel($r['last']) : null;
        }
        unset($r);
        usort($rows, fn($a, $b) => [$b['points'], $b['diff'], $b['gf']] <=> [$a['points'], $a['diff'], $a['gf']]);
        foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
        return ['rows' => $rows, 'live_season' => $current, 'current_season' => $curSeason ? self::seasonLabel($curSeason) : null,
            'baseline' => self::seasonLabel((int)$comp['baseline_until'])];
    }

    /** Wappen-Schluessel je Historie-Verein (aus den zugeordneten Vereinsnamen der Ligen) */
    private function crestKeys(int $cid): array
    {
        $dir = __DIR__ . '/../img/teams';
        $out = [];
        foreach ($this->teamMap($cid) as $team => $club) {
            $key = preg_replace('/[^a-zA-Z0-9]/', '', $team);
            if ($key === '') continue;
            if (is_file("$dir/hd/$key.png") || is_file("$dir/$key.gif")) $out[$club] = $key;
        }
        return $out;
    }

    private function addSeason(?array &$row, array $s, int $season): void
    {
        if ($row === null) return;
        foreach (['played', 'won', 'drawn', 'lost', 'gf', 'ga', 'points'] as $k) $row[$k] += (int)$s[$k];
        if ((int)$s['played'] > 0) {
            $row['seasons']++;
            $row['first'] = $row['first'] ? min($row['first'], $season) : $season;
            $row['last'] = max((int)$row['last'], $season);
        }
    }

    /**
     * Meister und Pokalsieger je Saison, dazu Zaehlung (Rekordmeister/-pokalsieger, Double).
     * Gibt es im Altbestand eine eigene Zaehlung (title_counts), ist sie massgeblich; uebernommene Saisons
     * werden dazugezaehlt. Sonst wird aus der Liste gezaehlt.
     */
    public function titles(array $comp): array
    {
        $st = $this->pdo->prepare("SELECT season, champion, cup, note, record, source FROM history_titles WHERE competition_id = ? ORDER BY season, id");
        $st->execute([(int)$comp['id']]);
        $list = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $t['record'] = json_decode((string)$t['record'], true);
            $t['label'] = self::seasonLabel((int)$t['season']);
            $list[] = $t;
        }
        $base = json_decode((string)($comp['title_counts'] ?? ''), true);
        $champs = []; $cups = []; $doubles = [];
        $add = function (array &$arr, string $name, ?string $season) {
            $k = self::titleKey($name);
            foreach ($arr as &$e) {
                if (self::titleKey($e['name']) === $k || in_array($k, $e['keys'] ?? [], true)) { $e['count']++; if ($season) $e['seasons'][] = $season; return; }
            }
            unset($e);
            $arr[] = ['name' => $name, 'count' => 1, 'seasons' => $season ? [$season] : []];
        };
        if (is_array($base)) {
            foreach (['champions' => &$champs, 'cups' => &$cups, 'doubles' => &$doubles] as $key => &$arr) {
                foreach ($base[$key] ?? [] as $e) $arr[] = ['name' => $e['name'], 'count' => (int)$e['count'], 'seasons' => $e['seasons'] ?? []];
            }
            unset($arr);
            foreach ($list as $t) {
                if ($t['source'] !== 'db') continue;
                if ($t['champion']) $add($champs, $t['champion'], $t['label']);
                if ($t['cup']) $add($cups, $t['cup'], $t['label']);
                if ($t['champion'] && $t['cup'] && self::titleKey($t['champion']) === self::titleKey($t['cup'])) $add($doubles, $t['champion'], $t['label']);
            }
        } else {
            foreach ($list as $t) {
                if ($t['champion']) $add($champs, $t['champion'], $t['label']);
                if ($t['cup']) $add($cups, $t['cup'], $t['label']);
                if ($t['champion'] && $t['cup'] && self::titleKey($t['champion']) === self::titleKey($t['cup'])) $add($doubles, $t['champion'], $t['label']);
            }
        }
        $sort = fn($a, $b) => $b['count'] <=> $a['count'];
        usort($champs, $sort); usort($cups, $sort); usort($doubles, $sort);
        return ['list' => $list, 'champions' => $champs, 'cups' => $cups, 'doubles' => $doubles];
    }

    /** Vereinsnamen fuer die Titelzaehlung vereinheitlichen ("Eimsbuetteler TV  (ETV)" = "Eimsbuetteler TV") */
    private static function titleKey(string $n): string
    {
        $n = preg_replace('/\s*\(.*?\)\s*/', ' ', $n);
        $n = preg_replace('/\b(hamburg|hh|ev|e\.v\.)\b/iu', '', $n);
        // Vereinskuerzel am Anfang ignorieren ("SC Concordia" = "Concordia")
        $n = preg_replace('/^\s*(sc|fc|sv|tus|tsv|vfl|usc|fsv|ssv|tsg|tura)\s+/iu', '', $n);
        return preg_replace('/[^a-z0-9]/', '', strtolower(strtr($n, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'])));
    }

    /** Platzierungen je Verein (Altbestand + uebernommene Saisons) */
    public function club(array $comp, int $clubId): ?array
    {
        $clubs = $this->clubsById((int)$comp['id']);
        if (!isset($clubs[$clubId])) return null;
        $c = $clubs[$clubId];
        $seasons = [];
        foreach ($c['placements'] as $y => $p) $seasons[(int)$y] = ['season' => (int)$y, 'label' => self::seasonLabel((int)$y), 'place' => $p];
        foreach ($c['years'] as $y) $seasons[(int)$y] ??= ['season' => (int)$y, 'label' => self::seasonLabel((int)$y), 'place' => null];
        $st = $this->pdo->prepare("SELECT * FROM history_season_stats WHERE competition_id = ? AND club_id = ?");
        $st->execute([(int)$comp['id'], $clubId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $y = (int)$s['season'];
            $seasons[$y] = ['season' => $y, 'label' => self::seasonLabel($y), 'place' => $s['place'] !== null ? (string)$s['place'] : null,
                'played' => (int)$s['played'], 'points' => (int)$s['points'], 'gf' => (int)$s['gf'], 'ga' => (int)$s['ga']];
        }
        ksort($seasons);
        $names = array_keys(array_filter($this->teamMap((int)$comp['id']), fn($id) => $id === $clubId));
        return ['id' => $clubId, 'name' => $c['name'], 'team_names' => $names, 'crest' => $this->crestKeys((int)$comp['id'])[$clubId] ?? null,
            'totals' => array_values(array_filter($this->eternalTable($comp)['rows'], fn($r) => $r['id'] === $clubId))[0] ?? null,
            'seasons' => array_values($seasons)];
    }

    /** Rekorde aus allen erfassten Spielen des Wettbewerbs (zwischengespeichert) */
    public function records(array $comp, int $limit = 10): array
    {
        $seasons = $this->leaguesBySeason($comp);
        $ids = [];
        foreach ($seasons as $s => $ls) foreach ($ls as $l) $ids[(int)$l['id']] = $s;
        if (!$ids) return ['matches_counted' => 0];
        $in = implode(',', array_keys($ids));
        $sig = $this->pdo->query("SELECT COUNT(*) || '-' || COALESCE(SUM(COALESCE(home_goals,0)*31 + COALESCE(guest_goals,0)), 0) || '-' || COALESCE(MAX(id),0) FROM matches WHERE league_id IN ($in)")->fetchColumn();
        $map = $this->teamMap((int)$comp['id']);
        $cacheFile = "$this->cacheDir/history-{$comp['slug']}-records.json";
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if ($cached && ($cached['sig'] ?? '') === $sig . '-' . md5(json_encode($map)) . "-$limit") return $cached['data'];

        $clubs = $this->clubsById((int)$comp['id']);
        $clubName = function (string $team) use ($map, $clubs) {
            return isset($map[$team], $clubs[$map[$team]]) ? $clubs[$map[$team]]['name'] : $team;
        };
        $rows = $this->pdo->query("SELECT m.league_id, m.round_nr, m.match_date, m.home_goals hg, m.guest_goals gg, th.name home, tg.name guest
            FROM matches m JOIN teams th ON th.id = m.home_team_id JOIN teams tg ON tg.id = m.guest_team_id
            WHERE m.league_id IN ($in) AND m.home_goals IS NOT NULL AND m.guest_goals IS NOT NULL AND m.home_goals >= 0 AND m.guest_goals >= 0")
            ->fetchAll(PDO::FETCH_ASSOC);
        $games = [];
        foreach ($rows as $r) {
            $season = $ids[(int)$r['league_id']];
            $d = DateTime::createFromFormat('d.m.Y', (string)$r['match_date']);
            $games[] = ['season' => $season, 'round' => (int)$r['round_nr'], 'date' => $d ? $d->format('Y-m-d') : null,
                'date_label' => $r['match_date'] ?: null, 'season_label' => self::seasonLabel($season),
                'home' => $clubName($r['home']), 'guest' => $clubName($r['guest']), 'hg' => (int)$r['hg'], 'gg' => (int)$r['gg']];
        }
        $pick = fn($g) => array_intersect_key($g, array_flip(['season_label', 'date_label', 'round', 'home', 'guest', 'hg', 'gg']));

        $byWin = array_filter($games, fn($g) => $g['hg'] !== $g['gg']);
        usort($byWin, fn($a, $b) => [abs($b['hg'] - $b['gg']), max($b['hg'], $b['gg'])] <=> [abs($a['hg'] - $a['gg']), max($a['hg'], $a['gg'])] ?: strcmp((string)$a['date'], (string)$b['date']));
        $homeWins = array_values(array_filter($byWin, fn($g) => $g['hg'] > $g['gg']));
        $awayWins = array_values(array_filter($byWin, fn($g) => $g['gg'] > $g['hg']));
        $goals = $games;
        usort($goals, fn($a, $b) => ($b['hg'] + $b['gg']) <=> ($a['hg'] + $a['gg']) ?: strcmp((string)$a['date'], (string)$b['date']));

        // Saisonwerte je Verein (alle Ligen einer Saison addiert)
        $seasonRows = [];
        foreach ($games as $g) {
            foreach ([[$g['home'], $g['hg'], $g['gg']], [$g['guest'], $g['gg'], $g['hg']]] as [$c, $f, $a]) {
                $k = $c . '|' . $g['season'];
                $seasonRows[$k] ??= ['club' => $c, 'season_label' => $g['season_label'], 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'gf' => 0, 'ga' => 0, 'points' => 0];
                $s = &$seasonRows[$k];
                $s['played']++; $s['gf'] += $f; $s['ga'] += $a;
                if ($f > $a) { $s['won']++; $s['points'] += 3; } elseif ($f == $a) { $s['drawn']++; $s['points']++; } else { $s['lost']++; }
                unset($s);
            }
        }
        $seasonRows = array_values(array_filter($seasonRows, fn($s) => $s['played'] >= 15));
        $top = function (array $list, callable $cmp) use ($limit) { usort($list, $cmp); return array_slice($list, 0, $limit); };

        // Serien je Verein in zeitlicher Folge (ueber Saisongrenzen, solange der Verein im Wettbewerb spielt)
        $seq = [];
        foreach ($games as $g) {
            $key = sprintf('%04d-%03d-%s', $g['season'], $g['round'], (string)$g['date']);
            $seq[$g['home']][] = [$key, $g['hg'] <=> $g['gg'], $g];
            $seq[$g['guest']][] = [$key, $g['gg'] <=> $g['hg'], $g];
        }
        $streaks = ['wins' => [], 'losses' => [], 'unbeaten' => [], 'winless' => []];
        foreach ($seq as $club => $list) {
            usort($list, fn($a, $b) => strcmp($a[0], $b[0]));
            foreach (['wins' => fn($r) => $r === 1, 'losses' => fn($r) => $r === -1, 'unbeaten' => fn($r) => $r >= 0, 'winless' => fn($r) => $r <= 0] as $type => $ok) {
                $best = 0; $cur = 0; $start = null; $bestRange = null;
                foreach ($list as $i => $e) {
                    if ($ok($e[1])) {
                        if ($cur === 0) $start = $e[2];
                        $cur++;
                        if ($cur > $best) { $best = $cur; $bestRange = [$start, $e[2]]; }
                    } else {
                        $cur = 0;
                    }
                }
                if ($best >= 3) {
                    $streaks[$type][] = ['club' => $club, 'length' => $best,
                        'from' => $bestRange[0]['season_label'] . ($bestRange[0]['date_label'] ? ', ' . $bestRange[0]['date_label'] : ''),
                        'to' => $bestRange[1]['season_label'] . ($bestRange[1]['date_label'] ? ', ' . $bestRange[1]['date_label'] : '')];
                }
            }
        }
        foreach ($streaks as $k => $l) { usort($l, fn($a, $b) => $b['length'] <=> $a['length'] ?: strcasecmp($a['club'], $b['club'])); $streaks[$k] = array_slice($l, 0, $limit); }

        $first = $seasons ? self::seasonLabel(min(array_keys($seasons))) : null;
        $data = [
            'since' => $first,
            'matches_counted' => count($games),
            'biggest_home_wins' => array_map($pick, array_slice($homeWins, 0, $limit)),
            'biggest_away_wins' => array_map($pick, array_slice($awayWins, 0, $limit)),
            'most_goals' => array_map($pick, array_slice($goals, 0, $limit)),
            'most_points_season' => $top($seasonRows, fn($a, $b) => $b['points'] <=> $a['points'] ?: $b['gf'] - $b['ga'] <=> $a['gf'] - $a['ga']),
            'best_avg_season' => $top($seasonRows, fn($a, $b) => $b['points'] / $b['played'] <=> $a['points'] / $a['played']),
            'most_goals_season' => $top($seasonRows, fn($a, $b) => $b['gf'] <=> $a['gf']),
            'fewest_against_season' => $top($seasonRows, fn($a, $b) => $a['ga'] / $a['played'] <=> $b['ga'] / $b['played']),
            'streaks' => $streaks,
        ];
        if (!is_dir($this->cacheDir)) @mkdir($this->cacheDir, 0755, true);
        @file_put_contents($cacheFile, json_encode(['sig' => $sig . '-' . md5(json_encode($map)) . "-$limit", 'data' => $data], JSON_UNESCAPED_UNICODE));
        return $data;
    }

    private function clearCache(array $comp): void
    {
        foreach (glob("$this->cacheDir/history-{$comp['slug']}-*.json") ?: [] as $f) @unlink($f);
    }

    // ------------------------------------------------------------------ Import

    /** Altbestand aus JSON (Format siehe scripts/history-import.php) importieren bzw. ersetzen */
    public function import(array $data, string $slug): array
    {
        foreach (['competition', 'league_pattern', 'baseline_until', 'clubs'] as $k) {
            if (!isset($data[$k])) throw new InvalidArgumentException("Feld '$k' fehlt.");
        }
        if (@preg_match('#' . str_replace('#', '\#', $data['league_pattern']) . '#', '') === false) throw new InvalidArgumentException('league_pattern ist kein gültiger regulärer Ausdruck.');
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("SELECT id FROM history_competitions WHERE slug = ?");
            $st->execute([$slug]);
            $cid = $st->fetchColumn();
            if ($cid) {
                $this->pdo->prepare("UPDATE history_competitions SET name = ?, league_pattern = ?, baseline_until = ?, source = ?, notes = ?, title_counts = ? WHERE id = ?")
                    ->execute([$data['competition'], $data['league_pattern'], (int)$data['baseline_until'], $data['source'] ?? null, json_encode($data['notes'] ?? [], JSON_UNESCAPED_UNICODE),
                        isset($data['title_counts']) ? json_encode($data['title_counts'], JSON_UNESCAPED_UNICODE) : null, $cid]);
                // Altbestand ersetzen; uebernommene Saisons und Zuordnungen bleiben (Vereine per Name wiedergefunden)
                $this->pdo->prepare("DELETE FROM history_titles WHERE competition_id = ? AND source = 'import'")->execute([$cid]);
            } else {
                $this->pdo->prepare("INSERT INTO history_competitions (slug, name, league_pattern, baseline_until, source, notes, title_counts) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$slug, $data['competition'], $data['league_pattern'], (int)$data['baseline_until'], $data['source'] ?? null, json_encode($data['notes'] ?? [], JSON_UNESCAPED_UNICODE),
                        isset($data['title_counts']) ? json_encode($data['title_counts'], JSON_UNESCAPED_UNICODE) : null]);
                $cid = $this->pdo->lastInsertId();
            }
            $cid = (int)$cid;
            $up = $this->pdo->prepare("INSERT INTO history_clubs (competition_id, name, played, won, drawn, lost, gf, ga, points, years, placements)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
                ON CONFLICT(competition_id, name) DO UPDATE SET played=excluded.played, won=excluded.won, drawn=excluded.drawn, lost=excluded.lost,
                gf=excluded.gf, ga=excluded.ga, points=excluded.points, years=excluded.years, placements=excluded.placements");
            foreach ($data['clubs'] as $c) {
                $years = array_values(array_filter(array_map('intval', $c['years'] ?? []), fn($y) => $y <= (int)$data['baseline_until']));
                $up->execute([$cid, trim($c['name']), (int)($c['played'] ?? 0), (int)($c['won'] ?? 0), (int)($c['drawn'] ?? 0), (int)($c['lost'] ?? 0),
                    (int)($c['gf'] ?? 0), (int)($c['ga'] ?? 0), (int)($c['points'] ?? 0), json_encode($years), json_encode((object)($c['placements'] ?? []), JSON_UNESCAPED_UNICODE)]);
            }
            $ti = $this->pdo->prepare("INSERT INTO history_titles (competition_id, season, champion, cup, note, record, source) VALUES (?,?,?,?,?,?, 'import')");
            foreach ($data['titles'] ?? [] as $t) {
                $ti->execute([$cid, (int)$t['season'], $t['champion'] ?? null, $t['cup'] ?? null, $t['note'] ?? null, isset($t['record']) ? json_encode($t['record']) : null]);
            }
            $clubIds = $this->pdo->prepare("SELECT name, id FROM history_clubs WHERE competition_id = ?");
            $clubIds->execute([$cid]);
            $byName = $clubIds->fetchAll(PDO::FETCH_KEY_PAIR);
            $tm = $this->pdo->prepare("INSERT OR REPLACE INTO history_team_map (competition_id, team_name, club_id) VALUES (?,?,?)");
            $mapped = 0;
            foreach ($data['team_map'] ?? [] as $team => $club) {
                if ($club && isset($byName[$club])) { $tm->execute([$cid, $team, $byName[$club]]); $mapped++; }
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->clearCache(['slug' => $slug]);
        @unlink($this->cacheDir . '/theme.json'); // Menuepunkt "Historie" erscheint ueber api/theme.php
        return ['competition_id' => $cid, 'clubs' => count($data['clubs']), 'titles' => count($data['titles'] ?? []), 'team_map' => $mapped];
    }
}
