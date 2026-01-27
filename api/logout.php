<?php
/**
 * Logout Endpoint
 * Properly destroys session with Security class
 */

require_once __DIR__ . '/../lib/Security.php';

Security::initSession();
Security::destroySession();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
