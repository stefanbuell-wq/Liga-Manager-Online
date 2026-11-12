<?php
/**
 * Release Notes fuer den Admin-Bereich (Redaktion und Admins).
 * Liefert RELEASE-NOTES.md als Text; .md-Dateien sind per .htaccess direkt nicht abrufbar.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/Security.php';

Security::initSession();
if (!Security::isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Nicht autorisiert']);
    exit;
}

$file = __DIR__ . '/../RELEASE-NOTES.md';
if (!is_file($file)) {
    http_response_code(404);
    echo json_encode(['error' => 'Keine Release Notes vorhanden']);
    exit;
}

echo json_encode([
    'success' => true,
    'markdown' => file_get_contents($file),
    'updated' => date('d.m.Y', filemtime($file)),
], JSON_UNESCAPED_UNICODE);
