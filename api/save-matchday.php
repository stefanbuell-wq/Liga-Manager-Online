<?php
/**
 * Save Matchday Results
 * Security: Auth + CSRF protection
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

Security::initSession();
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

// Auth Check
if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized', 'csrf_token' => Security::getCsrfToken()]);
    exit;
}

// CSRF protection
Security::requireCsrf();

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'No data']);
    exit;
}

$ligaFile = basename($data['liga'] ?? ''); // Sanitize filename
if (empty($ligaFile) || !preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid league file format']);
    exit;
}

$round = (int) ($data['round'] ?? 0);
if ($round <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid round number']);
    exit;
}

$matches = $data['matches'] ?? [];

try {
    $pdo = LmoDatabase::getInstance();

    // Get League ID
    $stmt = $pdo->prepare("SELECT id FROM leagues WHERE file = ?");
    $stmt->execute([$ligaFile]);
    $lid = $stmt->fetchColumn();

    if (!$lid) {
        throw new Exception("League not found in database");
    }

    // Create lookup for teams: original_id -> db_id
    $stmtTeams = $pdo->prepare("SELECT original_id, id FROM teams WHERE league_id = ?");
    $stmtTeams->execute([$lid]);
    $teamLookup = $stmtTeams->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtUpdate = $pdo->prepare("
        UPDATE matches
        SET home_goals = :hg, guest_goals = :gg,
            match_date = CASE WHEN :md_set THEN :md ELSE match_date END,
            match_time = CASE WHEN :mt_set THEN :mt ELSE match_time END,
            match_note = CASE WHEN :mn_set THEN :mn ELSE match_note END
        WHERE league_id = :lid
          AND round_nr = :r
          AND home_team_id = :hid
          AND guest_team_id = :gid
    ");

    $stmtInsert = $pdo->prepare("
        INSERT INTO matches (
            league_id, round_nr, home_team_id, guest_team_id,
            home_goals, guest_goals, match_date, match_time, match_note
        ) VALUES (
            :lid, :r, :hid, :gid,
            :hg, :gg, :md, :mt, :mn
        )
    ");

    $pdo->beginTransaction();

    foreach ($matches as $m) {
        // m contains home_id (original), guest_id (original)
        $hid = $teamLookup[$m['home_id']] ?? null;
        $gid = $teamLookup[$m['guest_id']] ?? null;

        if ($hid && $gid && $hid !== $gid) {
            // Nullify empty values
            $hg = ($m['home_goals'] === "" || $m['home_goals'] === null) ? null : $m['home_goals'];
            $gg = ($m['guest_goals'] === "" || $m['guest_goals'] === null) ? null : $m['guest_goals'];
            $md = !empty($m['date']) ? $m['date'] : null;
            $mt = !empty($m['time']) ? $m['time'] : null;
            $mn = !empty($m['note']) ? trim($m['note']) : null;

            // Felder, die der Aufrufer nicht mitschickt (admin/edit.html: nur Tore), bleiben unveraendert
            $setFlags = [
                ':md_set' => array_key_exists('date', $m) ? 1 : 0,
                ':mt_set' => array_key_exists('time', $m) ? 1 : 0,
                ':mn_set' => array_key_exists('note', $m) ? 1 : 0,
            ];

            $params = [
                ':hg' => $hg,
                ':gg' => $gg,
                ':md' => $md,
                ':mt' => $mt,
                ':mn' => $mn,
                ':lid' => $lid,
                ':r' => $round,
                ':hid' => $hid,
                ':gid' => $gid
            ];

            $stmtUpdate->execute($params + $setFlags);
            if ($stmtUpdate->rowCount() === 0) {
                // Prüfen ob Paarung bereits mit NULL-Werten existiert (SQLite rowCount ist 0 wenn Werte identisch)
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE league_id = ? AND round_nr = ? AND home_team_id = ? AND guest_team_id = ?");
                $stmtCheck->execute([$lid, $round, $hid, $gid]);
                if ((int)$stmtCheck->fetchColumn() === 0) {
                    $stmtInsert->execute($params);
                }
            }
        }
    }

    $pdo->commit();

    // Cache leeren (data/cache)
    $cacheDir = __DIR__ . '/../data/cache';
    if (is_dir($cacheDir)) {
        foreach (glob($cacheDir . '/*.json') ?: [] as $cacheFile) {
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
