<?php
/**
 * Taegliches Briefing an Redaktion/Admins (Empfaenger und Bereiche: Admin > Einstellungen).
 * Nur per CLI, z. B. Plesk "Geplante Aufgaben" taeglich 07:05:
 *   php /pfad/zum/webroot/cron/daily-briefing.php
 * Optionen: --force (auch wenn heute schon gesendet), --dry-run (nur anzeigen, nichts senden)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../lib/BriefingService.php';

$b = new BriefingService();
if (in_array('--dry-run', $argv, true)) {
    $cfg = $b->getConfig();
    $data = $b->collect();
    foreach ($cfg['recipients'] as $r) {
        $mail = $b->render($r, $data, $cfg['send_empty']);
        echo "=== An {$r['email']}: " . ($mail ? $mail['subject'] : '(keine Mail, nichts offen)') . " ===\n";
        if ($mail) echo $mail['body'], "\n";
    }
    exit;
}
foreach ($b->sendAll(in_array('--force', $argv, true)) as $to => $status) {
    echo date('c') . " $to: $status\n";
}
