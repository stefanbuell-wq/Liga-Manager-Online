<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: text/plain');

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
