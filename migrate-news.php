<?php
/**
 * Migration: FusionNews -> SQLite
 *
 * Liest die alten News-Dateien aus dem Git-Repository und importiert sie in die SQLite-Datenbank.
 */

require_once __DIR__ . '/lib/LmoDatabase.php';

echo "=== News Migration (FusionNews -> SQLite) ===\n\n";

$pdo = LmoDatabase::getInstance();

// News-Tabelle leeren? (Optional)
$existingCount = $pdo->query("SELECT COUNT(*) FROM news")->fetchColumn();
if ($existingCount > 0) {
    echo "Es existieren bereits $existingCount News in der Datenbank.\n";
    echo "Lösche bestehende News? (j/n): ";
    $answer = trim(fgets(STDIN));
    if (strtolower($answer) === 'j') {
        $pdo->exec("DELETE FROM news");
        echo "Bestehende News gelöscht.\n\n";
    } else {
        echo "Migration abgebrochen.\n";
        exit;
    }
}

// News-Dateien finden
// Zuerst versuchen wir das Dateisystem, dann Git
$newsDir = __DIR__ . '/news/news';
$newsFiles = [];

if (is_dir($newsDir)) {
    echo "Lese News aus Dateisystem: $newsDir\n";
    $files = glob($newsDir . '/news.*.php');
    foreach ($files as $file) {
        if (preg_match('/news\.(\d+)\.php$/', $file, $m)) {
            $newsFiles[$m[1]] = file_get_contents($file);
        }
    }
} else {
    echo "Verzeichnis nicht gefunden, versuche Git...\n";

    // Git-Commit finden der die News-Dateien hat
    $gitCommit = trim(shell_exec('git log --oneline -1 --all -- "news/news/news.1.php" 2>&1'));
    if (!$gitCommit) {
        echo "FEHLER: Keine News-Dateien gefunden (weder lokal noch in Git)\n";
        exit(1);
    }

    $commitHash = explode(' ', $gitCommit)[0];
    echo "Verwende Git-Commit: $commitHash\n";

    // Liste aller News-Dateien aus Git
    $gitFiles = shell_exec("git ls-tree -r --name-only $commitHash -- news/news/ 2>&1");
    $fileList = explode("\n", trim($gitFiles));

    foreach ($fileList as $file) {
        if (preg_match('/news\.(\d+)\.php$/', $file, $m)) {
            $content = shell_exec("git show $commitHash:$file 2>&1");
            if ($content && strpos($content, 'fatal:') === false) {
                $newsFiles[$m[1]] = $content;
            }
        }
    }
}

echo "Gefunden: " . count($newsFiles) . " News-Dateien\n\n";

if (count($newsFiles) === 0) {
    echo "Keine News-Dateien gefunden!\n";
    exit(1);
}

// News-Format parsen:
// <?php die('Access denied'); ?>
// short_content|<|content|<|author|<|title|<|email|<||<|timestamp|<|?|<|

$imported = 0;
$errors = 0;

$stmt = $pdo->prepare("
    INSERT OR REPLACE INTO news (id, title, short_content, content, author, email, timestamp, match_date)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

foreach ($newsFiles as $id => $content) {
    // PHP-Header entfernen
    $content = preg_replace('/^<\?php.*?\?>\s*/s', '', $content);

    // Nach Delimiter splitten
    $parts = explode('|<|', $content);

    if (count($parts) < 7) {
        echo "  Warnung: News #$id hat ungültiges Format (" . count($parts) . " Teile)\n";
        $errors++;
        continue;
    }

    $shortContent = trim($parts[0] ?? '');
    $fullContent = trim($parts[1] ?? '');
    $author = trim($parts[2] ?? '');
    $title = trim($parts[3] ?? '');
    $email = trim(str_replace('=', '', $parts[4] ?? ''));
    // $parts[5] ist leer
    $timestamp = intval($parts[6] ?? 0);

    // HTML-Entities dekodieren und Legacy-Formatierung konvertieren
    $shortContent = convertLegacyContent($shortContent);
    $fullContent = convertLegacyContent($fullContent);
    $title = html_entity_decode($title, ENT_QUOTES, 'UTF-8');
    $author = html_entity_decode($author, ENT_QUOTES, 'UTF-8');

    // Encoding konvertieren (ISO-8859-1 -> UTF-8)
    if (!mb_check_encoding($title, 'UTF-8')) {
        $title = mb_convert_encoding($title, 'UTF-8', 'ISO-8859-1');
    }
    if (!mb_check_encoding($shortContent, 'UTF-8')) {
        $shortContent = mb_convert_encoding($shortContent, 'UTF-8', 'ISO-8859-1');
    }
    if (!mb_check_encoding($fullContent, 'UTF-8')) {
        $fullContent = mb_convert_encoding($fullContent, 'UTF-8', 'ISO-8859-1');
    }
    if (!mb_check_encoding($author, 'UTF-8')) {
        $author = mb_convert_encoding($author, 'UTF-8', 'ISO-8859-1');
    }

    // Datum aus Timestamp
    $matchDate = $timestamp > 0 ? date('d.m.Y', $timestamp) : null;

    try {
        $stmt->execute([
            $id,
            $title ?: "News #$id",
            $shortContent,
            $fullContent,
            $author ?: 'Redaktion',
            $email,
            $timestamp,
            $matchDate
        ]);
        $imported++;

        if ($imported % 100 === 0) {
            echo "  $imported News importiert...\n";
        }
    } catch (Exception $e) {
        echo "  Fehler bei News #$id: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== Ergebnis ===\n";
echo "Importiert: $imported\n";
echo "Fehler: $errors\n";

// Statistiken
$count = $pdo->query("SELECT COUNT(*) FROM news")->fetchColumn();
echo "\nNews in Datenbank: $count\n";

// Neueste News anzeigen
echo "\nNeueste 5 News:\n";
$latest = $pdo->query("SELECT id, title, timestamp FROM news ORDER BY timestamp DESC LIMIT 5")->fetchAll();
foreach ($latest as $n) {
    $date = $n['timestamp'] > 0 ? date('d.m.Y', $n['timestamp']) : '?';
    echo "  [$date] " . substr($n['title'], 0, 60) . "...\n";
}

echo "\n=== Migration abgeschlossen! ===\n";

/**
 * Konvertiert Legacy-FusionNews Formatierung zu HTML
 */
function convertLegacyContent($text)
{
    // HTML-Entities dekodieren
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

    // Legacy BBCode/Tags konvertieren
    $replacements = [
        '&br;' => "\n",
        '<br />' => "\n",
        '<br>' => "\n",
        '&quot;' => '"',
        '&amp;' => '&',
        '&#33;' => '!',
        '&#39;' => "'",
        '<li>' => "\n• ",
        '</li>' => '',
    ];

    $text = str_replace(array_keys($replacements), array_values($replacements), $text);

    // Mehrfache Leerzeilen reduzieren
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}
