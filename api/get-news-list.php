<?php
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../lib/LmoDatabase.php';

try {
    $pdo = LmoDatabase::getInstance();

    // Sort by timestamp DESC (newest first)
    // Limit to 50 for now to prevent huge payloads
    $stmt = $pdo->query("SELECT id, title, author, timestamp, substr(content, 1, 300) as preview FROM news ORDER BY timestamp DESC LIMIT 50");
    $news = $stmt->fetchAll();

    // Check if we need to convert dates or anything? 
    // JS can handle timestamp.

    echo json_encode(['news' => $news]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
