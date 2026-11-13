<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Browser darf zwischenspeichern, muss aber jedes Mal nachfragen (ETag -> 304), damit
// neu eingetragene Ergebnisse sofort sichtbar sind (Server-Datei-Cache wird beim Speichern geleert)
header('Cache-Control: no-cache');

// GZIP Kompression wenn unterstützt
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

require_once __DIR__ . '/../lib/LmoRepository.php';

$ligaFile = $_GET['liga'] ?? null;
if ($ligaFile === null || $ligaFile === '') {
    // Standard: neueste Saison der Hamburg-Oberliga (z. B. hhoberliga2627.l98)
    require_once __DIR__ . '/../lib/LmoDatabase.php';
    $row = LmoDatabase::getInstance()
        ->query("SELECT file FROM leagues WHERE file LIKE 'hhoberliga____.l98' ORDER BY file DESC LIMIT 1")
        ->fetch();
    $ligaFile = $row['file'] ?? 'hhoberliga2627.l98';
}

// Validate file parameter against path traversal attacks
$ligaFile = basename($ligaFile); // Remove any path components
if (!preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid league file format']);
    exit;
}

// Validate optional round parameter (integer >= 1; upper bound 1..Rounds checked in repository)
$round = null;
if (isset($_GET['round']) && $_GET['round'] !== '') {
    $rawRound = $_GET['round'];
    if (!is_string($rawRound) || !preg_match('/^[1-9]\d{0,2}$/', $rawRound)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid round parameter']);
        exit;
    }
    $round = (int)$rawRound;
}

// Einfacher File-Cache (Cache-Key enthält Liga und optionalen Spieltag)
$cacheDir = __DIR__ . '/../data/cache';
$cacheKey = $round !== null ? "{$ligaFile}_round_{$round}" : $ligaFile;
$cacheFile = $cacheDir . '/' . md5($cacheKey) . '.json';
$cacheTime = 300; // 5 Minuten

// Cache prüfen
function lgdSend(string $json): void
{
    $etag = '"' . md5($json) . '"';
    header('ETag: ' . $etag);
    if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    echo $json;
}

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    lgdSend((string)file_get_contents($cacheFile));
    exit;
}

try {
    $repo = new LmoRepository();
    $data = $repo->getLeagueDataFull($ligaFile, $round);
    $json = json_encode($data);

    // Cache schreiben
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $json);

    lgdSend($json);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
