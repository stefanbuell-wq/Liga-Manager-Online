<?php
/**
 * HD-Wappen fuer das Web verkleinern (nur lokal): php scripts/resize-crests.php [Groesse=160]
 * Originale (350 px von fussball.de) werden nach data/crests/orig/ verschoben und bleiben dort,
 * img/teams/hd/*.png erhalten die verkleinerte Fassung (Transparenz bleibt).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$size = (int) ($argv[1] ?? 160);
$root = dirname(__DIR__);
$orig = "$root/data/crests/orig";
@mkdir($orig, 0775, true);

$n = 0; $before = 0; $after = 0;
foreach (glob("$root/img/teams/hd/*.png") as $file) {
    $o = "$orig/" . basename($file);
    [$w, $h] = getimagesize($file);
    if ($w > $size || !is_file($o)) copy($file, $o); // frisch geladen (gross) oder neu: als Original merken
    $src = imagecreatefrompng($o);
    [$w, $h] = [imagesx($src), imagesy($src)];
    $before += filesize($o);
    if ($w <= $size) { copy($o, $file); $after += filesize($file); imagedestroy($src); continue; }
    $nh = (int) round($h * $size / $w);
    $dst = imagecreatetruecolor($size, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $nh, $w, $h);
    imagepng($dst, $file, 9);
    imagedestroy($src); imagedestroy($dst);
    $after += filesize($file);
    $n++;
}
printf("%d verkleinert auf %d px: %.1f MB -> %.1f MB\n", $n, $size, $before / 1048576, $after / 1048576);
