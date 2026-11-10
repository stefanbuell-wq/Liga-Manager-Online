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

// Forennutzer und Redaktion: allgemeine Anmeldung (authenticated bleibt = Redaktion)
$response['member'] = !empty($_SESSION['lmo26_auth']) && !empty($_SESSION['lmo26_user_id']);
if ($response['member']) {
    $response['user_id'] = (int)$_SESSION['lmo26_user_id'];
    $response['username'] = $_SESSION['lmo26_username'] ?? '';
    $response['display_name'] = $_SESSION['lmo26_user_name'] ?? '';
    $response['role'] = $_SESSION['lmo26_user_role'] ?? 'user';
}

echo json_encode($response);
