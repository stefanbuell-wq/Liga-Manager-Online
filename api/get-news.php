<?php
/**
 * API-Endpunkt: News/Spielbericht abrufen
 *
 * Parameter:
 *   - id: News-ID (einzelner Artikel)
 *   - match_id: Match-ID (alle News zu einem Spiel)
 *   - list: 1 (Liste aller News mit Paginierung)
 *   - limit: Anzahl (Standard: 20)
 *   - offset: Start-Position (Standard: 0)
 *   - search: Suchbegriff
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/LmoRepository.php';

function injectLocalTeamLogos($html) {
    if (!$html) return $html;
    $original = $html;
    $teamLogoMap = [
        'TuS Dassendorf' => 'TuSDassendorf.gif',
        'TuRa Harksheide' => 'TuRaHarksheide.gif',
        'SC V/W 04 Billstedt' => 'SCVorwrtsWackerBillstedt.gif',
        'SC V/W Billstedt' => 'SCVorwrtsWackerBillstedt.gif',
        'SC VW 04 Billstedt' => 'SCVorwrtsWackerBillstedt.gif',
        'V/W 04 Billstedt' => 'SCVorwrtsWackerBillstedt.gif',
        'Billstedt' => 'SCVorwrtsWackerBillstedt.gif',
        'SC Vier- und Marschlande' => 'SCVierundMarschlande.gif',
        'SC V/M' => 'SCVierundMarschlande.gif',
        'SCVM' => 'SCVierundMarschlande.gif',
        'Altona 93' => 'Altona93.gif',
        'ETSV Hamburg' => 'ETSVHamburg.gif',
        'Niendorfer TSV' => 'NiendorferTSV.gif',
        'Rahlstedter SC' => 'RahlstedterSC.gif',
        'SC Victoria' => 'SCVictoria.gif',
        'SC Condor' => 'SCCondor.gif',
        'Hamm United FC' => 'HammUnitedFC.gif',
        'USC Paloma' => 'USCPaloma.gif',
        'HEBC' => 'HEBC.gif',
        'TSV Sasel' => 'TSVSasel.gif',
        'SV Curslack-Neuengamme' => 'SVCurslackNeuengamme.gif',
        'SV Rugenbergen' => 'SVRugenbergen.gif',
        'TSV Buchholz 08' => 'TSVBuchholz08.gif',
        'VfL Lohbrügge' => 'VfLLohbrgge.gif',
        'VfL Lohbruegge' => 'VfLLohbrgge.gif'
    ];
    // Aus fetter Kopfzeile Teamnamen extrahieren: "<b>TeamA – TeamB ...</b>"
    $teams = null;
    if (preg_match('/<b>\s*([^<>\n\r]+?)\s*[–\-]\s*([^<>\n\r]+?)\s*(?:\d|\\(|$)/u', $html, $m)) {
        $t1 = trim($m[1]);
        $t2 = trim($m[2]);
        $teams = [$t1, $t2];
    }
    // Alternativ aus "vs."-Zeile extrahieren
    if (!$teams && preg_match('/>\s*([^<>]+?)\s*vs\.?\s*([^<>]+?)\s*</i', $html, $m)) {
        $t1 = trim($m[1]);
        $t2 = trim($m[2]);
        $teams = [$t1, $t2];
    }
    if (!$teams) return $original;
    // Map auf lokale Dateien
    $logos = [];
    foreach ($teams as $t) {
        $found = null;
        // 1. Direkter Treffer
        if (isset($teamLogoMap[$t])) $found = $teamLogoMap[$t];
        // 2. Grobe Heuristiken
        if (!$found) {
            // Normalize Umlaute zu einfachen Buchstaben für heuristische Suche
            $n = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
            $n = strtolower(preg_replace('/[^a-z0-9]+/i', '', $n ?? $t));
            foreach ($teamLogoMap as $name => $file) {
                $nn = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
                $nn = strtolower(preg_replace('/[^a-z0-9]+/i', '', $nn ?? $name));
                if ($nn && $n && (strpos($n, $nn) !== false || strpos($nn, $n) !== false)) {
                    $found = $file;
                    break;
                }
            }
        }
        if ($found) {
            $logos[] = '<img src="img/teams/' . $found . '" alt="' . htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="max-height:60px;vertical-align:middle;" onerror="this.src=\'img/teams/blank.png\';">';
        } else {
            $logos[] = '<img src="img/teams/blank.png" alt="" style="max-height:60px;vertical-align:middle;">';
        }
    }
    if (count($logos) === 2) {
        $block = '<center>' . $logos[0] . ' &nbsp;vs.&nbsp; ' . $logos[1] . '</center>';
        // Vorhandene Vereinswappen/Proxy-Zeilen ersetzen
        $html = preg_replace('/<center>.*?vs\.?.*?<\/center>/is', $block, $html, 1, $replaced);
        if (!$replaced) {
            $html = $block . "<br>\n" . $html;
        }
    }
    return $html;
}

function rewriteContentForCSP($html) {
    if (!$html) return $html;
    // Unzulässige oder nicht vorhandene Relativpfade entfernen (../images/*)
    $html = preg_replace('#<img[^>]+src=["\']\.\./images/[^"\']+["\'][^>]*>#i', '', $html);
    // Erlaubte externe Domains via Proxy einbinden
    $allowedHosts = [
        'www.vereinswappen.de',
        'vereinswappen.de',
        'www.stpaulicoffee.de',
        'stpaulicoffee.de',
        'www.lotto-hh.de',
        'lotto-hh.de'
    ];
    $html = preg_replace_callback('#<img([^>]+)src=["\'](https?://[^"\']+)["\']([^>]*)>#i', function ($m) use ($allowedHosts) {
        $pre = $m[1]; $url = $m[2]; $post = $m[3];
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && in_array(strtolower($host), $allowedHosts, true)) {
            $proxied = 'api/img-proxy.php?u=' . rawurlencode($url);
            return '<img' . $pre . 'src="' . $proxied . '"' . $post . '>';
        }
        // Für nicht erlaubte Fremdquellen Bild entfernen
        return '';
    }, $html);
    return $html;
}

try {
    $repo = new LmoRepository();

    // Einzelne News abrufen
    if (isset($_GET['id'])) {
        $id = (int) $_GET['id'];
        $news = $repo->getNewsById($id);

        if (!$news) {
            http_response_code(404);
            echo json_encode(['error' => 'News nicht gefunden', 'id' => $id]);
            exit;
        }

        // Datum formatieren
        $news['date_formatted'] = $news['timestamp'] > 0
            ? date('d.m.Y H:i', $news['timestamp'])
            : null;

        // Inhalte für CSP aufbereiten
        $news['content'] = injectLocalTeamLogos($news['content'] ?? '');
        $news['content'] = rewriteContentForCSP($news['content'] ?? '');
        $news['short_content'] = rewriteContentForCSP($news['short_content'] ?? '');

        echo json_encode([
            'success' => true,
            'news' => $news
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News für ein Match abrufen
    if (isset($_GET['match_id'])) {
        $matchId = (int) $_GET['match_id'];
        $newsList = $repo->getNewsForMatch($matchId);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y H:i', $news['timestamp'])
                : null;
        }

        echo json_encode([
            'success' => true,
            'match_id' => $matchId,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News-Suche
    if (isset($_GET['search'])) {
        $query = trim($_GET['search']);
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;

        $newsList = $repo->searchNews($query, $limit);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y', $news['timestamp'])
                : null;
        }

        echo json_encode([
            'success' => true,
            'query' => $query,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // News-Liste (Paginierung)
    if (isset($_GET['list'])) {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;

        $newsList = $repo->getNewsList($limit, $offset);

        foreach ($newsList as &$news) {
            $news['date_formatted'] = $news['timestamp'] > 0
                ? date('d.m.Y', $news['timestamp'])
                : null;
            $news['preview'] = rewriteContentForCSP($news['preview'] ?? '');
        }

        echo json_encode([
            'success' => true,
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($newsList),
            'news' => $newsList
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    // Keine Parameter - Hilfe ausgeben
    echo json_encode([
        'error' => 'Parameter fehlt',
        'usage' => [
            'id' => 'News-ID für einzelnen Artikel',
            'match_id' => 'Match-ID für alle News zu einem Spiel',
            'list' => '1 für paginierte Liste',
            'search' => 'Suchbegriff',
            'limit' => 'Anzahl (Standard: 20)',
            'offset' => 'Start-Position (Standard: 0)'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Serverfehler',
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
