<?php
/**
 * Profilbilder (Avatare) fuer Konten.
 *
 * users.avatar enthaelt einen Pfad relativ zur Website:
 *   img/avatars/<24 hex>.(webp|jpg)        – vom Nutzer hochgeladen (neu kodiert, ohne Metadaten)
 *   img/avatars/legacy/<name>.(jpg|png|gif) – aus dem alten SMF-Forum uebernommen
 *   img/teams/<Key>.gif                    – Vereinswappen (HD ueber img/teams/.htaccess)
 * Leer = Anfangsbuchstabe im Forum.
 */

require_once __DIR__ . '/LmoDatabase.php';

class AvatarService
{
    public const SIZE = 160;                 // gespeicherte Kantenlaenge (Anzeige 40–80 px, Retina)
    public const MAX_BYTES = 2 * 1048576;    // 2 MB
    private const MAX_PIXELS = 40000000;     // Schutz vor Speicher-Explosion (z. B. 8000x5000)
    private const VALID = '#^img/(avatars/[a-f0-9]{24}\.(webp|jpg)|avatars/legacy/[A-Za-z0-9_-]{1,60}\.(jpg|png|gif)|teams/[A-Za-z0-9]{1,60}\.gif)$#';
    private const IMPORT_FLAG = 'legacy_avatars_imported';

    private PDO $pdo;
    private string $root;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->root = dirname(__DIR__);
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('avatar', $cols, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN avatar TEXT");
        }
        $done = true;
    }

    public static function isValidPath(?string $p): bool
    {
        return is_string($p) && (bool)preg_match(self::VALID, $p);
    }

    public function get(int $userId): ?string
    {
        $st = $this->pdo->prepare("SELECT avatar FROM users WHERE id = ?");
        $st->execute([$userId]);
        $a = $st->fetchColumn();
        return self::isValidPath($a ?: null) ? $a : null;
    }

    /** Hochgeladenes Bild pruefen, quadratisch zuschneiden, neu kodieren und setzen */
    public function upload(int $userId, ?array $file): string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new InvalidArgumentException('Bitte ein Bild auswählen.');
        }
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Das Bild ist größer als 2 MB.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Upload fehlgeschlagen. Bitte erneut versuchen.');
        }
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Bildbearbeitung (GD) ist auf dem Server nicht verfügbar.');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new InvalidArgumentException('Erlaubt sind JPG, PNG, WebP oder GIF.');
        }
        [$w, $h] = $info;
        if ($w < 32 || $h < 32) throw new InvalidArgumentException('Das Bild ist zu klein (mindestens 32×32 Pixel).');
        if ($w * $h > self::MAX_PIXELS) throw new InvalidArgumentException('Das Bild hat zu viele Pixel. Bitte vorher verkleinern.');

        $src = @imagecreatefromstring((string)file_get_contents($file['tmp_name']));
        if (!$src) throw new InvalidArgumentException('Das Bild konnte nicht gelesen werden.');
        $src = $this->applyExifOrientation($src, $file['tmp_name'], $info['mime']);

        $dst = $this->squareResize($src, self::SIZE);
        imagedestroy($src);

        $dir = $this->root . '/img/avatars';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Bildordner kann nicht angelegt werden');
        $useWebp = function_exists('imagewebp');
        $name = bin2hex(random_bytes(12)) . ($useWebp ? '.webp' : '.jpg');
        $ok = $useWebp ? imagewebp($dst, "$dir/$name", 85) : imagejpeg($dst, "$dir/$name", 88);
        imagedestroy($dst);
        if (!$ok) throw new RuntimeException('Bild konnte nicht gespeichert werden');
        @chmod("$dir/$name", 0644);
        return $this->set($userId, "img/avatars/$name");
    }

    /** Vereinswappen als Profilbild */
    public function setCrest(int $userId, string $teamKey): string
    {
        if (!preg_match('/^[A-Za-z0-9]{1,60}$/', $teamKey)) throw new InvalidArgumentException('Unbekanntes Wappen.');
        if (!is_file($this->root . "/img/teams/hd/$teamKey.png") && !is_file($this->root . "/img/teams/$teamKey.gif")) {
            throw new InvalidArgumentException('Unbekanntes Wappen.');
        }
        return $this->set($userId, "img/teams/$teamKey.gif");
    }

    public function remove(int $userId): void
    {
        $this->set($userId, null);
    }

    /** Wappen zur Auswahl: Vereine mit HD-Wappen (Name aus data/crests/map.json) */
    public function crestChoices(): array
    {
        $map = json_decode((string)@file_get_contents($this->root . '/data/crests/map.json'), true) ?: [];
        $out = [];
        foreach ($map as $name => $e) {
            if (empty($e['file']) || !preg_match('#^img/teams/hd/([A-Za-z0-9]+)\.png$#', $e['file'], $m)) continue;
            if (!is_file($this->root . '/' . $e['file'])) continue;
            $out[$m[1]] ??= ['key' => $m[1], 'name' => $name, 'img' => "img/teams/{$m[1]}.gif"];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_values($out);
    }

    /**
     * Einmalig: Avatare aus dem alten Forum (data/legacy-avatars.json, Dateien in img/avatars/legacy/)
     * den Altkonten zuordnen, die noch kein Bild haben. Danach nie wieder automatisch.
     */
    public function importLegacyOnce(): int
    {
        require_once __DIR__ . '/SettingsRepository.php';
        $settings = new SettingsRepository();
        if ($settings->get(self::IMPORT_FLAG) !== '') return 0;
        $list = json_decode((string)@file_get_contents($this->root . '/data/legacy-avatars.json'), true);
        if (!is_array($list)) return 0;   // Datei (noch) nicht vorhanden -> spaeter erneut versuchen
        $st = $this->pdo->prepare("UPDATE users SET avatar = ? WHERE lower(username) = lower(?) AND (avatar IS NULL OR avatar = '')");
        $n = 0;
        foreach ($list as $username => $path) {
            if (!self::isValidPath($path) || strpos($path, 'img/avatars/') === 0 && strpos($path, 'img/avatars/legacy/') !== 0) continue;
            $file = strpos($path, 'img/teams/') === 0
                ? $this->root . '/img/teams/hd/' . basename($path, '.gif') . '.png'
                : $this->root . '/' . $path;
            if (!is_file($file)) continue;
            $st->execute([$path, $username]);
            $n += $st->rowCount();
        }
        $settings->set(self::IMPORT_FLAG, date('c') . " ($n)");
        return $n;
    }

    /* ---------- intern ---------- */

    private function set(int $userId, ?string $path): string
    {
        $old = $this->get($userId);
        $this->pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$path, $userId]);
        // Eigenes hochgeladenes Bild aufraeumen (Wappen/Altbilder bleiben)
        if ($old && $old !== $path && preg_match('#^img/avatars/[a-f0-9]{24}\.(webp|jpg)$#', $old)) {
            @unlink($this->root . '/' . $old);
        }
        return (string)$path;
    }

    private function squareResize($src, int $size)
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $sx = (int)(($w - $side) / 2);
        $sy = (int)(($h - $side) / 2);
        $dst = imagecreatetruecolor($size, $size);
        // Transparenz auf Weiss legen (WebP/JPEG ohne Alpha-Probleme in allen Themes)
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        return $dst;
    }

    private function applyExifOrientation($img, string $path, string $mime)
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) return $img;
        $exif = @exif_read_data($path);
        $o = (int)($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $r = imagerotate($img, $angle, 0);
            if ($r) { imagedestroy($img); return $r; }
        }
        return $img;
    }
}
