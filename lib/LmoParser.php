<?php

class LmoParser
{
    private $ligaDir;
    private $data = [];
    private $teams = [];
    private $matches = [];

    public function __construct($ligaDir)
    {
        $this->ligaDir = $ligaDir;
    }

    public function load($ligaFile)
    {
        // Sanitize filename to prevent path traversal
        $ligaFile = basename($ligaFile);
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
            throw new Exception("Invalid league file format: $ligaFile");
        }

        $filePath = $this->ligaDir . '/' . $ligaFile;

        if (!file_exists($filePath)) {
            throw new Exception("Liga file not found: $filePath");
        }

        // LMO files are Windows-1252 / ISO-8859-1 encoded.
        $content = file_get_contents($filePath);
        if (!mb_check_encoding($content, 'UTF-8') || !preg_match('/[\xC2-\xF4][\x80-\xBF]/', $content)) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $this->data = parse_ini_string($content, true, INI_SCANNER_RAW);

        if (!$this->data) {
            throw new Exception("Could not parse LMO file.");
        }

        $this->teams = [];
        $this->matches = [];
        $this->parseTeams();
        $this->parseMatches();

        return [
            'options' => $this->data['Options'] ?? [],
            'teams' => $this->teams,
            'matches' => $this->matches,
            'table' => $this->calculateTable()
        ];
    }

    private function parseTeams()
    {
        if (isset($this->data['Teams'])) {
            foreach ($this->data['Teams'] as $key => $value) {
                if (is_numeric($key)) {
                    $id = (int) $key;
                    $name = trim($value, '"');
                    $shortName = isset($this->data['Teamk'][$id]) ? trim($this->data['Teamk'][$id], '"') : '';
                    $midName = isset($this->data['Teamm'][$id]) ? trim($this->data['Teamm'][$id], '"') : '';
                    $teamSec = $this->data["Team$id"] ?? [];
                    $sp = isset($teamSec['SP']) ? (int) $teamSec['SP'] : 0;
                    $sm = isset($teamSec['SM']) ? (int) $teamSec['SM'] : 0;
                    $tor1 = isset($teamSec['TOR1']) ? (int) $teamSec['TOR1'] : 0;
                    $tor2 = isset($teamSec['TOR2']) ? (int) $teamSec['TOR2'] : 0;
                    $note = isset($teamSec['NOT']) ? trim($teamSec['NOT'], '"') : '';
                    $url = isset($teamSec['URL']) ? trim($teamSec['URL'], '"') : '';

                    $this->teams[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'short_name' => $shortName,
                        'mid_name' => $midName,
                        'icon' => '',
                        'penalty_points' => $sp,
                        'minus_points' => $sm,
                        'bonus_goals_for' => $tor1,
                        'bonus_goals_against' => $tor2,
                        'note' => $note !== '' ? $note : null,
                        'url' => $url !== '' ? $url : null,
                    ];
                }
            }
        }
    }

    private function parseMatches()
    {
        $rounds = isset($this->data['Options']['Rounds']) ? (int) $this->data['Options']['Rounds'] : 0;
        $matchesPerRound = isset($this->data['Options']['Matches']) ? (int) $this->data['Options']['Matches'] : 10;

        for ($i = 1; $i <= $rounds; $i++) {
            $section = "Round$i";
            if (!isset($this->data[$section]))
                continue;

            $roundMatches = [];
            for ($j = 1; $j <= $matchesPerRound; $j++) {
                $taKey = "TA$j";
                $tbKey = "TB$j";
                $gaKey = "GA$j";
                $gbKey = "GB$j";

                if (isset($this->data[$section][$taKey]) && isset($this->data[$section][$tbKey])) {
                    $homeIdx = (int) $this->data[$section][$taKey];
                    $guestIdx = (int) $this->data[$section][$tbKey];

                    $homeGoalsStr = $this->data[$section][$gaKey] ?? -1;
                    $guestGoalsStr = $this->data[$section][$gbKey] ?? -1;

                    $homeGoals = (int) $homeGoalsStr;
                    $guestGoals = (int) $guestGoalsStr;

                    $played = ($homeGoals >= 0 && $guestGoals >= 0);

                    // Date & Time from AT (Anstoß-Timestamp)
                    $atKey = "AT$j";
                    $timestamp = isset($this->data[$section][$atKey]) ? (int) $this->data[$section][$atKey] : 0;

                    $matchDate = null;
                    $matchTime = null;
                    $isoDate = null;

                    if ($timestamp > 0) {
                        $matchDate = date('d.m.Y', $timestamp);
                        $matchTime = date('H:i', $timestamp);
                        $isoDate = date('Y-m-d', $timestamp);
                    }

                    if ($homeIdx > 0 && $guestIdx > 0) {
                        $note = isset($this->data[$section]["NT$j"]) && trim($this->data[$section]["NT$j"]) !== ''
                            ? trim($this->data[$section]["NT$j"], '"')
                            : null;
                        $reportUrl = isset($this->data[$section]["BE$j"]) && trim($this->data[$section]["BE$j"]) !== ''
                            ? trim($this->data[$section]["BE$j"], '"')
                            : null;
                        $newsId = null;
                        if ($reportUrl && preg_match('/fullnews\.php\?id=(\d+)/i', $reportUrl, $nm)) {
                            $newsId = (int) $nm[1];
                        }

                        $roundMatches[] = [
                            'home_id' => $homeIdx,
                            'guest_id' => $guestIdx,
                            'home' => $this->teams[$homeIdx]['name'] ?? "Team $homeIdx",
                            'guest' => $this->teams[$guestIdx]['name'] ?? "Team $guestIdx",
                            'home_goals' => $played ? $homeGoals : null,
                            'guest_goals' => $played ? $guestGoals : null,
                            'played' => $played,
                            'timestamp' => $timestamp > 0 ? $timestamp : null,
                            'date' => $matchDate,
                            'iso_date' => $isoDate,
                            'time' => $matchTime,
                            'note' => $note,
                            'report_url' => $reportUrl,
                            'news_id' => $newsId,
                        ];
                    }
                }
            }
            $this->matches[$i] = $roundMatches;
        }
    }

    private function calculateTable()
    {
        $ptsWin = isset($this->data['Options']['PointsForWin']) ? (int) $this->data['Options']['PointsForWin'] : 3;
        $ptsDraw = isset($this->data['Options']['PointsForDraw']) ? (int) $this->data['Options']['PointsForDraw'] : 1;
        $ptsLost = isset($this->data['Options']['PointsForLost']) ? (int) $this->data['Options']['PointsForLost'] : 0;

        $table = [];
        foreach ($this->teams as $id => $team) {
            $sp = (int) ($team['penalty_points'] ?? 0);
            $tor1 = (int) ($team['bonus_goals_for'] ?? 0);
            $tor2 = (int) ($team['bonus_goals_against'] ?? 0);
            $table[$id] = [
                'team_id' => $id,
                'name' => $team['name'],
                'short_name' => $team['short_name'] ?? '',
                'played' => 0,
                'won' => 0,
                'draw' => 0,
                'lost' => 0,
                'goals_for' => $tor1,
                'goals_against' => $tor2,
                'diff' => 0,
                'points' => -$sp,
                'penalty_points' => $sp,
                'note' => $team['note'] ?? null,
            ];
        }

        foreach ($this->matches as $round => $games) {
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
                        $table[$h]['points'] += $ptsWin;
                    } elseif ($hg == $gg) {
                        $table[$h]['draw']++;
                        $table[$h]['points'] += $ptsDraw;
                    } else {
                        $table[$h]['lost']++;
                        $table[$h]['points'] += $ptsLost;
                    }
                }

                if (isset($table[$g])) {
                    $table[$g]['played']++;
                    $table[$g]['goals_for'] += $gg;
                    $table[$g]['goals_against'] += $hg;

                    if ($gg > $hg) {
                        $table[$g]['won']++;
                        $table[$g]['points'] += $ptsWin;
                    } elseif ($gg == $hg) {
                        $table[$g]['draw']++;
                        $table[$g]['points'] += $ptsDraw;
                    } else {
                        $table[$g]['lost']++;
                        $table[$g]['points'] += $ptsLost;
                    }
                }
            }
        }

        foreach ($table as &$row) {
            $row['diff'] = $row['goals_for'] - $row['goals_against'];
        }
        unset($row);

        usort($table, function ($a, $b) {
            if ($a['points'] != $b['points']) {
                return $b['points'] - $a['points'];
            }
            if ($a['diff'] != $b['diff']) {
                return $b['diff'] - $a['diff'];
            }
            return $b['goals_for'] - $a['goals_for'];
        });

        $rank = 1;
        foreach ($table as &$row) {
            $row['rank'] = $rank++;
        }
        unset($row);

        return $table;
    }
}
