<?php
/**
 * Wappen per Kommandozeile setzen (gleiche Verarbeitung wie Admin -> Wappen)
 * Aufruf (im Webroot): php scripts/crest-set.php "<Vereinsname>" <bilddatei>
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/CrestService.php';
if ($argc < 3) { fwrite(STDERR, "Aufruf: php scripts/crest-set.php \"<Vereinsname>\" <bilddatei>\n"); exit(1); }
[$name, $file] = [$argv[1], $argv[2]];
if (!is_file($file)) { fwrite(STDERR, "Datei nicht gefunden: $file\n"); exit(1); }
$st = (new CrestService())->upload($name, $file, (int)filesize($file));
echo "Wappen gesetzt: $name -> " . ($st['url'] ?? '?') . "\n";
