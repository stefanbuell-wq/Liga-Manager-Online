<?php
/**
 * Historie (Ewige Tabelle, Meister/Pokalsieger, Rekorde, Vereine)
 *
 * Oeffentlich:
 *   GET ?view=meta                         – Wettbewerbe
 *   GET ?comp=<slug>&view=table[&live=1]   – Ewige Tabelle (live = laufende Saison mitzählen)
 *   GET ?comp=<slug>&view=titles           – Meister und Pokalsieger
 *   GET ?comp=<slug>&view=records          – Rekorde aus den erfassten Spielen
 *   GET ?comp=<slug>&view=club&id=<id>     – Platzierungen eines Vereins
 * Nur Admin:
 *   GET ?comp=<slug>&view=admin            – übernehmbare Saisons, nicht zugeordnete Vereinsnamen, Vereinsliste
 *   POST JSON {action: adopt, comp, season, cup?, force?}
 *   POST JSON {action: unadopt, comp, season}
 *   POST JSON {action: map, comp, team_name, club_id? | new_club?}
 */
require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/HistoryService.php';

header('Content-Type: application/json; charset=utf-8');

function hJson(array $data, int $code = 200): void
{
    http_response_code($code);
    if (session_status() === PHP_SESSION_ACTIVE) $data['csrf_token'] = Security::getCsrfToken();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $svc = new HistoryService();
    $method = $_SERVER['REQUEST_METHOD'];
    $in = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
    $view = $in['view'] ?? 'table';
    $comp = $svc->competition(isset($in['comp']) && $in['comp'] !== '' ? (string)$in['comp'] : null);

    if ($method === 'GET' && $view !== 'admin') {
        header('Cache-Control: no-cache');
        if ($view === 'meta') hJson(['success' => true, 'data' => $svc->competitions()]);
        if (!$comp) hJson(['success' => false, 'error' => 'Für diese Seite sind noch keine historischen Daten hinterlegt.'], 404);
        $head = ['slug' => $comp['slug'], 'name' => $comp['name'], 'source' => $comp['source'], 'notes' => $comp['notes'],
                 'baseline' => HistoryService::seasonLabel((int)$comp['baseline_until'])];
        switch ($view) {
            case 'table':   hJson(['success' => true, 'competition' => $head, 'data' => $svc->eternalTable($comp, !empty($in['live']))]);
            case 'titles':  hJson(['success' => true, 'competition' => $head, 'data' => $svc->titles($comp)]);
            case 'records': hJson(['success' => true, 'competition' => $head, 'data' => $svc->records($comp)]);
            case 'club':
                $c = $svc->club($comp, (int)($in['id'] ?? 0));
                if (!$c) hJson(['success' => false, 'error' => 'Verein nicht gefunden.'], 404);
                hJson(['success' => true, 'competition' => $head, 'data' => $c]);
        }
        hJson(['success' => false, 'error' => 'Unbekannte Ansicht.'], 400);
    }

    // Ab hier nur Admin
    Security::initSession();
    if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') hJson(['success' => false, 'error' => 'Nur für Administratoren.'], 403);
    if (!$comp) hJson(['success' => false, 'error' => 'Kein Wettbewerb mit historischen Daten vorhanden (Import: scripts/history-import.php).'], 404);

    if ($method === 'GET') {
        $clubs = array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name']], $svc->eternalTable($comp)['rows']);
        usort($clubs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        hJson(['success' => true, 'data' => [
            'competition' => ['slug' => $comp['slug'], 'name' => $comp['name'], 'baseline' => HistoryService::seasonLabel((int)$comp['baseline_until'])],
            'seasons' => $svc->adoptableSeasons($comp),
            'unmapped' => $svc->unmappedTeams($comp),
            'clubs' => $clubs,
        ]]);
    }
    if ($method !== 'POST') hJson(['success' => false, 'error' => 'Methode nicht erlaubt'], 405);
    Security::requireCsrf();
    $user = (string)($_SESSION['lmo26_username'] ?? $_SESSION['lmo26_user'] ?? 'admin');
    switch ($in['action'] ?? '') {
        case 'adopt':
            $r = $svc->adopt($comp, (int)($in['season'] ?? 0), isset($in['cup']) ? (string)$in['cup'] : null, $user, !empty($in['force']));
            hJson(['success' => true, 'data' => $r, 'message' => 'Saison ' . HistoryService::seasonLabel($r['season']) . ' übernommen' . ($r['champion'] ? ' – Meister: ' . $r['champion'] : '') . '.']);
        case 'unadopt':
            $svc->unadopt($comp, (int)($in['season'] ?? 0));
            hJson(['success' => true, 'message' => 'Übernahme rückgängig gemacht.']);
        case 'map':
            $svc->mapTeam($comp, (string)($in['team_name'] ?? ''), isset($in['club_id']) ? (int)$in['club_id'] : null, isset($in['new_club']) ? (string)$in['new_club'] : null);
            hJson(['success' => true, 'message' => 'Zuordnung gespeichert.']);
    }
    hJson(['success' => false, 'error' => 'Unbekannte Aktion.'], 400);
} catch (InvalidArgumentException $e) {
    hJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('history.php: ' . $e->getMessage());
    hJson(['success' => false, 'error' => 'Interner Fehler.'], 500);
}
