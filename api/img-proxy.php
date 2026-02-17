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

// Fetch with cURL and small safety limits
function curl_fetch($url, $host, $verify = true) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/*,*/*;q=0.8'
        ],
        CURLOPT_REFERER => (parse_url($url, PHP_URL_SCHEME) . '://' . $host . '/'),
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => false
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = $body === false ? curl_error($ch) : null;
    curl_close($ch);
    return [$status, $contentType, $body, $err];
}

[$status, $contentType, $body, $err] = curl_fetch($url, $host, true);
if ($body === false || $status < 200 || $status >= 300) {
    // Retry without SSL verification as fallback (einige alte Serverzertifikate)
    [$status, $contentType, $body, $err] = curl_fetch($url, $host, false);
}

if ($body === false || $status < 200 || $status >= 300) {
    // Last resort: file_get_contents mit Stream-Context
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\nAccept: image/avif,image/webp,image/*,*/*;q=0.8\r\nReferer: " . (parse_url($url, PHP_URL_SCHEME) . '://' . $host . "/") . "\r\n",
            'timeout' => 10,
            'ignore_errors' => true
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\\d\\.\\d\\s+(\\d+)#', $h, $m)) {
                $status = (int)$m[1];
            }
            if (stripos($h, 'Content-Type:') === 0) {
                $contentType = trim(substr($h, strlen('Content-Type:')));
            }
        }
    }
}

if ($status < 200 || $status >= 300 || !$body) {
    http_response_code(404);
    exit;
}

// Determine content-type
if (!$contentType || stripos($contentType, 'image/') !== 0) {
    // Fallback anhand Dateiendung
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
    $map = ['gif' => 'image/gif', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
    $contentType = $map[$ext] ?? 'image/octet-stream';
}

header('Cache-Control: public, max-age=86400, immutable');
header('Content-Type: ' . $contentType);
echo $body;
?> 
