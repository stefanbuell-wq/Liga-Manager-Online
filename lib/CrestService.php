<?php
/**
 * Vereinswappen verwalten (Admin -> Wappen).
 *
 * Ablage (wie bisher, damit alle Seiten und gespeicherte Berichte weiter funktionieren):
 *   img/teams/<Schluessel>.gif      kleines Wappen (28 px), Fallback ohne mod_rewrite
 *   img/teams/hd/<Schluessel>.png   hochaufloesend (max. 160 px); img/teams/.htaccess liefert es fuer X.gif aus
 * Schluessel = Vereinsname ohne Sonderzeichen (z. B. "Altona 93" -> "Altona93").
 * Ersetzte oder entfernte Wappen werden nach img/teams/_alt/ verschoben, nicht geloescht.
 */
require_once __DIR__ . '/LmoDatabase.php';

class CrestService
{
    public const HD_SIZE = 160;
    public const SMALL_SIZE = 28;
    public const MAX_BYTES = 3 * 1024 * 1024;

    private string $dir;
    private PDO $pdo;

    public function __construct(?string $root = null)
    {
        $this->dir = rtrim($root ?? (__DIR__ . '/..'), '/') . '/img/teams';
        $this->pdo = LmoDatabase::getInstance();
    }

    public static function key(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9]/', '', $name);
    }

    /** Alle Vereinsnamen aus den Ligen mit Wappen-Status */
    public function listAll(): array
    {
        $rows = $this->pdo->query("SELECT t.name, COUNT(DISTINCT t.league_id) AS seasons, MAX(l.file) AS last_file
            FROM teams t JOIN leagues l ON l.id = t.league_id
            WHERE t.name IS NOT NULL AND trim(t.name) <> ''
            GROUP BY t.name ORDER BY t.name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach ($rows as $r) {
            $k = self::key($r['name']);
            if ($k === '') continue;
            $byKey[$k]['names'][] = $r['name'];
            $byKey[$k]['seasons'] = ($byKey[$k]['seasons'] ?? 0) + (int)$r['seasons'];
        }
        $out = [];
        foreach ($byKey as $k => $e) {
            $out[] = $this->status($k) + ['name' => $e['names'][0], 'aliases' => array_slice($e['names'], 1), 'seasons' => $e['seasons']];
        }
        // Vereine nur aus der Historie (keine Saison in den Ligen): Wappen unter dem Historie-Namen
        foreach ($this->historyOnlyClubs($byKey) as $k => $e) {
            $out[] = $this->status($k) + ['name' => $e['name'], 'aliases' => [], 'seasons' => $e['seasons'], 'history' => true];
        }
        usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    private function historyOnlyClubs(array $teamKeys): array
    {
        $t = $this->pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'history_clubs'")->fetchColumn();
        if (!$t) return [];
        require_once __DIR__ . '/HistoryService.php';
        $rows = $this->pdo->query("SELECT c.name, c.years,
                (SELECT COUNT(*) FROM history_team_map m WHERE m.club_id = c.id) AS mapped
            FROM history_clubs c")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            if ((int)$r['mapped'] > 0) continue;
            $k = HistoryService::crestKeyForClub($r['name']);
            if ($k === '' || isset($teamKeys[$k]) || isset($out[$k])) continue;
            $years = json_decode((string)$r['years'], true);
            $out[$k] = ['name' => trim(preg_replace('/\s*\(.*?\)/u', '', $r['name'])), 'seasons' => is_array($years) ? count($years) : 0];
        }
        return $out;
    }

    public function status(string $key): array
    {
        $hd = is_file("$this->dir/hd/$key.png");
        $small = is_file("$this->dir/$key.gif");
        $mtime = max($hd ? filemtime("$this->dir/hd/$key.png") : 0, $small ? filemtime("$this->dir/$key.gif") : 0);
        return [
            'key' => $key,
            'hd' => $hd,
            'small' => $small,
            'url' => ($hd ? "img/teams/hd/$key.png" : ($small ? "img/teams/$key.gif" : null)),
            'version' => $mtime,
        ];
    }

    /** Neues Wappen aus einer hochgeladenen Bilddatei (PNG, JPG, GIF, WebP) */
    public function upload(string $name, string $tmpFile, int $size): array
    {
        $key = $this->requireKey($name);
        if ($size <= 0 || $size > self::MAX_BYTES) throw new InvalidArgumentException('Datei zu groß (max. 3 MB).');
        $info = @getimagesize($tmpFile);
        $types = [IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_GIF => 'imagecreatefromgif', IMAGETYPE_WEBP => 'imagecreatefromwebp'];
        if (!$info || !isset($types[$info[2]])) throw new InvalidArgumentException('Bitte ein Bild als PNG, JPG, GIF oder WebP hochladen.');
        if ($info[0] < 24 || $info[1] < 24) throw new InvalidArgumentException('Bild zu klein (mindestens 24 × 24 Pixel).');
        if ($info[0] * $info[1] > 25_000_000) throw new InvalidArgumentException('Bild zu groß (zu viele Pixel).');
        if (!function_exists($types[$info[2]])) throw new RuntimeException('Dieses Bildformat wird vom Server nicht unterstützt.');
        $src = @$types[$info[2]]($tmpFile);
        if (!$src) throw new InvalidArgumentException('Bild konnte nicht gelesen werden.');
        $src = $this->trim($src);
        $this->writeAll($key, $src);
        imagedestroy($src);
        return $this->status($key);
    }

    /** Wappen eines anderen Vereins übernehmen (z. B. für zweite Mannschaften) */
    public function copyFrom(string $name, string $sourceName): array
    {
        $key = $this->requireKey($name);
        $srcKey = $this->requireKey($sourceName);
        if ($key === $srcKey) throw new InvalidArgumentException('Quelle und Ziel sind derselbe Verein.');
        $file = is_file("$this->dir/hd/$srcKey.png") ? "$this->dir/hd/$srcKey.png" : "$this->dir/$srcKey.gif";
        if (!is_file($file)) throw new InvalidArgumentException('Der gewählte Verein hat kein Wappen.');
        $src = str_ends_with($file, '.png') ? imagecreatefrompng($file) : imagecreatefromgif($file);
        if (!$src) throw new RuntimeException('Wappen konnte nicht gelesen werden.');
        $this->writeAll($key, $src);
        imagedestroy($src);
        return $this->status($key);
    }

    /** Wappen entfernen (wird nach img/teams/_alt/ verschoben) */
    public function remove(string $name): array
    {
        $key = $this->requireKey($name);
        $this->archive($key);
        return $this->status($key);
    }

    // ------------------------------------------------------------------

    private function requireKey(string $name): string
    {
        $key = self::key($name);
        if ($key === '' || strlen($key) > 80) throw new InvalidArgumentException('Ungültiger Vereinsname.');
        return $key;
    }

    private function writeAll(string $key, $src): void
    {
        foreach (["$this->dir", "$this->dir/hd"] as $d) {
            if (!is_dir($d) && !@mkdir($d, 0755, true)) throw new RuntimeException("Ordner $d kann nicht angelegt werden.");
        }
        $this->archive($key);
        $hd = $this->fit($src, self::HD_SIZE);
        if (!imagepng($hd, "$this->dir/hd/$key.png", 9)) throw new RuntimeException('Wappen konnte nicht gespeichert werden.');
        imagedestroy($hd);
        $small = $this->fit($src, self::SMALL_SIZE);
        // GIF kennt nur eine transparente Farbe: Halbtransparenz gegen Weiss mischen, Rest transparent
        $gif = imagecreatetruecolor(imagesx($small), imagesy($small));
        $bg = imagecolorallocate($gif, 255, 255, 254);
        imagefill($gif, 0, 0, $bg);
        imagecopy($gif, $small, 0, 0, 0, 0, imagesx($small), imagesy($small));
        imagetruecolortopalette($gif, true, 255);
        $t = imagecolorclosest($gif, 255, 255, 254);
        imagecolortransparent($gif, $t);
        imagegif($gif, "$this->dir/$key.gif");
        imagedestroy($gif);
        imagedestroy($small);
        @chmod("$this->dir/hd/$key.png", 0644);
        @chmod("$this->dir/$key.gif", 0644);
    }

    /** Proportional in ein Quadrat einpassen (zentriert, transparenter Rand) */
    private function fit($src, int $box)
    {
        $w = imagesx($src); $h = imagesy($src);
        $scale = min($box / $w, $box / $h, $box >= self::HD_SIZE ? 4 : 1);
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($box, $box);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagealphablending($dst, true);
        imagecopyresampled($dst, $src, (int)(($box - $nw) / 2), (int)(($box - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagealphablending($dst, false);
        return $dst;
    }

    /** Leeren bzw. weissen Rand abschneiden, damit Wappen gleich gross wirken */
    private function trim($src)
    {
        if (function_exists('imagecropauto')) {
            $c = @imagecropauto($src, IMG_CROP_TRANSPARENT);
            if ($c === false) $c = @imagecropauto($src, IMG_CROP_WHITE);
            if ($c !== false && imagesx($c) >= 16 && imagesy($c) >= 16) {
                imagedestroy($src);
                $src = $c;
            }
        }
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);
        imagesavealpha($src, true);
        return $src;
    }

    private function archive(string $key): void
    {
        $alt = "$this->dir/_alt";
        foreach (["hd/$key.png", "$key.gif"] as $rel) {
            $f = "$this->dir/$rel";
            if (!is_file($f)) continue;
            if (!is_dir($alt)) @mkdir($alt, 0755, true);
            @rename($f, $alt . '/' . date('Ymd-His') . '-' . basename($rel));
        }
    }
}
