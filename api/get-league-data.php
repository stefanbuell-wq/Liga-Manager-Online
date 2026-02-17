<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Cache für 5 Minuten (kann vom Client auch länger gecacht werden)
header('Cache-Control: public, max-age=300');

// GZIP Kompression wenn unterstützt
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}

require_once __DIR__ . '/../lib/LmoRepository.php';

$ligaFile = $_GET['liga'] ?? 'hhoberliga2425.l98';

// Validate file parameter against path traversal attacks
$ligaFile = basename($ligaFile); // Remove any path components
if (!preg_match('/^[a-zA-Z0-9_-]+\.(l98|L98|lmo|LMO)$/', $ligaFile)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid league file format']);
    exit;
}

// Einfacher File-Cache
$cacheDir = __DIR__ . '/../data/cache';
$cacheFile = $cacheDir . '/' . md5($ligaFile) . '.json';
$cacheTime = 300; // 5 Minuten

// Cache prüfen
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    readfile($cacheFile);
    exit;
}

try {
    $repo = new LmoRepository();
    $data = $repo->getLeagueDataFull($ligaFile);
    $json = json_encode($data);

    // Cache schreiben
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $json);

    echo $json;
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
