<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "DEBUG: Script started.\n";

$id = 6998; // Hardcode for testing locally via CLI
// $id = $_GET['id'] ?? null;

echo "DEBUG: ID is $id\n";

if (!$id) {
    echo json_encode(['error' => 'No ID provided']);
    exit;
}

echo "DEBUG: Requiring LmoDatabase...\n";
require_once __DIR__ . '/../lib/LmoDatabase.php';
echo "DEBUG: LmoDatabase required.\n";

try {
    echo "DEBUG: Getting PDO instance...\n";
    $pdo = LmoDatabase::getInstance();
    echo "DEBUG: PDO instance got.\n";

    $stmt = $pdo->prepare("SELECT * FROM news WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $news = $stmt->fetch();

    if ($news) {
        echo "DEBUG: News found.\n";
        echo json_encode($news);
    } else {
        echo "DEBUG: News NOT found.\n";
        echo json_encode(['error' => 'News not found']);
    }
} catch (Exception $e) {
    echo "DEBUG: Exception: " . $e->getMessage() . "\n";
    echo json_encode(['error' => $e->getMessage()]);
}

