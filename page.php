<?php
/**
 * Liefert die oeffentlichen HTML-Seiten mit dem Namen der Instanz schon im HTML aus
 * (Seitentitel, Logo, Copyright) – wichtig fuer Suchmaschinen und Link-Vorschauen
 * (WhatsApp, Facebook), die kein JavaScript ausfuehren. js/instance.js uebernimmt den Rest.
 * Aufruf ueber .htaccess: index.html, forum.html, kontakt.html, konto.html, suche.html -> page.php?p=<name>
 * Ohne mod_rewrite werden die .html-Dateien direkt ausgeliefert (dann setzt nur das JavaScript den Namen).
 */
require_once __DIR__ . '/lib/Instance.php';

$pages = ['index', 'forum', 'kontakt', 'konto', 'suche', 'historie'];
$p = $_GET['p'] ?? 'index';
if (!in_array($p, $pages, true)) {
    http_response_code(404);
    exit;
}
$html = file_get_contents(__DIR__ . '/' . $p . '.html');
if ($html === false) {
    http_response_code(500);
    exit;
}

$name = Instance::siteName();
$tagline = Instance::siteTagline();
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Seitentitel
$html = preg_replace_callback('#<title data-page="([^"]*)">[^<]*</title>#', function ($m) use ($name, $tagline, $e) {
    $page = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    $t = $page !== '' ? $page . ' - ' . $name : $name . ($tagline !== '' ? ' - ' . $tagline : '');
    return '<title data-page="' . $m[1] . '">' . $e($t) . '</title>';
}, $html, 1);

// Logo: Endung (".de") in Primaerfarbe
$logo = preg_match('/^(.+)(\.[A-Za-z]{2,})$/', $name, $m)
    ? $e($m[1]) . '<span style="color: var(--color-primary);">' . $e($m[2]) . '</span>'
    : $e($name);
$html = str_replace('<span data-site-logo>Liga Manager Online</span>', '<span data-site-logo>' . $logo . '</span>', $html);
$html = str_replace('<div data-site-name>LMO</div>', '<div data-site-name>' . $e($name) . '</div>', $html);

// Copyright
$html = str_replace('>© Liga Manager Online<', '>' . $e('© ' . date('Y') . ' ' . $name . ($tagline !== '' ? ' - ' . $tagline : '')) . '<', $html);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$etag = '"' . md5($html) . '"';
header('ETag: ' . $etag);
if (trim($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
echo $html;
