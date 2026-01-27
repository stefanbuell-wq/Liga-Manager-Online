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
    echo json_encode(['error' => 'No data']);
    exit;
}

$ligaFile = $data['liga'];
$round = $data['round'];
$matches = $data['matches'];

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
            match_date = :md, match_time = :mt, match_note = :mn
        WHERE league_id = :lid
          AND round_nr = :r
          AND home_team_id = :hid
          AND guest_team_id = :gid
    ");

    $pdo->beginTransaction();

    foreach ($matches as $m) {
        // m contains home_id (original), guest_id (original)
        $hid = $teamLookup[$m['home_id']] ?? null;
        $gid = $teamLookup[$m['guest_id']] ?? null;

        if ($hid && $gid) {
            // Nullify empty values
            $hg = ($m['home_goals'] === "" || $m['home_goals'] === null) ? null : $m['home_goals'];
            $gg = ($m['guest_goals'] === "" || $m['guest_goals'] === null) ? null : $m['guest_goals'];
            $md = !empty($m['date']) ? $m['date'] : null;
            $mt = !empty($m['time']) ? $m['time'] : null;
            $mn = !empty($m['note']) ? trim($m['note']) : null;

            $stmtUpdate->execute([
                ':hg' => $hg,
                ':gg' => $gg,
                ':md' => $md,
                ':mt' => $mt,
                ':mn' => $mn,
                ':lid' => $lid,
                ':r' => $round,
                ':hid' => $hid,
                ':gid' => $gid
            ]);
        }
    }

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['error' => $e->getMessage()]);
}
