<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../lib/LmoRepository.php';

$ligaFile = $_GET['liga'] ?? 'hhoberliga2425.l98';

try {
    $repo = new LmoRepository();
    $data = $repo->getLeagueDataFull($ligaFile);
    echo json_encode($data);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
