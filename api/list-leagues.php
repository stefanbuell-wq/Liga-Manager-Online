<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=600'); // 10 Minuten Cache
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/LmoRepository.php';

try {
    $repo = new LmoRepository();
    $leagues = $repo->getAllLeagues();
    echo json_encode(['leagues' => $leagues]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
