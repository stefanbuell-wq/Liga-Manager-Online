<?php
ini_set('display_errors', 0); // Disable display errors to ensure valid JSON
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

// Log errors to a file if needed, but for now ensure valid JSON output
try {
    require_once __DIR__ . '/../lib/LmoDatabase.php';

    $id = $_GET['id'] ?? null;

    if (!$id) {
        echo json_encode(['error' => 'No ID provided']);
        exit;
    }

    $pdo = LmoDatabase::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM news WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $news = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($news) {
        // Ensure UTF-8 encoding for JSON
        $news = utf8ize($news);
        $json = json_encode($news, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            http_response_code(500);
            echo json_encode(['error' => 'JSON encoding failed: ' . json_last_error_msg()]);
        } else {
            echo $json;
        }
    } else {
        echo json_encode(['error' => 'News not found']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
function utf8ize($d)
{
    if (is_array($d)) {
        foreach ($d as $k => $v) {
            $d[$k] = utf8ize($v);
        }
    } else if (is_string($d)) {
        // Check if already valid UTF-8, if not convert from Latin1
        if (!mb_check_encoding($d, 'UTF-8')) {
            return mb_convert_encoding($d, 'UTF-8', 'ISO-8859-1');
        }
        return $d;
    }
    return $d;
}
