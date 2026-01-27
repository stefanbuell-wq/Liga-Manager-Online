<?php
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../lib/LmoRepository.php';

try {
    $repo = new LmoRepository();
    // getAllLeagues returns [{file, name}, ...]
    $leagues = $repo->getAllLeagues();
    echo json_encode(['leagues' => $leagues]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
