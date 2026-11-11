<?php
/**
 * Kopiert alle von News-Artikeln verwendeten Altbilder der frueheren hafo.de-Seite
 * aus dem Export nach legacy/ (gleiche Pfade wie frueher, z. B. legacy/images/stpcoffee.jpg).
 *
 * Aufruf (lokal): php scripts/build-legacy-images.php "<Pfad zum Export>/hafo.de"
 * Danach legacy/ als ZIP auf den Server hochladen (legacy/ ist gitignored).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$src = rtrim($argv[1] ?? '', '/\\');
if (!$src || !is_dir($src . '/news')) {
    fwrite(STDERR, "Exportordner (mit news/ und images/) angeben.\n");
    exit(1);
}
require_once __DIR__ . '/../lib/LmoDatabase.php';
$root = dirname(__DIR__);
$dest = $root . '/legacy';
$pdo = LmoDatabase::getInstance();

$copied = 0; $missing = 0; $bytes = 0; $seen = [];
$rows = $pdo->query('SELECT content, short_content FROM news');
while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
    preg_match_all('#<img[^>]+src\s*=\s*["\']([^"\']+)#i', ($r['content'] ?? '') . ($r['short_content'] ?? ''), $m);
    foreach ($m[1] as $s) {
        $s = html_entity_decode($s, ENT_QUOTES, 'UTF-8');
        if (preg_match('#^https?://(?:www\.)?hafo\.de/(.+)$#i', $s, $mm)) $p = $mm[1];
        elseif (strpos($s, '../') === 0) $p = substr($s, 3);
        elseif (preg_match('#^images/#i', $s)) $p = 'news/' . $s;
        else continue;
        $p = rawurldecode(strtok($p, '?#'));
        if (isset($seen[$p]) || strpos($p, '..') !== false) continue;
        $seen[$p] = true;
        if (!in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
        $from = $src . '/' . $p;
        if (!is_file($from) || @getimagesize($from) === false) { $missing++; continue; }
        $to = $dest . '/' . $p;
        if (!is_dir(dirname($to))) mkdir(dirname($to), 0755, true);
        if (!is_file($to) || filesize($to) !== filesize($from)) copy($from, $to);
        $copied++; $bytes += filesize($from);
    }
}
// Keine Skriptausfuehrung im Bilderordner
file_put_contents($dest . '/.htaccess', "Options -Indexes -ExecCGI\nRemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phar\nRemoveType .php .phtml .phar\n<FilesMatch \"\\.(?i:php\\d?|phtml|phar|pl|py|cgi|sh)$\">\n    Require all denied\n</FilesMatch>\n");
printf("%d Bilder kopiert (%.1f MB), %d fehlen im Export\n", $copied, $bytes / 1e6, $missing);
