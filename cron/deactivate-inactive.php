<?php
/**
 * Deaktiviert Forenkonten, die laenger als 12 Monate nicht angemeldet waren.
 * Nur per CLI (hPanel > Erweitert > Cron-Jobs), z. B. taeglich 03:17:
 *   /usr/bin/php /var/www/vhosts/<webspace>/hafo.de/httpdocs/cron/deactivate-inactive.php
 * Zusaetzlich laeuft die Pruefung einmal taeglich beim Oeffnen der Benutzerliste im Admin.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/AccountService.php';
$n = (new AccountService())->deactivateInactive();
echo date('c') . " deaktiviert: $n\n";
