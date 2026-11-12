<?php
/**
 * Sponsoren-Banner API
 *
 * Oeffentlich:
 *   GET                       – aktive Banner je Position (top/left/right)
 *   GET  ?go=ID               – Klick zaehlen und zum Sponsor weiterleiten
 *   POST action=seen, v={id:n} – Sichtkontakte melden (per sendBeacon, ohne Cookie)
 *
 * Verwaltung (nur Rolle admin, POST mit CSRF):
 *   GET  ?action=list[&from&to]   – alle Banner inkl. Zaehler im Zeitraum
 *   GET  ?action=csv&from&to[&id] – Tageswerte als CSV
 *   POST action=save (multipart)  – anlegen/aendern inkl. Bild-Upload
 *   POST action=toggle, id, active
 *   POST action=delete, id
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/SponsorRepository.php';

function fdJson(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fdIsBot(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|fetch|curl|wget|python|httpclient|headless|lighthouse/i', $ua);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $repo = new SponsorRepository();

    /* ---------- Klick-Weiterleitung ---------- */
    if ($method === 'GET' && isset($_GET['go'])) {
        $id = (int)$_GET['go'];
        $url = $repo->getLinkForClick($id);
        if (!$url) {
            header('Location: ../', true, 302);
            exit;
        }
        if (!fdIsBot()) {
            $repo->countClick($id);
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: origin');
        header('X-Robots-Tag: noindex, nofollow');
        header('Location: ' . $url, true, 302);
        exit;
    }

    $action = $_GET['action'] ?? '';
    $body = [];
    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $body = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
        $action = $_POST['action'] ?? $body['action'] ?? '';
    }

    /* ---------- Oeffentlich: Banner ausliefern ---------- */
    if ($method === 'GET' && $action === '') {
        header('Cache-Control: public, max-age=120');
        fdJson(['success' => true, 'data' => $repo->getPublicBanners()]);
    }

    /* ---------- Oeffentlich: Sichtkontakte ---------- */
    if ($method === 'POST' && $action === 'seen') {
        // Pro IP hoechstens 300 Zaehlaufrufe in 15 Min, damit die Statistik nicht aufgeblaeht wird
        $seenId = 'seen:' . Security::getClientIp();
        if (!fdIsBot() && isset($body['v']) && is_array($body['v']) && Security::checkRateLimit($seenId, 300)['allowed']) {
            Security::recordFailedAttempt($seenId);
            $repo->countViews(array_slice($body['v'], 0, 20, true));
        }
        http_response_code(204);
        exit;
    }

    /* ---------- Ab hier nur Admin ---------- */
    Security::initSession();
    if (!Security::isLoggedIn() || Security::getUserRole() !== 'admin') {
        fdJson(['success' => false, 'error' => 'Nur fuer Administratoren', 'csrf_token' => Security::getCsrfToken()], 403);
    }

    if ($method === 'GET' && $action === 'list') {
        fdJson([
            'success' => true,
            'data' => [
                'banners' => $repo->listAll($_GET['from'] ?? null, $_GET['to'] ?? null),
                'positions' => SponsorRepository::POSITIONS,
            ],
            'csrf_token' => Security::getCsrfToken(),
        ]);
    }

    if ($method === 'GET' && $action === 'csv') {
        $from = $_GET['from'] ?? '';
        $to = $_GET['to'] ?? '';
        if (!SponsorRepository::isDate($from) || !SponsorRepository::isDate($to)) {
            fdJson(['success' => false, 'error' => 'Zeitraum fehlt'], 400);
        }
        $rows = $repo->dailyStats($from, $to, isset($_GET['id']) ? (int)$_GET['id'] : null);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sponsoren-statistik_' . $from . '_' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM fuer Excel
        fputcsv($out, ['Datum', 'Sponsor', 'Position', 'Banner-ID', 'Sichtkontakte', 'Klicks'], ';', '"', '');
        foreach ($rows as $r) {
            $sponsor = preg_match('/^[=+\-@]/', $r['sponsor']) ? "'" . $r['sponsor'] : $r['sponsor'];
            fputcsv($out, [$r['day'], $sponsor, SponsorRepository::POSITIONS[$r['position']]['label'] ?? $r['position'],
                $r['banner_id'], $r['views'], $r['clicks']], ';', '"', '');
        }
        fclose($out);
        exit;
    }

    if ($method !== 'POST') {
        fdJson(['success' => false, 'error' => 'Ungueltige Anfrage'], 400);
    }

    Security::requireCsrf();
    $in = $_POST ?: $body;

    switch ($action) {
        case 'save':
            $id = $repo->save($in, [
                'image' => $_FILES['image'] ?? null,
                'image_alt' => $_FILES['image_alt'] ?? null,
            ]);
            fdJson(['success' => true, 'data' => ['id' => $id], 'message' => 'Gespeichert']);

        case 'toggle':
            $repo->setActive((int)($in['id'] ?? 0), !empty($in['active']));
            fdJson(['success' => true]);

        case 'delete':
            $repo->delete((int)($in['id'] ?? 0));
            fdJson(['success' => true, 'message' => 'Geloescht']);

        default:
            fdJson(['success' => false, 'error' => 'Unbekannte Aktion'], 400);
    }
} catch (InvalidArgumentException $e) {
    fdJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('foerderer.php: ' . $e->getMessage());
    fdJson(['success' => false, 'error' => 'Serverfehler'], 500);
}
