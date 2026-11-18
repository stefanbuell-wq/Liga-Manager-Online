<?php
/**
 * Ersteinrichtung einer neuen Installation (nur Kommandozeile).
 *
 *   php scripts/install.php --name="Musterliga.de" [--tagline="Fußball in Musterstadt"] [--url=https://musterliga.de] [--admin=admin]
 *   php scripts/install.php --demo [--url=...]     Demo im Stil von hafo.de mit erfundenen Beispieldaten
 *
 * - prueft PHP-Version und Erweiterungen
 * - legt data/ (Datenbank, Cache, Sessions) an
 * - schreibt config/instance.php (falls noch nicht vorhanden)
 * - legt das Datenbankschema an
 * - legt das erste Admin-Konto an (Passwort wird abgefragt; ohne Terminal: Umgebungsvariable LMO_ADMIN_PASSWORD)
 *
 * Danach: Webserver auf diesen Ordner zeigen lassen, im Admin anmelden und unter
 * "Saison-Assistent" die erste Liga anlegen. Mail-Versand: config/mail.example.php -> config/mail.php.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$opt = getopt('', ['name:', 'tagline:', 'url:', 'admin:', 'demo']);
$demo = array_key_exists('demo', $opt);

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function fail(string $s): void { fwrite(STDERR, "FEHLER: $s\n"); exit(1); }

// 1. Voraussetzungen
if (PHP_VERSION_ID < 80000) fail('PHP 8.0 oder neuer noetig (gefunden: ' . PHP_VERSION . ').');
foreach (['pdo_sqlite', 'mbstring', 'json'] as $ext) {
    if (!extension_loaded($ext)) fail("PHP-Erweiterung '$ext' fehlt.");
}
if (!extension_loaded('gd')) out('Hinweis: GD fehlt – Profil- und Forenbilder koennen nicht verkleinert werden.');

// 2. Verzeichnisse
foreach (['data', 'data/cache', 'data/sessions', 'img/avatars', 'img/forum', 'img/foerderer', 'img/teams', 'ligen'] as $d) {
    if (!is_dir("$root/$d") && !mkdir("$root/$d", 0775, true)) fail("Kann $d nicht anlegen.");
}
if (!is_writable("$root/data")) fail('data/ ist nicht beschreibbar.');

// 3. Instanz-Konfiguration
$instFile = "$root/config/instance.php";
if (!is_file($instFile)) {
    $name = trim($opt['name'] ?? '') ?: ($demo ? 'HAFO.de' : 'Liga Manager Online');
    $cfg = [
        'site_name'    => $name,
        'site_tagline' => trim($opt['tagline'] ?? '') ?: ($demo ? 'Hamburger Fußball Online – Demo' : ''),
        'base_url'     => rtrim(trim($opt['url'] ?? ''), '/'),
        'mail_tag'     => $demo ? 'HAFO' : '',
        'tip_label'    => $demo ? 'HAFO-Tipp' : 'Tipp',
        'region'       => $demo ? 'Hamburger Amateurfußball' : '',
        'legacy_forum' => false,
        'legacy_host'  => '',
        'default_league' => $demo ? 'demooberliga' : '',
        'league_short_names' => $demo ? ['Oberliga Demo' => 'Oberliga'] : [],
    ];
    file_put_contents($instFile, "<?php\n// Instanz-Einstellungen (angelegt von scripts/install.php am " . date('d.m.Y') . ")\nreturn " . var_export($cfg, true) . ";\n");
    out("config/instance.php angelegt ($name).");
} else {
    out('config/instance.php vorhanden – unveraendert.');
}

// 4. Datenbank
require_once "$root/lib/LmoDatabase.php";
require_once "$root/lib/AccountService.php";
// createSchema() legt Spiele/Berichte neu an (DROP TABLE) – deshalb nur bei leerer Datenbank
$pdo = LmoDatabase::getInstance();
$hasLeagues = (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'leagues'")->fetchColumn();
if ($hasLeagues) {
    out('Datenbank vorhanden – Schema bleibt unveraendert.');
} else {
    LmoDatabase::createSchema();
    out('Datenbank angelegt: data/database.sqlite');
}
new AccountService();

// 5. Admin-Konto
$admins = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
if ($admins > 0) {
    out("Es gibt bereits $admins Admin-Konto(en). Weitere/Passwort neu: php cron/create-admin.php <name>");
} else {
    $user = trim($opt['admin'] ?? '') ?: 'admin';
    if (!preg_match('/^[\p{L}0-9 ._-]{3,30}$/u', $user)) fail('Ungueltiger Admin-Name.');
    $pw = getenv('LMO_ADMIN_PASSWORD') ?: '';
    if ($pw === '') {
        fwrite(STDOUT, "Passwort fuer Admin '$user': ");
        $tty = function_exists('system') && stream_isatty(STDIN);
        if ($tty) system('stty -echo');
        $pw = trim((string)fgets(STDIN));
        if ($tty) system('stty echo');
        fwrite(STDOUT, "\n");
    }
    if ($err = Security::validatePassword($pw)) fail(implode('. ', $err));
    $pdo->prepare("INSERT INTO users (username, password_hash, display_name, role, active, status) VALUES (?, ?, ?, 'admin', 1, 'active')")
        ->execute([$user, password_hash($pw, PASSWORD_DEFAULT), $user]);
    out("Admin '$user' angelegt.");
}

// 6. Demo-Inhalte
if ($demo) {
    (function () { require __DIR__ . '/demo-data.php'; })();
}

out('');
out('Fertig. Naechste Schritte:');
out('  1. Webserver (Apache mit .htaccess) auf diesen Ordner richten, PHP 8+.');
out('  2. Im Browser /admin/ aufrufen und anmelden.');
out('  3. Unter "Saison-Assistent" die erste Liga mit Mannschaften und Spielplan anlegen.');
out('  4. Optional: config/mail.example.php nach config/mail.php kopieren (Mail-Versand).');
out('  5. Impressum und Datenschutzerklaerung als impressum.html / datenschutz.html ablegen.');
