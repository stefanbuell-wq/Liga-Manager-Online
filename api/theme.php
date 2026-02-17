<?php
/**
 * Theme API - GET/POST Theme Settings
 *
 * GET: Returns current theme settings as JSON
 * POST: Updates theme settings (requires authentication)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../lib/LmoDatabase.php';

// Ensure settings table exists
$pdo = LmoDatabase::getInstance();
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Default theme values
$defaultTheme = [
    // Branding
    'siteName' => 'HAFO.de',
    'siteTagline' => 'Hamburger Fußball Online',
    'logoUrl' => '',
    'faviconUrl' => '',

    // Primary Colors
    'colorPrimary' => '#E2001A',
    'colorPrimaryHover' => '#ff1a35',
    'colorPrimaryDark' => '#b80015',

    // Background Colors
    'colorBgDark' => '#0D0D0D',
    'colorBgCard' => '#1A1F1A',
    'colorBgCardHover' => '#242924',
    'colorBgElevated' => '#1E231E',

    // Text Colors
    'colorTextPrimary' => '#FFFFFF',
    'colorTextSecondary' => '#A0A0A0',
    'colorTextMuted' => '#666666',

    // Accent Colors
    'colorLive' => '#00FF88',
    'colorSuccess' => '#22C55E',
    'colorWarning' => '#F59E0B',
    'colorError' => '#EF4444',

    // Borders & Effects
    'borderRadius' => '12',
    'borderOpacity' => '0.08',
    'shadowIntensity' => '0.4',

    // Typography
    'fontPrimary' => 'Inter',
    'fontDisplay' => 'Urbanist',

    // Layout
    'containerWidth' => '1400',
    'headerHeight' => '72',
];

/**
 * Get all theme settings
 */
function getThemeSettings($pdo, $defaults) {
    $stmt = $pdo->query("SELECT key, value FROM settings WHERE key LIKE 'theme_%'");
    $rows = $stmt->fetchAll();

    $theme = $defaults;
    foreach ($rows as $row) {
        $key = str_replace('theme_', '', $row['key']);
        // Convert camelCase
        $key = lcfirst(str_replace('_', '', ucwords($key, '_')));
        if (isset($theme[$key])) {
            $theme[$key] = $row['value'];
        }
    }

    return $theme;
}

/**
 * Save theme settings
 */
function saveThemeSettings($pdo, $settings) {
    $stmt = $pdo->prepare("INSERT OR REPLACE INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))");

    foreach ($settings as $key => $value) {
        // Convert camelCase to snake_case for DB
        $dbKey = 'theme_' . strtolower(preg_replace('/([A-Z])/', '_$1', $key));
        $stmt->execute([$dbKey, $value]);
    }

    // Clear theme cache
    $cacheFile = __DIR__ . '/../data/cache/theme.json';
    if (file_exists($cacheFile)) {
        unlink($cacheFile);
    }

    return true;
}

// Handle request
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check cache first
    $cacheFile = __DIR__ . '/../data/cache/theme.json';
    $cacheTime = 3600; // 1 hour

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
        readfile($cacheFile);
        exit;
    }

    $theme = getThemeSettings($pdo, $defaultTheme);

    // Generate CSS variables mapping
    $theme['cssVariables'] = [
        '--color-primary' => $theme['colorPrimary'],
        '--color-primary-hover' => $theme['colorPrimaryHover'],
        '--color-primary-dark' => $theme['colorPrimaryDark'],
        '--color-bg-dark' => $theme['colorBgDark'],
        '--color-bg-card' => $theme['colorBgCard'],
        '--color-bg-card-hover' => $theme['colorBgCardHover'],
        '--color-bg-elevated' => $theme['colorBgElevated'],
        '--color-text-primary' => $theme['colorTextPrimary'],
        '--color-text-secondary' => $theme['colorTextSecondary'],
        '--color-text-muted' => $theme['colorTextMuted'],
        '--color-live' => $theme['colorLive'],
        '--color-success' => $theme['colorSuccess'],
        '--color-warning' => $theme['colorWarning'],
        '--color-error' => $theme['colorError'],
        '--radius-md' => $theme['borderRadius'] . 'px',
        '--radius-lg' => (intval($theme['borderRadius']) + 4) . 'px',
        '--radius-xl' => (intval($theme['borderRadius']) + 12) . 'px',
        '--border-color' => 'rgba(255, 255, 255, ' . $theme['borderOpacity'] . ')',
        '--shadow-card' => '0 4px 24px rgba(0, 0, 0, ' . $theme['shadowIntensity'] . ')',
        '--font-primary' => "'" . $theme['fontPrimary'] . "', -apple-system, BlinkMacSystemFont, sans-serif",
        '--font-display' => "'" . $theme['fontDisplay'] . "', '" . $theme['fontPrimary'] . "', sans-serif",
    ];

    $json = json_encode($theme);

    // Cache result
    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    file_put_contents($cacheFile, $json);

    echo $json;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Require authentication for POST
    require_once __DIR__ . '/../lib/Security.php';

    session_start();
    if (!Security::isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Daten']);
        exit;
    }

    // Validate and sanitize input
    $allowed = array_keys($defaultTheme);
    $settings = [];

    foreach ($input as $key => $value) {
        if (in_array($key, $allowed)) {
            // Sanitize value based on type
            if (strpos($key, 'color') === 0 || strpos($key, 'Color') !== false) {
                // Validate hex color
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                    $settings[$key] = $value;
                }
            } elseif (in_array($key, ['borderRadius', 'containerWidth', 'headerHeight'])) {
                // Validate numeric
                $settings[$key] = max(0, intval($value));
            } elseif (in_array($key, ['borderOpacity', 'shadowIntensity'])) {
                // Validate float 0-1
                $settings[$key] = max(0, min(1, floatval($value)));
            } else {
                // Sanitize string
                $settings[$key] = htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
            }
        }
    }

    if (empty($settings)) {
        http_response_code(400);
        echo json_encode(['error' => 'Keine gültigen Einstellungen']);
        exit;
    }

    try {
        saveThemeSettings($pdo, $settings);
        echo json_encode(['success' => true, 'saved' => count($settings)]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}
