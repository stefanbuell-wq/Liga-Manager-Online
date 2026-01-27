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
        $filePath = $this->ligaDir . '/' . basename($ligaFile);

        if (!file_exists($filePath)) {
            throw new Exception("Liga file not found: $filePath");
        }

        // LMO files are often ISO-8859-1 encoded. We need to handle this.
        // parse_ini_file might fail with key names in different encodings, 
        // so we read it manually to ensure UTF-8 conversion.
        $content = file_get_contents($filePath);
        $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

        $this->data = parse_ini_string($content, true, INI_SCANNER_RAW);

        if (!$this->data) {
            throw new Exception("Could not parse LMO file.");
        }

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
                    $this->teams[$id] = [
                        'id' => $id,
                        'name' => $name,
                        'icon' => ''
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

                    $played = ($homeGoals != -1 && $guestGoals != -1);

                    // Date & Time from AT (Anstoß-Timestamp)
                    $atKey = "AT$j";
                    $timestamp = isset($this->data[$section][$atKey]) ? (int) $this->data[$section][$atKey] : 0;

                    $matchDate = null;
                    $matchTime = null;

                    if ($timestamp > 0) {
                        $matchDate = date('d.m.Y', $timestamp);
                        $matchTime = date('H:i', $timestamp);
                    }

                    if ($homeIdx > 0 && $guestIdx > 0) {
                        $roundMatches[] = [
                            'home_id' => $homeIdx,
                            'guest_id' => $guestIdx,
                            'home' => $this->teams[$homeIdx]['name'] ?? "Team $homeIdx",
                            'guest' => $this->teams[$guestIdx]['name'] ?? "Team $guestIdx",
                            'home_goals' => $played ? $homeGoals : null,
                            'guest_goals' => $played ? $guestGoals : null,
                            'played' => $played,
                            'played' => $played,
                            'date' => $matchDate,
                            'time' => $matchTime,
                            'note' => isset($this->data[$section]["NT$j"]) ? $this->data[$section]["NT$j"] : null,
                            'report_url' => isset($this->data[$section]["BE$j"]) ? $this->data[$section]["BE$j"] : null,
                        ];
                    }
                }
            }
            $this->matches[$i] = $roundMatches;
        }
    }

    private function calculateTable()
    {
        $table = [];
        foreach ($this->teams as $id => $team) {
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

        foreach ($table as &$row) {
            $row['diff'] = $row['goals_for'] - $row['goals_against'];
        }

        usort($table, function ($a, $b) {
            if ($a['points'] != $b['points']) {
                return $b['points'] - $a['points'];
            }
            if ($a['diff'] != $b['diff']) {
                return $b['diff'] - $a['diff'];
            }
            return $b['goals_for'] - $a['goals_for'];
        });

        return $table;
    }
}
