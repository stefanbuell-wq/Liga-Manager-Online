<?php
/**
 * API-Endpunkt: Saison-Assistent (Neue Saison einer Liga anlegen)
 *
 * GET  ?action=init                 - Alle Ligen + bekannte Teams (Autocomplete/Logos)
 * GET  ?action=source_league&liga=X - Quell-Liga, Teams, Tabellenplatz & Vorschläge für Name/Datei
 * GET  ?action=check_file&file=X    - Prüft Dateinamen-Format und ob Datei bereits existiert
 * POST (action=preview)             - Validiert Eingaben und erzeugt Spielplan-Vorschau
 * POST (action=create)              - Legt Liga, Teams und Spielplan in einer DB-Transaktion an & leert Cache
 *
 * Security: Nur für eingeloggte Admins + CSRF-Schutz bei POST
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';
require_once __DIR__ . '/../lib/LmoRepository.php';
require_once __DIR__ . '/../lib/LmoWriter.php';

Security::initSession();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// Auth Check: Nur eingeloggte Admins
$role = $_SESSION['lmo26_user_role'] ?? '';
if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true || $role !== 'admin') {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Nicht autorisiert (nur für Administratoren)',
        'csrf_token' => Security::getCsrfToken()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF protection for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::requireCsrf();
}

/**
 * Stellt sicher, dass teams.short_name und teams.logo_file existieren
 */
function ensureSeasonTeamColumns(PDO $pdo): void
{
    $cols = $pdo->query("PRAGMA table_info(teams)")->fetchAll();
    $colNames = array_column($cols, 'name');
    if (!in_array('short_name', $colNames, true)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN short_name TEXT");
    }
    if (!in_array('logo_file', $colNames, true)) {
        $pdo->exec("ALTER TABLE teams ADD COLUMN logo_file TEXT");
    }
}

/**
 * Schlägt neuen Dateinamen vor (z. B. hhoberliga2627.l98 -> hhoberliga2728.l98)
 */
function suggestNextFilename(string $sourceFile): string
{
    $base = preg_replace('/\.(l98|lmo)$/i', '', basename($sourceFile));
    $base = strtolower($base);
    $base = preg_replace('/[^a-z0-9_-]/', '', $base);

    // Muster 1: am Ende 4 Ziffern JJ1JJ2 (z. B. 2627 -> 2728)
    if (preg_match('/^(.*?)(\d{2})(\d{2})$/', $base, $m)) {
        $y1 = (int)$m[2];
        $y2 = (int)$m[3];
        if ($y2 === ($y1 + 1) % 100) {
            $ny1 = ($y1 + 1) % 100;
            $ny2 = ($y2 + 1) % 100;
            return sprintf('%s%02d%02d.l98', $m[1], $ny1, $ny2);
        }
    }

    // Muster 2: am Ende JJ1-JJ2 oder JJ1_JJ2 (z. B. 04-05 -> 05-06)
    if (preg_match('/^(.*?)(\d{2})([-_])(\d{2})$/', $base, $m)) {
        $y1 = (int)$m[2];
        $sep = $m[3];
        $y2 = (int)$m[4];
        if ($y2 === ($y1 + 1) % 100) {
            $ny1 = ($y1 + 1) % 100;
            $ny2 = ($y2 + 1) % 100;
            return sprintf('%s%02d%s%02d.l98', $m[1], $ny1, $sep, $ny2);
        }
    }

    // Muster 3: irgendwo im Dateinamen 4 Ziffern JJ1JJ2
    if (preg_match('/^(.*?)(\d{2})(\d{2})([a-z0-9_-]*)$/', $base, $m)) {
        $y1 = (int)$m[2];
        $y2 = (int)$m[4 !== '' ? 3 : 3];
        if ($y2 === ($y1 + 1) % 100) {
            $ny1 = ($y1 + 1) % 100;
            $ny2 = ($y2 + 1) % 100;
            return sprintf('%s%02d%02d%s.l98', $m[1], $ny1, $ny2, $m[4]);
        }
    }

    return $base . '_neu.l98';
}

/**
 * Schlägt neuen Liganamen vor (z. B. "Hamburg-Oberliga 26/27" -> "Hamburg-Oberliga 27/28")
 */
function suggestNextLeagueName(string $sourceName): string
{
    $name = trim($sourceName);

    // Muster 1: JJJJ/JJJJ (z. B. 2026/2027 -> 2027/2028)
    if (preg_match('/^(.*?\b)(\d{4})\/(\d{4})(\b.*)$/u', $name, $m)) {
        $y1 = (int)$m[2] + 1;
        $y2 = (int)$m[3] + 1;
        return $m[1] . $y1 . '/' . $y2 . $m[4];
    }

    // Muster 2: JJJJ/JJ (z. B. 2026/27 -> 2027/28)
    if (preg_match('/^(.*?\b)(\d{4})\/(\d{2})(\b.*)$/u', $name, $m)) {
        $y1 = (int)$m[2] + 1;
        $y2 = ((int)$m[3] + 1) % 100;
        return sprintf('%s%d/%02d%s', $m[1], $y1, $y2, $m[4]);
    }

    // Muster 3: JJ/JJ (z. B. 26/27 -> 27/28)
    if (preg_match('/^(.*?)(\d{2})\/(\d{2})(.*)$/u', $name, $m)) {
        $y1 = ((int)$m[2] + 1) % 100;
        $y2 = ((int)$m[3] + 1) % 100;
        return sprintf('%s%02d/%02d%s', $m[1], $y1, $y2, $m[4]);
    }

    return $name . ' (Neue Saison)';
}

/**
 * Ermittelt Logo-Datei (und ggf. Kurzname) vom gleichnamigen Team in der DB oder aus img/teams/
 */
function resolveTeamMeta(PDO $pdo, string $name, ?string $shortName = null, ?string $logoFile = null): array
{
    $cleanName = trim($name);
    $short = trim((string)$shortName);
    $logo = trim((string)$logoFile);

    // Suche gleichnamiges Team in der DB
    $stmt = $pdo->prepare("
        SELECT short_name, logo_file
        FROM teams
        WHERE LOWER(TRIM(name)) = LOWER(:name)
        ORDER BY
            CASE WHEN logo_file IS NOT NULL AND TRIM(logo_file) != '' THEN 0 ELSE 1 END,
            CASE WHEN short_name IS NOT NULL AND TRIM(short_name) != '' THEN 0 ELSE 1 END,
            id DESC
        LIMIT 1
    ");
    $stmt->execute([':name' => $cleanName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        if ($logo === '' && !empty($row['logo_file'])) {
            $logo = trim($row['logo_file']);
        }
        if ($short === '' && !empty($row['short_name'])) {
            $short = trim($row['short_name']);
        }
    }

    // Falls kein logo_file in DB hinterlegt, prüfe Datei in img/teams/
    if ($logo === '') {
        $alnum = preg_replace('/[^a-zA-Z0-9]/', '', $cleanName);
        if ($alnum !== '') {
            $teamsImgDir = __DIR__ . '/../img/teams';
            foreach (['.gif', '.png', '.svg', '.jpg'] as $ext) {
                $candidate = $alnum . $ext;
                if (file_exists($teamsImgDir . '/' . $candidate)) {
                    $logo = $candidate;
                    break;
                }
            }
        }
    }

    return [
        'name' => $cleanName,
        'short_name' => $short !== '' ? $short : null,
        'logo_file' => $logo !== '' ? $logo : null
    ];
}

/**
 * Prüft ob ein Liga-Dateiname bereits in der DB (oder als Datei) existiert
 */
function leagueFileExists(PDO $pdo, string $file): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leagues WHERE LOWER(file) = LOWER(:f)");
    $stmt->execute([':f' => $file]);
    if ((int)$stmt->fetchColumn() > 0) {
        return true;
    }
    $ligaPath = __DIR__ . '/../ligen/' . basename($file);
    return file_exists($ligaPath);
}

/**
 * Konvertiert ein beliebiges Eingabedatum (YYYY-MM-DD oder DD.MM.YYYY) in ein DateTimeImmutable-Objekt
 */
function parseInputDate(?string $dateStr): ?DateTimeImmutable
{
    $raw = trim((string)$dateStr);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            throw new InvalidArgumentException("Ungültiges Startdatum: $raw");
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int)$m[1], (int)$m[2], (int)$m[3]));
    }
    if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $raw, $m)) {
        if (!checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
            throw new InvalidArgumentException("Ungültiges Startdatum: $raw");
        }
        return new DateTimeImmutable(sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]));
    }
    throw new InvalidArgumentException("Startdatum muss im Format TT.MM.JJJJ oder JJJJ-MM-TT angegeben werden");
}

/**
 * Validiert Standard-Uhrzeit (HH:MM)
 */
function parseInputTime(?string $timeStr): ?string
{
    $raw = trim((string)$timeStr);
    if ($raw === '') {
        return null;
    }
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw)) {
        throw new InvalidArgumentException("Standard-Uhrzeit muss im Format HH:MM (z. B. 15:00) angegeben werden");
    }
    return $raw;
}

/**
 * Erzeugt eine Doppelrunde nach dem Berger-Tabellen-Algorithmus (Round-Robin).
 * - Bei ungerader Teamanzahl n wird ein Dummy-Slot ("spielfrei") ergänzt (m = n + 1).
 * - Rückrunde ist gespiegelt mit getauschtem Heim-/Auswärtsrecht.
 *
 * @param array $teams Array von Teams (1-basiert oder 0-basiert, mit Schlüsseln 'original_id', 'name', ...)
 * @param DateTimeImmutable|null $startDate Optionales Startdatum für Spieltag 1
 * @param int $weekInterval Wochen-Abstand zwischen Spieltagen (Standard: 1)
 * @param string|null $defaultTime Standard-Uhrzeit (HH:MM)
 * @return array ['rounds_count', 'matches_per_round', 'has_bye', 'rounds' => [roundNr => ['date', 'bye_team', 'matches' => [...]]]]
 */
function generateDoubleRoundRobin(array $teams, ?DateTimeImmutable $startDate, int $weekInterval = 1, ?string $defaultTime = null): array
{
    $teamsList = array_values($teams);
    $n = count($teamsList);
    $hasBye = ($n % 2 !== 0);

    // Bei ungerader Anzahl Dummy-Eintrag (null) für "spielfrei" anhängen
    $slots = $teamsList;
    if ($hasBye) {
        $slots[] = null;
    }

    $m = count($slots); // immer gerade
    $hinrundeRounds = $m - 1;
    $totalRounds = 2 * $hinrundeRounds;
    $matchesPerRound = intdiv($n, 2); // echte Spiele pro Spieltag (ohne spielfrei)

    $hinrunde = [];

    // Berger-Tabelle für Hinrunde (Runden r = 0 .. m-2)
    // Slot m-1 ist fixiert, Slots 0 .. m-2 rotieren
    $rotatingCount = $m - 1;

    for ($r = 0; $r < $hinrundeRounds; $r++) {
        $roundNr = $r + 1;
        $roundMatches = [];
        $byeTeam = null;

        // Paarung 0: rotierender Slot r vs fixierter Slot m-1
        $firstRotIdx = $r % $rotatingCount;
        $teamA = $slots[$firstRotIdx];
        $teamB = $slots[$m - 1];

        if ($r % 2 === 0) {
            $home = $teamA;
            $guest = $teamB;
        } else {
            $home = $teamB;
            $guest = $teamA;
        }

        if ($home === null || $guest === null) {
            $byeTeam = $home ?? $guest;
        } else {
            $roundMatches[] = ['home' => $home, 'guest' => $guest];
        }

        // Paarungen i = 1 .. (m/2 - 1)
        $half = intdiv($m, 2);
        for ($i = 1; $i < $half; $i++) {
            $idx1 = ($r + $i) % $rotatingCount;
            $idx2 = ($r + $rotatingCount - $i) % $rotatingCount;

            $t1 = $slots[$idx1];
            $t2 = $slots[$idx2];

            if ($i % 2 === 1) {
                $home = $t1;
                $guest = $t2;
            } else {
                $home = $t2;
                $guest = $t1;
            }

            if ($home === null || $guest === null) {
                $byeTeam = $home ?? $guest;
            } else {
                $roundMatches[] = ['home' => $home, 'guest' => $guest];
            }
        }

        $hinrunde[$roundNr] = [
            'bye_team' => $byeTeam,
            'pairings' => $roundMatches
        ];
    }

    // Gesamten Spielplan (Hinrunde + gespiegelte Rückrunde mit Heim/Auswärts getauscht) aufbauen
    $roundsOut = [];
    for ($roundNr = 1; $roundNr <= $totalRounds; $roundNr++) {
        $isRueckrunde = ($roundNr > $hinrundeRounds);
        $baseRoundNr = $isRueckrunde ? ($roundNr - $hinrundeRounds) : $roundNr;
        $baseRound = $hinrunde[$baseRoundNr];

        $matchDateStr = null;
        if ($startDate !== null) {
            $daysOffset = ($roundNr - 1) * $weekInterval * 7;
            $roundDate = $startDate->modify("+{$daysOffset} days");
            $matchDateStr = $roundDate->format('d.m.Y'); // Format TT.MM.JJJJ!
        }

        $matchesFormatted = [];
        foreach ($baseRound['pairings'] as $pair) {
            $homeTeam = $isRueckrunde ? $pair['guest'] : $pair['home'];
            $guestTeam = $isRueckrunde ? $pair['home'] : $pair['guest'];

            $matchesFormatted[] = [
                'round_nr' => $roundNr,
                'home_id' => $homeTeam['original_id'],
                'guest_id' => $guestTeam['original_id'],
                'home_name' => $homeTeam['name'],
                'guest_name' => $guestTeam['name'],
                'match_date' => $matchDateStr,
                'match_time' => $defaultTime
            ];
        }

        $roundsOut[$roundNr] = [
            'round_nr' => $roundNr,
            'half' => $isRueckrunde ? 2 : 1,
            'date' => $matchDateStr,
            'time' => $defaultTime,
            'bye_team' => $baseRound['bye_team'] ? $baseRound['bye_team']['name'] : null,
            'matches' => $matchesFormatted
        ];
    }

    return [
        'rounds_count' => $totalRounds,
        'matches_per_round' => $matchesPerRound,
        'has_bye' => $hasBye,
        'rounds' => $roundsOut
    ];
}

/**
 * Standard-Optionen einer Liga ohne Vorlage (3/1/0 Punkte, Hin- und Rueckrunde, Ansichten wie LMO 4)
 */
function defaultLeagueOptions(): array
{
    return [
        'Title' => 'Liga Manager Online 4', 'Type' => '0', 'goalfaktor' => '1', 'pointsfaktor' => '1', 'enableGameSort' => '1',
        'Kegel' => '0', 'HandS' => '0', 'PointsForWin' => '3', 'PointsForDraw' => '1', 'PointsForLost' => '0',
        'Spez' => '0', 'HideDraw' => '0', 'OnRun' => '0', 'MinusPoints' => '1', 'Direct' => '0',
        'Champ' => '1', 'CL' => '0', 'CK' => '0', 'UC' => '0', 'AR' => '0', 'AB' => '0',
        'namePkt' => 'Pkt.', 'nameTor' => 'Tore', 'tableHinRueck' => '1', 'tableHeimAusw' => '1',
        'DatC' => '1', 'DatS' => '1', 'DatM' => '1', 'DatF' => '%d.%m. %H.%M', 'urlT' => '1', 'urlB' => '1',
        'Plan' => '1', 'Ergebnis' => '1', 'mittore' => '1', 'favTeam' => '0', 'selTeam' => '0', 'ticker' => '0',
        'Graph' => '1', 'Kreuz' => '1', 'Tabelle' => '1', 'Ligastats' => '1', 'kurve1' => '0', 'kurve2' => '0',
    ];
}

/**
 * Validiert und bereitet alle Eingabedaten für Preview & Create auf
 */
function validateAndPrepareSeason(PDO $pdo, array $input): array
{
    // Ohne Quell-Liga (erste Liga einer neuen Installation): Standardregeln
    $sourceFile = basename(trim((string)($input['source_liga'] ?? '')));
    $sourceLeague = null;
    if ($sourceFile !== '') {
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $sourceFile)) {
            throw new InvalidArgumentException('Bitte eine gültige Quell-Liga auswählen.');
        }
        $repo = new LmoRepository();
        $sourceLeague = $repo->getLeagueByFile($sourceFile);
    }

    $newName = trim((string)($input['new_name'] ?? ''));
    if ($newName === '' || mb_strlen($newName) > 120) {
        throw new InvalidArgumentException('Bitte einen gültigen Namen für die neue Saison angeben (1–120 Zeichen).');
    }

    $newFile = trim((string)($input['new_file'] ?? ''));
    if (!preg_match('/^[a-z0-9_-]+\.l98$/', $newFile)) {
        throw new InvalidArgumentException('Ungültiger Dateiname: Erlaubt ist nur das Format ^[a-z0-9_-]+\\.l98$ (Kleinbuchstaben, Ziffern, _ und -, Endung .l98).');
    }

    if (leagueFileExists($pdo, $newFile)) {
        throw new InvalidArgumentException("Die Liga-Datei \"$newFile\" existiert bereits. Bitte einen anderen Dateinamen wählen.");
    }

    $rawTeams = $input['teams'] ?? [];
    if (!is_array($rawTeams)) {
        throw new InvalidArgumentException('Ungültige Teamliste.');
    }

    $preparedTeams = [];
    $seenNames = [];
    $oid = 1;

    foreach ($rawTeams as $t) {
        $tName = trim((string)($t['name'] ?? ''));
        if ($tName === '') {
            continue;
        }
        $lowerKey = mb_strtolower($tName, 'UTF-8');
        if (isset($seenNames[$lowerKey])) {
            throw new InvalidArgumentException("Doppelter Teamname in der Auswahl: \"$tName\". Jedes Team darf nur einmal vorkommen.");
        }
        $seenNames[$lowerKey] = true;

        $meta = resolveTeamMeta($pdo, $tName, $t['short_name'] ?? null, $t['logo_file'] ?? null);
        $preparedTeams[] = [
            'original_id' => $oid++,
            'name' => $meta['name'],
            'short_name' => $meta['short_name'],
            'logo_file' => $meta['logo_file']
        ];
    }

    $teamCount = count($preparedTeams);
    if ($teamCount < 4 || $teamCount > 24) {
        throw new InvalidArgumentException("Ungültige Teamanzahl ($teamCount): Es müssen zwischen 4 und 24 Teams ausgewählt sein.");
    }

    $scheduleMode = trim((string)($input['schedule_mode'] ?? 'auto'));
    if (!in_array($scheduleMode, ['empty', 'auto'], true)) {
        throw new InvalidArgumentException('Ungültiger Spielplan-Modus (erlaubt: empty oder auto).');
    }

    $startDate = parseInputDate($input['start_date'] ?? null);
    $weekInterval = isset($input['week_interval']) && $input['week_interval'] !== '' ? (int)$input['week_interval'] : 1;
    if ($weekInterval < 1 || $weekInterval > 12) {
        throw new InvalidArgumentException('Wochen-Abstand muss zwischen 1 und 12 liegen.');
    }
    $defaultTime = parseInputTime($input['default_time'] ?? null);

    $hasBye = ($teamCount % 2 !== 0);
    // Gemäß Auftrag: Anzahl Teams n -> Rounds = 2*(n-1) bei geradem n; bei ungeradem n (spielfrei) 2*n Runden, Matches pro Spieltag = floor(n/2)
    $effectiveN = $hasBye ? ($teamCount + 1) : $teamCount;
    $roundsCount = 2 * ($effectiveN - 1);
    $matchesPerRound = intdiv($teamCount, 2);

    $schedule = null;
    $totalMatches = 0;

    if ($scheduleMode === 'auto') {
        $schedule = generateDoubleRoundRobin($preparedTeams, $startDate, $weekInterval, $defaultTime);
        $roundsCount = $schedule['rounds_count'];
        $matchesPerRound = $schedule['matches_per_round'];
        $totalMatches = $roundsCount * $matchesPerRound;
    }

    // Options von der Quell-Liga kopieren und anpassen (ohne Vorlage: Standardregeln wie LMO 4)
    $options = ($sourceLeague && is_array($sourceLeague['options'])) ? $sourceLeague['options'] : defaultLeagueOptions();
    $options['Name'] = $newName;
    $options['Teams'] = (string)$teamCount;
    $options['Rounds'] = (string)$roundsCount;
    $options['Matches'] = (string)$matchesPerRound;
    $options['Actual'] = '1';

    return [
        'source_league' => $sourceLeague ? [
            'id' => (int)$sourceLeague['id'],
            'file' => $sourceLeague['file'],
            'name' => $sourceLeague['name']
        ] : null,
        'new_name' => $newName,
        'new_file' => $newFile,
        'options' => $options,
        'teams' => $preparedTeams,
        'team_count' => $teamCount,
        'has_bye' => $hasBye,
        'schedule_mode' => $scheduleMode,
        'start_date' => $startDate ? $startDate->format('d.m.Y') : null,
        'week_interval' => $weekInterval,
        'default_time' => $defaultTime,
        'rounds_count' => $roundsCount,
        'matches_per_round' => $matchesPerRound,
        'total_matches' => $totalMatches,
        'schedule' => $schedule ? $schedule['rounds'] : []
    ];
}

/**
 * Leert den JSON-Datei-Cache in data/cache/
 */
function clearLeagueCache(): int
{
    $cacheDir = __DIR__ . '/../data/cache';
    if (!is_dir($cacheDir)) {
        return 0;
    }
    $deleted = 0;
    foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
        if (is_file($file) && @unlink($file)) {
            $deleted++;
        }
    }
    return $deleted;
}

try {
    $pdo = LmoDatabase::getInstance();
    ensureSeasonTeamColumns($pdo);
    $repo = new LmoRepository();

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'GET') {
        if ($action === 'init' || $action === '') {
            $leagues = $repo->getAllLeagues();

            // Bekannte Teams aus allen Ligen für Autocomplete und Logo-Übernahme laden
            $stmtTeams = $pdo->query("
                SELECT name, short_name, logo_file
                FROM teams
                WHERE name IS NOT NULL AND TRIM(name) != ''
                ORDER BY
                    CASE WHEN logo_file IS NOT NULL AND TRIM(logo_file) != '' THEN 0 ELSE 1 END,
                    CASE WHEN short_name IS NOT NULL AND TRIM(short_name) != '' THEN 0 ELSE 1 END,
                    id DESC
            ");
            $knownTeamsMap = [];
            foreach ($stmtTeams->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = mb_strtolower(trim($row['name']), 'UTF-8');
                if (!isset($knownTeamsMap[$key])) {
                    $meta = resolveTeamMeta($pdo, $row['name'], $row['short_name'], $row['logo_file']);
                    $knownTeamsMap[$key] = $meta;
                }
            }
            $knownTeams = array_values($knownTeamsMap);
            usort($knownTeams, fn($a, $b) => strcasecmp($a['name'], $b['name']));

            echo json_encode([
                'success' => true,
                'leagues' => $leagues,
                'known_teams' => $knownTeams,
                'csrf_token' => Security::getCsrfToken()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'source_league') {
            $ligaFile = basename(trim((string)($_GET['liga'] ?? '')));
            if ($ligaFile === '' || !preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Ungültiger Liga-Dateiname'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $league = $repo->getLeagueByFile($ligaFile);
            $dbTeams = $repo->getTeams($league['id']);

            // Tabellenstand ermitteln (für Anzeige von Platzierung / Abstiegsplätzen)
            $ranksByName = [];
            try {
                $full = $repo->getLeagueDataFull($ligaFile);
                if (!empty($full['table'])) {
                    foreach ($full['table'] as $idx => $row) {
                        $ranksByName[$row['name']] = [
                            'rank' => $idx + 1,
                            'points' => $row['points'],
                            'played' => $row['played'],
                            'diff' => $row['diff']
                        ];
                    }
                }
            } catch (Exception $e) {
                // Ignorieren falls Tabelle leer
            }

            $abCount = isset($league['options']['AB']) ? (int)$league['options']['AB'] : 0;
            $totalSourceTeams = count($dbTeams);

            $teamsOut = [];
            foreach ($dbTeams as $t) {
                $meta = resolveTeamMeta($pdo, $t['name'], $t['short_name'] ?? null, $t['logo_file'] ?? null);
                $rankInfo = $ranksByName[$t['name']] ?? null;
                $rank = $rankInfo ? $rankInfo['rank'] : null;
                $isRelegated = ($abCount > 0 && $rank !== null && $rank > ($totalSourceTeams - $abCount));

                $teamsOut[] = [
                    'id' => (int)$t['id'],
                    'original_id' => (int)$t['original_id'],
                    'name' => $meta['name'],
                    'short_name' => $meta['short_name'],
                    'logo_file' => $meta['logo_file'],
                    'rank' => $rank,
                    'points' => $rankInfo['points'] ?? null,
                    'is_relegated' => $isRelegated
                ];
            }

            $sourceName = $league['options']['Name'] ?? $league['name'];
            $suggestedName = suggestNextLeagueName($sourceName);
            $suggestedFile = suggestNextFilename($league['file']);

            echo json_encode([
                'success' => true,
                'source_league' => [
                    'id' => (int)$league['id'],
                    'file' => $league['file'],
                    'name' => $sourceName,
                    'options' => $league['options'],
                    'absteiger_count' => $abCount
                ],
                'suggested_name' => $suggestedName,
                'suggested_file' => $suggestedFile,
                'suggested_file_exists' => leagueFileExists($pdo, $suggestedFile),
                'teams' => $teamsOut,
                'csrf_token' => Security::getCsrfToken()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'check_file') {
            $file = trim((string)($_GET['file'] ?? ''));
            $validFormat = (bool)preg_match('/^[a-z0-9_-]+\.l98$/', $file);
            $exists = $validFormat ? leagueFileExists($pdo, $file) : false;

            echo json_encode([
                'success' => true,
                'file' => $file,
                'valid_format' => $validFormat,
                'exists' => $exists,
                'available' => ($validFormat && !$exists)
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unbekannte GET-Aktion'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültige JSON-Daten'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $postAction = $input['action'] ?? '';

        if ($postAction === 'preview') {
            $prepared = validateAndPrepareSeason($pdo, $input);
            echo json_encode([
                'success' => true,
                'preview' => $prepared,
                'csrf_token' => Security::getCsrfToken()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($postAction === 'create') {
            $prepared = validateAndPrepareSeason($pdo, $input);

            $pdo->beginTransaction();
            try {
                // 1. Liga in leagues anlegen
                $stmtLeague = $pdo->prepare("
                    INSERT INTO leagues (file, name, options)
                    VALUES (:file, :name, :options)
                ");
                $stmtLeague->execute([
                    ':file' => $prepared['new_file'],
                    ':name' => $prepared['new_name'],
                    ':options' => json_encode($prepared['options'], JSON_UNESCAPED_UNICODE)
                ]);
                $newLeagueId = (int)$pdo->lastInsertId();

                // 2. Teams in teams anlegen
                $stmtTeam = $pdo->prepare("
                    INSERT INTO teams (league_id, original_id, name, short_name, logo_file)
                    VALUES (:lid, :oid, :name, :short, :logo)
                ");
                $teamIdMap = []; // original_id => db_id
                foreach ($prepared['teams'] as $team) {
                    $stmtTeam->execute([
                        ':lid' => $newLeagueId,
                        ':oid' => $team['original_id'],
                        ':name' => $team['name'],
                        ':short' => $team['short_name'],
                        ':logo' => $team['logo_file']
                    ]);
                    $teamIdMap[$team['original_id']] = (int)$pdo->lastInsertId();
                }

                // 3. Spielplan in matches anlegen (bei Option B / auto)
                $insertedMatches = 0;
                if ($prepared['schedule_mode'] === 'auto' && !empty($prepared['schedule'])) {
                    $stmtMatch = $pdo->prepare("
                        INSERT INTO matches (
                            league_id, round_nr, home_team_id, guest_team_id,
                            home_goals, guest_goals, match_date, match_time, match_note, report_url
                        ) VALUES (
                            :lid, :r, :hid, :gid,
                            NULL, NULL, :md, :mt, NULL, NULL
                        )
                    ");

                    foreach ($prepared['schedule'] as $roundNr => $roundData) {
                        foreach ($roundData['matches'] as $m) {
                            $hid = $teamIdMap[$m['home_id']] ?? null;
                            $gid = $teamIdMap[$m['guest_id']] ?? null;
                            if (!$hid || !$gid) {
                                throw new RuntimeException("Interner Fehler: Team-ID-Zuordnung fehlgeschlagen.");
                            }
                            $stmtMatch->execute([
                                ':lid' => $newLeagueId,
                                ':r' => (int)$roundNr,
                                ':hid' => $hid,
                                ':gid' => $gid,
                                ':md' => $m['match_date'],
                                ':mt' => $m['match_time']
                            ]);
                            $insertedMatches++;
                        }
                    }
                }

                $pdo->commit();
            } catch (Exception $txEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txEx;
            }

            // 4. Cache leeren (data/cache)
            $clearedCacheFiles = clearLeagueCache();

            // 5. Optional .l98 schreiben, falls Ordner ligen/ existiert und beschreibbar ist
            $wroteL98 = false;
            $ligenDir = __DIR__ . '/../ligen';
            if (is_dir($ligenDir) && is_writable($ligenDir)) {
                try {
                    $iniData = ['Options' => $prepared['options'], 'Teams' => [], 'Teamk' => []];
                    foreach ($prepared['teams'] as $t) {
                        $oid = $t['original_id'];
                        $iniData['Teams'][$oid] = $t['name'];
                        if (!empty($t['short_name'])) {
                            $iniData['Teamk'][$oid] = $t['short_name'];
                        }
                    }
                    if ($prepared['schedule_mode'] === 'auto' && !empty($prepared['schedule'])) {
                        foreach ($prepared['schedule'] as $roundNr => $roundData) {
                            $sec = "Round{$roundNr}";
                            $iniData[$sec] = [];
                            foreach ($roundData['matches'] as $idx => $m) {
                                $j = $idx + 1;
                                $iniData[$sec]["TA{$j}"] = $m['home_id'];
                                $iniData[$sec]["TB{$j}"] = $m['guest_id'];
                                $iniData[$sec]["GA{$j}"] = -1;
                                $iniData[$sec]["GB{$j}"] = -1;
                            }
                        }
                    }
                    $writer = new LmoWriter();
                    $writer->save($ligenDir . '/' . $prepared['new_file'], $iniData);
                    $wroteL98 = true;
                } catch (Exception $e) {
                    // Optionales Schreiben fehlgeschlagen -> kein Abbruch
                }
            }

            echo json_encode([
                'success' => true,
                'message' => sprintf('Saison "%s" (%s) wurde erfolgreich angelegt.', $prepared['new_name'], $prepared['new_file']),
                'league' => [
                    'id' => $newLeagueId,
                    'file' => $prepared['new_file'],
                    'name' => $prepared['new_name'],
                    'teams_count' => $prepared['team_count'],
                    'rounds_count' => $prepared['rounds_count'],
                    'matches_per_round' => $prepared['matches_per_round'],
                    'matches_inserted' => $insertedMatches,
                    'schedule_mode' => $prepared['schedule_mode'],
                    'cache_cleared' => $clearedCacheFiles,
                    'l98_written' => $wroteL98
                ],
                'csrf_token' => Security::getCsrfToken()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unbekannte POST-Aktion: ' . $postAction], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt'], JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'csrf_token' => Security::getCsrfToken()
    ], JSON_UNESCAPED_UNICODE);
}
