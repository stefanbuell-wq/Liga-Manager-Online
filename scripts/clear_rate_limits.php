<?php
// One-time maintenance: clear all login rate limits/lockouts.
// Usage: php scripts/clear_rate_limits.php
declare(strict_types=1);
try {
    $path = __DIR__ . '/../data/security.db';
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        identifier TEXT UNIQUE NOT NULL,
        attempts INTEGER DEFAULT 0,
        locked_until DATETIME,
        expires_at DATETIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $count = $pdo->exec("DELETE FROM rate_limits");
    echo "cleared\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    exit(1);
}

