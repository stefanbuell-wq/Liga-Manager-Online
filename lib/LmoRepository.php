<?php

require_once __DIR__ . '/LmoDatabase.php';

class LmoRepository
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
    }

    public function getAllLeagues()
    {
        $stmt = $this->pdo->query("SELECT file, name FROM leagues ORDER BY name");
        return $stmt->fetchAll();
    }

    public function getLeagueByFile($file)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM leagues WHERE file = :file");
        $stmt->execute([':file' => $file]);
        $league = $stmt->fetch();

        if (!$league) {
            throw new Exception("League not found: $file");
        }

        $league['options'] = json_decode($league['options'], true);
        return $league;
    }

    public function getTeams($leagueId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE league_id = :lid ORDER BY id");
        $stmt->execute([':lid' => $leagueId]);
        return $stmt->fetchAll();
    }

    public function getMatches($leagueId)
    {
        $stmt = $this->pdo->prepare("
            SELECT m.*, t1.name as home_name, t2.name as guest_name, m.match_note, m.report_url 
            FROM matches m
            LEFT JOIN teams t1 ON m.home_team_id = t1.id
            LEFT JOIN teams t2 ON m.guest_team_id = t2.id
            WHERE m.league_id = :lid
            ORDER BY m.round_nr, m.id
        ");
        $stmt->execute([':lid' => $leagueId]);
        return $stmt->fetchAll();
    }

    public function getLeagueDataFull($file)
    {
        $league = $this->getLeagueByFile($file);
        $teams = $this->getTeams($league['id']);
        $matches = $this->getMatches($league['id']);

        $teamsOut = [];
        $teamLookup = []; // dbId -> {id, name}
        foreach ($teams as $t) {
            $tOut = [
                'id' => $t['original_id'],
                'name' => $t['name'],
                'icon' => ''
            ];
            $teamsOut[$t['original_id']] = $tOut;
            $teamLookup[$t['id']] = $tOut;
        }

        $matchesOut = [];
        foreach ($matches as $m) {
            $r = $m['round_nr'];
            if (!isset($matchesOut[$r]))
                $matchesOut[$r] = [];

            $played = ($m['home_goals'] !== null && $m['guest_goals'] !== null && $m['home_goals'] >= 0);

            $matchesOut[$r][] = [
                'id' => $m['id'],
                'home_id' => $teamLookup[$m['home_team_id']]['id'],
                'guest_id' => $teamLookup[$m['guest_team_id']]['id'],
                'home' => $m['home_name'],
                'guest' => $m['guest_name'],
                'home_goals' => $played ? $m['home_goals'] : null,
                'guest_goals' => $played ? $m['guest_goals'] : null,
                'played' => $played,
                'date' => $m['match_date'] ?? null,
                'time' => $m['match_time'] ?? null,
                'match_note' => $m['match_note'] ?? null,
                'report_url' => $m['report_url'] ?? null,
                'has_news' => false
            ];

            // Check if report_url is actually a legacy news link
            if (!empty($m['report_url']) && preg_match('/fullnews\.php\?id=(\d+)/', $m['report_url'], $matches)) {
                $matchesOut[$r][count($matchesOut[$r]) - 1]['news_id'] = $matches[1];
                $matchesOut[$r][count($matchesOut[$r]) - 1]['has_news'] = true;
                $matchesOut[$r][count($matchesOut[$r]) - 1]['report_url'] = null; // Hide the raw report link
            }
        }

        // News-Verknüpfungen aus match_news Tabelle laden (viel effizienter)
        $matchNewsLinks = $this->getMatchIdsWithNews($league['id']);
        $newsLookup = [];
        foreach ($matchNewsLinks as $link) {
            $newsLookup[$link['match_id']] = $link['news_id'];
        }

        // Match-IDs den Ausgabe-Matches zuordnen
        // Wir brauchen eine Zuordnung von DB-Match-ID zu Round/Index
        $stmtMatchIds = $this->pdo->prepare("
            SELECT id, round_nr, home_team_id, guest_team_id
            FROM matches
            WHERE league_id = :lid
            ORDER BY round_nr, id
        ");
        $stmtMatchIds->execute([':lid' => $league['id']]);
        $dbMatches = $stmtMatchIds->fetchAll();

        // Durchlaufe DB-Matches und setze has_news basierend auf Verknüpfungen
        $matchIndex = [];
        foreach ($dbMatches as $dbMatch) {
            $r = $dbMatch['round_nr'];
            if (!isset($matchIndex[$r])) {
                $matchIndex[$r] = 0;
            }
            $idx = $matchIndex[$r]++;

            if (isset($matchesOut[$r][$idx]) && isset($newsLookup[$dbMatch['id']])) {
                $matchesOut[$r][$idx]['has_news'] = true;
                $matchesOut[$r][$idx]['news_id'] = $newsLookup[$dbMatch['id']];
            }
        }

        // Punktekorrekturen laden
        $corrections = $this->getPointCorrections($league['id']);

        // Calculate Table (On the fly)
        $table = $this->calculateTable($teamsOut, $matchesOut, $corrections);

        return [
            'league_id' => $league['id'],
            'options' => $league['options'],
            'teams' => $teamsOut,
            'matches' => $matchesOut,
            'table' => $table
        ];
    }

    /**
     * Holt alle News für ein bestimmtes Match
     */
    public function getNewsForMatch($matchId)
    {
        $stmt = $this->pdo->prepare("
            SELECT n.*, mn.confidence
            FROM news n
            JOIN match_news mn ON n.id = mn.news_id
            WHERE mn.match_id = :mid
            ORDER BY mn.confidence DESC, n.timestamp DESC
        ");
        $stmt->execute([':mid' => $matchId]);
        return $stmt->fetchAll();
    }

    /**
     * Holt eine einzelne News mit ID
     */
    public function getNewsById($newsId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM news WHERE id = :id");
        $stmt->execute([':id' => $newsId]);
        return $stmt->fetch();
    }

    /**
     * Holt alle News mit Paginierung
     */
    public function getNewsList($limit = 20, $offset = 0)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, title, short_content, author, timestamp, match_date
            FROM news
            ORDER BY timestamp DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Sucht News nach Teamnamen
     */
    public function searchNews($query, $limit = 20)
    {
        $stmt = $this->pdo->prepare("
            SELECT id, title, short_content, author, timestamp
            FROM news
            WHERE title LIKE :q OR content LIKE :q
            ORDER BY timestamp DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':q', "%$query%", PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Prüft ob ein Match verknüpfte News hat
     */
    public function matchHasNews($matchId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM match_news WHERE match_id = :mid");
        $stmt->execute([':mid' => $matchId]);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Holt Match-IDs mit verknüpften News für eine Liga
     */
    public function getMatchIdsWithNews($leagueId)
    {
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT mn.match_id, mn.news_id
            FROM match_news mn
            JOIN matches m ON mn.match_id = m.id
            WHERE m.league_id = :lid
        ");
        $stmt->execute([':lid' => $leagueId]);
        return $stmt->fetchAll();
    }

    // ==================== TEAM MANAGEMENT ====================

    /**
     * Holt alle Teams einer Liga mit zusätzlichen Infos
     */
    public function getTeamsForAdmin($leagueId)
    {
        $stmt = $this->pdo->prepare("
            SELECT t.*,
                   (SELECT COUNT(*) FROM matches WHERE home_team_id = t.id OR guest_team_id = t.id) as match_count
            FROM teams t
            WHERE t.league_id = :lid
            ORDER BY t.name
        ");
        $stmt->execute([':lid' => $leagueId]);
        return $stmt->fetchAll();
    }

    /**
     * Holt ein einzelnes Team
     */
    public function getTeamById($teamId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM teams WHERE id = :id");
        $stmt->execute([':id' => $teamId]);
        return $stmt->fetch();
    }

    /**
     * Erstellt ein neues Team
     */
    public function createTeam($leagueId, $name, $shortName = null, $logoFile = null)
    {
        // Nächste original_id für diese Liga ermitteln
        $stmt = $this->pdo->prepare("SELECT MAX(original_id) FROM teams WHERE league_id = :lid");
        $stmt->execute([':lid' => $leagueId]);
        $maxId = $stmt->fetchColumn() ?: 0;
        $newOriginalId = $maxId + 1;

        $stmt = $this->pdo->prepare("
            INSERT INTO teams (league_id, original_id, name, short_name, logo_file)
            VALUES (:lid, :oid, :name, :short, :logo)
        ");
        $stmt->execute([
            ':lid' => $leagueId,
            ':oid' => $newOriginalId,
            ':name' => $name,
            ':short' => $shortName,
            ':logo' => $logoFile
        ]);
        return $this->pdo->lastInsertId();
    }

    /**
     * Aktualisiert ein Team
     */
    public function updateTeam($teamId, $name, $shortName = null, $logoFile = null)
    {
        $stmt = $this->pdo->prepare("
            UPDATE teams
            SET name = :name, short_name = :short, logo_file = :logo
            WHERE id = :id
        ");
        return $stmt->execute([
            ':id' => $teamId,
            ':name' => $name,
            ':short' => $shortName,
            ':logo' => $logoFile
        ]);
    }

    /**
     * Löscht ein Team (nur wenn keine Spiele vorhanden)
     */
    public function deleteTeam($teamId)
    {
        // Prüfen ob Team in Spielen verwendet wird
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM matches
            WHERE home_team_id = :id OR guest_team_id = :id
        ");
        $stmt->execute([':id' => $teamId]);
        $matchCount = $stmt->fetchColumn();

        if ($matchCount > 0) {
            throw new Exception("Team kann nicht gelöscht werden - $matchCount Spiele vorhanden");
        }

        $stmt = $this->pdo->prepare("DELETE FROM teams WHERE id = :id");
        return $stmt->execute([':id' => $teamId]);
    }

    /**
     * Prüft ob ein Teamname in einer Liga bereits existiert
     */
    public function teamNameExists($leagueId, $name, $excludeTeamId = null)
    {
        $sql = "SELECT COUNT(*) FROM teams WHERE league_id = :lid AND name = :name";
        $params = [':lid' => $leagueId, ':name' => $name];

        if ($excludeTeamId) {
            $sql .= " AND id != :tid";
            $params[':tid'] = $excludeTeamId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Holt Punktekorrekturen für eine Liga
     */
    public function getPointCorrections($leagueId)
    {
        // Prüfe ob Tabelle existiert
        $tableExists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='point_corrections'")->fetch();
        if (!$tableExists) {
            return [];
        }

        $stmt = $this->pdo->prepare("
            SELECT pc.team_id, pc.points, pc.reason, t.name as team_name, t.original_id
            FROM point_corrections pc
            JOIN teams t ON pc.team_id = t.id
            WHERE pc.league_id = ?
        ");
        $stmt->execute([$leagueId]);
        $corrections = [];
        foreach ($stmt->fetchAll() as $row) {
            // Indexiere nach original_id (wie in $teamsOut verwendet)
            $corrections[$row['original_id']] = [
                'points' => $row['points'],
                'reason' => $row['reason'],
                'team_name' => $row['team_name']
            ];
        }
        return $corrections;
    }

    private function calculateTable($teams, $matches, $corrections = [])
    {
        // Re-use logic from LmoParser roughly
        $table = [];
        foreach ($teams as $id => $team) {
            $table[$id] = [
                'name' => $team['name'],
                'played' => 0,
                'won' => 0,
                'draw' => 0,
                'lost' => 0,
                'goals_for' => 0,
                'goals_against' => 0,
                'diff' => 0,
                'points' => 0
            ];
        }

        foreach ($matches as $round => $games) {
            foreach ($games as $game) {
                if (!$game['played'])
                    continue;

                $h = $game['home_id'];
                $g = $game['guest_id'];
                $hg = $game['home_goals'];
                $gg = $game['guest_goals'];

                if (isset($table[$h])) {
                    $table[$h]['played']++;
                    $table[$h]['goals_for'] += $hg;
                    $table[$h]['goals_against'] += $gg;
                    if ($hg > $gg) {
                        $table[$h]['won']++;
                        $table[$h]['points'] += 3;
                    } elseif ($hg == $gg) {
                        $table[$h]['draw']++;
                        $table[$h]['points'] += 1;
                    } else {
                        $table[$h]['lost']++;
                    }
                }
                if (isset($table[$g])) {
                    $table[$g]['played']++;
                    $table[$g]['goals_for'] += $gg;
                    $table[$g]['goals_against'] += $hg;
                    if ($gg > $hg) {
                        $table[$g]['won']++;
                        $table[$g]['points'] += 3;
                    } elseif ($gg == $hg) {
                        $table[$g]['draw']++;
                        $table[$g]['points'] += 1;
                    } else {
                        $table[$g]['lost']++;
                    }
                }
            }
        }

        foreach ($table as $id => &$row) {
            $row['diff'] = $row['goals_for'] - $row['goals_against'];
            $row['correction'] = 0;
            $row['correction_reason'] = null;

            // Punktekorrekturen anwenden (corrections sind nach original_id indexiert)
            if (isset($corrections[$id])) {
                $row['points'] += $corrections[$id]['points'];
                $row['correction'] = $corrections[$id]['points'];
                $row['correction_reason'] = $corrections[$id]['reason'];
            }
        }

        usort($table, function ($a, $b) {
            if ($a['points'] != $b['points'])
                return $b['points'] - $a['points'];
            if ($a['diff'] != $b['diff'])
                return $b['diff'] - $a['diff'];
            return $b['goals_for'] - $a['goals_for'];
        });

        return $table; // This returns array (0-indexed) sorted by rank
    }
}
