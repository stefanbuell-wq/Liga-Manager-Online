<?php
// Simple, domain-whitelisted image proxy to satisfy CSP img-src 'self'
// Usage: /api/img-proxy.php?u=<encoded URL>
header('X-Content-Type-Options: nosniff');

$u = isset($_GET['u']) ? $_GET['u'] : '';
if (!$u) {
    http_response_code(400);
    exit;
}

$url = filter_var($u, FILTER_SANITIZE_URL);
if (!preg_match('#^https?://#i', $url)) {
    http_response_code(400);
    exit;
}

$allowedHosts = [
    'www.vereinswappen.de',
    'vereinswappen.de',
    'www.stpaulicoffee.de',
    'stpaulicoffee.de',
    'www.lotto-hh.de',
    'lotto-hh.de'
];
$host = parse_url($url, PHP_URL_HOST);
if (!$host || !in_array(strtolower($host), $allowedHosts, true)) {
    http_response_code(403);
    exit;
}

// Dateicache (30 Tage): Wappen/Banner aendern sich praktisch nie
$cacheDir = __DIR__ . '/../data/cache/img';
$cacheKey = $cacheDir . '/' . sha1($url);
if (is_file($cacheKey . '.bin') && is_file($cacheKey . '.type') && filemtime($cacheKey . '.bin') > time() - 30 * 86400) {
    header('Cache-Control: public, max-age=604800');
    header('Content-Type: ' . trim((string)file_get_contents($cacheKey . '.type')));
    readfile($cacheKey . '.bin');
    exit;
}

// Abruf mit cURL. Weiterleitungen werden NICHT automatisch verfolgt, sondern
// einzeln geprueft: jedes Ziel muss wieder http(s) und ein erlaubter Host sein.
function curl_fetch($url, $verify = true) {
    $host = parse_url($url, PHP_URL_HOST);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXFILESIZE => 2 * 1024 * 1024,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER => ['Accept: image/avif,image/webp,image/*,*/*;q=0.8'],
        CURLOPT_REFERER => (parse_url($url, PHP_URL_SCHEME) . '://' . $host . '/'),
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$status, $body, $location];
}

function allowed_url($url, array $allowedHosts) {
    if (!is_string($url) || !preg_match('#^https?://#i', $url)) return false;
    $h = parse_url($url, PHP_URL_HOST);
    return $h && in_array(strtolower($h), $allowedHosts, true);
}

function fetch_allowed($url, array $allowedHosts, $verify) {
    for ($hop = 0; $hop <= 3; $hop++) {
        [$status, $body, $location] = curl_fetch($url, $verify);
        if ($status >= 300 && $status < 400 && $location) {
            if (!allowed_url($location, $allowedHosts)) return [403, false];
            $url = $location;
            continue;
        }
        return [$status, $body];
    }
    return [508, false];
}

[$status, $body] = fetch_allowed($url, $allowedHosts, true);
if ($status !== 403 && ($body === false || $status < 200 || $status >= 300)) {
    // Zweiter Versuch ohne Zertifikatspruefung (einige alte Serverzertifikate);
    // Ziel bleibt auf die erlaubten Hosts beschraenkt, Inhalt wird unten als Bild geprueft
    [$status, $body] = fetch_allowed($url, $allowedHosts, false);
}

if ($status < 200 || $status >= 300 || !$body || strlen($body) > 2 * 1024 * 1024) {
    http_response_code($status === 403 ? 403 : 404);
    exit;
}

// Inhalt muss ein echtes Rasterbild sein; der Typ kommt aus dem Inhalt, nicht aus Header/Endung
$info = @getimagesizefromstring($body);
$okTypes = ['image/gif', 'image/png', 'image/jpeg', 'image/webp'];
if (!$info || !in_array($info['mime'] ?? '', $okTypes, true)) {
    http_response_code(415);
    exit;
}
$contentType = $info['mime'];
if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) {
    @file_put_contents($cacheKey . '.bin', $body, LOCK_EX);
    @file_put_contents($cacheKey . '.type', $contentType, LOCK_EX);
}

header('Cache-Control: public, max-age=604800');
header('Content-Type: ' . $contentType);
echo $body;
