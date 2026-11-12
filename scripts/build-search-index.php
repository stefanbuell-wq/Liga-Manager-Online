<?php
/**
 * Legt den Volltextindex fuer Forenbeitraege an (fuer api/search.php) und befuellt ihn.
 * Neue/geaenderte/geloeschte Beitraege halten Trigger aktuell.
 * Gefiltert auf oeffentliche Bereiche wird erst bei der Suche (view_permission = 'public').
 *
 * Aufruf: php scripts/build-search-index.php   (einmalig; erneuter Aufruf baut neu auf)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../lib/LmoDatabase.php';
$pdo = LmoDatabase::getInstance();

$pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS forum_fts USING fts5(content, content='forum_posts', content_rowid='id')");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS forum_fts_ai AFTER INSERT ON forum_posts BEGIN
    INSERT INTO forum_fts(rowid, content) VALUES (new.id, new.content); END");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS forum_fts_ad AFTER DELETE ON forum_posts BEGIN
    INSERT INTO forum_fts(forum_fts, rowid, content) VALUES ('delete', old.id, old.content); END");
$pdo->exec("CREATE TRIGGER IF NOT EXISTS forum_fts_au AFTER UPDATE OF content ON forum_posts BEGIN
    INSERT INTO forum_fts(forum_fts, rowid, content) VALUES ('delete', old.id, old.content);
    INSERT INTO forum_fts(rowid, content) VALUES (new.id, new.content); END");

$t = microtime(true);
$pdo->exec("INSERT INTO forum_fts(forum_fts) VALUES ('rebuild')");
$n = $pdo->query("SELECT COUNT(*) FROM forum_posts")->fetchColumn();
printf("forum_fts aufgebaut: %d Beitraege in %.1f s\n", $n, microtime(true) - $t);
