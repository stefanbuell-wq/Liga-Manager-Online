<?php
/**
 * Bild fuer einen Forenbeitrag hochladen (angemeldete Forenkonten)
 *   POST multipart: file=<bild>, csrf_token
 *   -> {success, path: "img/forum/<24 hex>.webp|jpg", width, height}
 * Das Bild wird auf max. 1600 px verkleinert, gedreht (EXIF) und neu kodiert (ohne Metadaten).
 * Im Beitrag steht es als [img]img/forum/...[/img].
 */

require_once __DIR__ . '/../lib/Security.php';

header('Content-Type: application/json; charset=utf-8');

const FI_MAX_BYTES = 8 * 1048576;
const FI_MAX_EDGE = 1600;
const FI_MAX_PIXELS = 50000000;

function fiJson(array $d, int $code = 200): void
{
    http_response_code($code);
    $d['csrf_token'] = Security::getCsrfToken();
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

Security::initSession();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fiJson(['success' => false, 'error' => 'Nur POST'], 405);
    if (!Security::isMember()) fiJson(['success' => false, 'error' => 'Bitte anmelden.'], 401);
    Security::requireCsrf();

    $uid = (int)$_SESSION['lmo26_user_id'];
    $rl = 'forumimg:' . $uid;
    if (!Security::checkRateLimit($rl, 30)['allowed']) {
        fiJson(['success' => false, 'error' => 'Zu viele Bilder in kurzer Zeit. Bitte später erneut versuchen.'], 429);
    }

    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) fiJson(['success' => false, 'error' => 'Bitte ein Bild auswählen.'], 400);
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $f['size'] > FI_MAX_BYTES) {
        fiJson(['success' => false, 'error' => 'Das Bild ist größer als 8 MB.'], 400);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fiJson(['success' => false, 'error' => 'Upload fehlgeschlagen.'], 400);
    if (!function_exists('imagecreatefromstring')) fiJson(['success' => false, 'error' => 'Bildbearbeitung ist auf dem Server nicht verfügbar.'], 500);

    $info = @getimagesize($f['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
        fiJson(['success' => false, 'error' => 'Erlaubt sind JPG, PNG, WebP oder GIF.'], 400);
    }
    [$w, $h] = $info;
    if ($w < 16 || $h < 16) fiJson(['success' => false, 'error' => 'Das Bild ist zu klein.'], 400);
    if ($w * $h > FI_MAX_PIXELS) fiJson(['success' => false, 'error' => 'Das Bild hat zu viele Pixel. Bitte vorher verkleinern.'], 400);

    Security::recordFailedAttempt($rl);

    $src = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$src) fiJson(['success' => false, 'error' => 'Das Bild konnte nicht gelesen werden.'], 400);

    // Handyfotos richtig herum drehen
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)((@exif_read_data($f['tmp_name']))['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle && ($r = imagerotate($src, $angle, 0))) { imagedestroy($src); $src = $r; }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, FI_MAX_EDGE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));   // Transparenz auf Weiss
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = __DIR__ . '/../img/forum';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Bildordner fehlt');
    $webp = function_exists('imagewebp');
    $name = bin2hex(random_bytes(12)) . ($webp ? '.webp' : '.jpg');
    $ok = $webp ? imagewebp($dst, "$dir/$name", 82) : imagejpeg($dst, "$dir/$name", 85);
    imagedestroy($dst);
    if (!$ok) throw new RuntimeException('Speichern fehlgeschlagen');
    @chmod("$dir/$name", 0644);

    fiJson(['success' => true, 'path' => "img/forum/$name", 'width' => $nw, 'height' => $nh]);
} catch (Throwable $e) {
    error_log('forum-image.php: ' . $e->getMessage());
    fiJson(['success' => false, 'error' => 'Serverfehler beim Bild-Upload'], 500);
}
