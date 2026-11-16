<?php
/**
 * Abmeldelink aus Forum-Benachrichtigungen (ohne Anmeldung, signiert):
 *   GET ?u=<user_id>&t=<topic_id>&s=<signatur>
 * Zeigt eine kurze Bestaetigungsseite.
 */

require_once __DIR__ . '/../lib/ForumComfort.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$u = (int)($_GET['u'] ?? 0);
$t = (int)($_GET['t'] ?? 0);
$s = (string)($_GET['s'] ?? '');

$ok = false;
$title = '';
try {
    $c = new ForumComfort();
    if ($u > 0 && $t > 0 && preg_match('/^[a-f0-9]{32}$/', $s) && $c->verify($u, $t, $s)) {
        $c->unsubscribe($u, $t);
        $ok = true;
        $st = LmoDatabase::getInstance()->prepare("SELECT title FROM forum_topics WHERE id = ?");
        $st->execute([$t]);
        $title = (string)$st->fetchColumn();
    }
} catch (Throwable $e) {
    error_log('forum-abo.php: ' . $e->getMessage());
}
$h = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>HAFO.de – Benachrichtigung</title>
<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:0 20px;color:#222;line-height:1.5}a{color:#d40000}</style>
</head><body>
<h1>HAFO.de</h1>
<?php if ($ok): ?>
<p>Erledigt: Du bekommst keine E-Mails mehr zum Thema <strong>„<?= $h($title) ?>“</strong>.</p>
<p>Wieder abonnieren kannst du im Thema mit dem Knopf „🔔 Abonnieren“.</p>
<p><a href="../forum.html?topic=<?= $t ?>">Zum Thema</a></p>
<?php else: ?>
<p>Dieser Abmeldelink ist ungültig oder unvollständig. Bitte den Link aus der E-Mail vollständig kopieren.</p>
<p><a href="../forum.html">Zum Forum</a></p>
<?php endif; ?>
</body></html>
