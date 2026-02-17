<?php
/**
 * Save News Article
 * Security: Auth + CSRF protection
 */

require_once __DIR__ . '/../lib/Security.php';
require_once __DIR__ . '/../lib/LmoDatabase.php';

Security::initSession();
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['lmo26_admin']) || $_SESSION['lmo26_admin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'csrf_token' => Security::getCsrfToken()]);
    exit;
}

// CSRF protection
Security::requireCsrf();

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

$id = $input['id'] ?? null;
$title = trim($input['title'] ?? '');
$content = trim($input['content'] ?? '');
$author = trim($input['author'] ?? 'Admin');
$timestamp = time(); // Update timestamp on save? Or keep original? Let's update for now so it bumps up.

if (empty($title) || empty($content)) {
    http_response_code(400);
    echo json_encode(['error' => 'Title and Content are required']);
    exit;
}

try {
    $pdo = LmoDatabase::getInstance();

    if ($id) {
        // Update
        $stmt = $pdo->prepare("UPDATE news SET title = :t, content = :c, author = :a, timestamp = :ts WHERE id = :id");
        $stmt->execute([
            ':t' => $title,
            ':c' => $content,
            ':a' => $author,
            ':ts' => $timestamp,
            ':id' => $id
        ]);
    } else {
        // Insert
        // Use a random large ID or auto-increment? Schema says INTEGER PRIMARY KEY which auto-increments if null.
        // But the legacy data used strict IDs.
        // Let's rely on SQLite Autoincrement behavior for new items (pass NULL for ID).
        $stmt = $pdo->prepare("INSERT INTO news (title, content, author, timestamp) VALUES (:t, :c, :a, :ts)");
        $stmt->execute([
            ':t' => $title,
            ':c' => $content,
            ':a' => $author,
            ':ts' => $timestamp
        ]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
