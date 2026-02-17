<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

$token = 'lmo26-admin-ops-8c1b6f8e7d';
if (isset($_GET['action']) && $_GET['action'] === 'clear_rl' && ($_GET['t'] ?? '') === $token) {
    try {
        $dbPath = __DIR__ . '/../data/security.db';
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("DELETE FROM rate_limits");
        echo "RATE_LIMITS: CLEARED\n";
    } catch (Throwable $e) {
        echo "RATE_LIMITS: ERROR " . $e->getMessage() . "\n";
    }
    // Also show toggle state
    $toggle = __DIR__ . '/../data/ratelimit.off';
    echo "TOGGLE_FILE_EXISTS: " . (file_exists($toggle) ? "YES" : "NO") . "\n";
    exit;
}

echo "=== LMO26 SERVER CHECK ===\n";
echo "PHP Version: " . phpversion() . "\n";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "Current Dir: " . __DIR__ . "\n";

$lmo4Dir = realpath(__DIR__ . '/../ligen');
echo "LMO26 Ligen Dir (Resolved): " . ($lmo4Dir ? $lmo4Dir : "NOT FOUND") . "\n";

if ($lmo4Dir && is_dir($lmo4Dir)) {
    echo "Ligen Dir Exists: YES\n";
    echo "Writable: " . (is_writable($lmo4Dir) ? "YES" : "NO") . "\n";

    $files = scandir($lmo4Dir);
    $l98s = 0;
    foreach ($files as $f) {
        if (substr($f, -4) === '.l98')
            $l98s++;
    }
    echo "Found .l98 files: $l98s\n";
} else {
    echo "ERROR: Could not find ../../lmo4/ligen directory.\n";
    echo "Expected path: " . __DIR__ . '/../../lmo4/ligen' . "\n";
}

// Login/RateLimit diagnostics
$toggle = __DIR__ . '/../data/ratelimit.off';
echo "\n=== LOGIN/RATELIMIT DIAG ===\n";
echo "Toggle file: " . (file_exists($toggle) ? "present" : "absent") . "\n";
$loginFile = __DIR__ . '/login.php';
echo "login.php mtime: " . (file_exists($loginFile) ? date('c', filemtime($loginFile)) : 'missing') . "\n";
// login.override
$ov = __DIR__ . '/../data/login.override';
if (file_exists($ov)) {
    $c = @file_get_contents($ov);
    $len = is_string($c) ? strlen($c) : 0;
    echo "login.override: present ($len bytes)\n";
} else {
    echo "login.override: absent\n";
}
// users table presence
try {
    $pdo = new PDO('sqlite:' . (__DIR__ . '/../data/database.sqlite'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hasUsers = (bool)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    echo "users table: " . ($hasUsers ? "exists" : "missing") . "\n";
    if ($hasUsers) {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        echo "users count: $cnt\n";
        $row = $pdo->query("SELECT id,username,role,active,length(password_hash) hash_len FROM users WHERE username='admin'")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            echo "admin row: id={$row['id']} role={$row['role']} active={$row['active']} hash_len={$row['hash_len']}\n";
        } else {
            echo "admin row: not found\n";
        }
    }
} catch (Throwable $e) {
    echo "users check error: " . $e->getMessage() . "\n";
}

echo "\n=== EXTENSIONS ===\n";
echo "mbstring: " . (extension_loaded('mbstring') ? 'OK' : 'MISSING (Critical for umlauts)') . "\n";
echo "json: " . (extension_loaded('json') ? 'OK' : 'MISSING') . "\n";
echo "PDO: " . (class_exists('PDO') ? 'OK' : 'MISSING') . "\n";
echo "pdo_sqlite: " . (extension_loaded('pdo_sqlite') ? 'OK' : 'MISSING') . "\n";
echo "sqlite3: " . (extension_loaded('sqlite3') ? 'OK' : 'MISSING') . "\n";
echo "SQLite Version: " . (class_exists('SQLite3') ? SQLite3::version()['versionString'] : 'N/A') . "\n";

echo "\n=== TEST READ ===\n";
// Try to include parser
try {
    require_once '../lib/LmoParser.php';
    echo "LmoParser included: OK\n";
} catch (Throwable $e) {
    echo "LmoParser include FAILED: " . $e->getMessage() . "\n";
}
