<?php
/**
 * Auth Check Endpoint
 * Returns authentication status and CSRF token
 */

require_once __DIR__ . '/../lib/Security.php';

Security::initSession();

ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json');

$authenticated = isset($_SESSION['lmo26_admin']) && $_SESSION['lmo26_admin'] === true;

$response = [
    'authenticated' => $authenticated,
    'csrf_token' => Security::getCsrfToken()
];

// Include user info if authenticated
if ($authenticated) {
    $response['user'] = [
        'name' => $_SESSION['lmo26_user_name'] ?? 'Admin',
        'role' => $_SESSION['lmo26_user_role'] ?? 'admin'
    ];
}

echo json_encode($response);
