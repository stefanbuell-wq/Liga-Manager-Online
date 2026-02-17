<?php
/**
 * Bereinigt Sonderzeichen und HTML-Entities aus allen News-Eintraegen
 */

require_once __DIR__ . '/lib/LmoDatabase.php';

echo "=== News Sonderzeichen-Bereinigung ===" . PHP_EOL . PHP_EOL;

$pdo = LmoDatabase::getInstance();

// Alle News laden
$news = $pdo->query("SELECT id, title, short_content, content FROM news")->fetchAll();
echo "Gefunden: " . count($news) . " News-Eintraege" . PHP_EOL . PHP_EOL;

/**
 * Bereinigt Text von problematischen Sonderzeichen und HTML-Entities
 */
function cleanText($text) {
    if (empty($text)) return $text;

    // HTML-Entities dekodieren (mehrfach, da manchmal doppelt kodiert)
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Spezifische Legacy-Entities
    $replacements = [
        '&quot;' => '"',
        '&amp;' => '&',
        '&lt;' => '<',
        '&gt;' => '>',
        '&nbsp;' => ' ',
        '&#33;' => '!',
        '&#34;' => '"',
        '&#39;' => "'",
        '&#40;' => '(',
        '&#41;' => ')',
        '&#44;' => ',',
        '&#45;' => '-',
        '&#46;' => '.',
        '&#47;' => '/',
        '&#58;' => ':',
        '&#59;' => ';',
        '&#60;' => '<',
        '&#62;' => '>',
        '&#91;' => '[',
        '&#93;' => ']',
        '&#123;' => '{',
        '&#125;' => '}',
        '&br;' => "\n",
        '<br />' => "\n",
        '<br>' => "\n",
        '<BR>' => "\n",
        '<p>' => "\n",
        '</p>' => "\n",
        '<P>' => "\n",
        '</P>' => "\n",
    ];

    $text = str_replace(array_keys($replacements), array_values($replacements), $text);

    // Verbleibende numerische HTML-Entities konvertieren (&#123; Format)
    $text = preg_replace_callback('/&#(\d+);/', function($matches) {
        $code = intval($matches[1]);
        if ($code > 0 && $code < 65536) {
            return mb_chr($code, 'UTF-8');
        }
        return $matches[0];
    }, $text);

    // Hex-Entities (&#x1F4A9; Format)
    $text = preg_replace_callback('/&#x([0-9a-fA-F]+);/', function($matches) {
        $code = hexdec($matches[1]);
        if ($code > 0 && $code < 65536) {
            return mb_chr($code, 'UTF-8');
        }
        return $matches[0];
    }, $text);

    // Kaputte UTF-8 Sequenzen reparieren (typisch fuer ISO-8859-1 zu UTF-8 Fehler)
    // Verwende Hex-Escapes um Encoding-Probleme im Quellcode zu vermeiden
    $encodingFixes = [
        // Deutsche Umlaute (doppelt kodiert)
        "\xC3\x83\xC2\xA4" => "\xC3\xA4", // ae
        "\xC3\x83\xC2\xB6" => "\xC3\xB6", // oe
        "\xC3\x83\xC2\xBC" => "\xC3\xBC", // ue
        "\xC3\x83\xE2\x80\x9E" => "\xC3\x84", // Ae
        "\xC3\x83\xE2\x80\x93" => "\xC3\x96", // Oe
        "\xC3\x83\xC5\x93" => "\xC3\x9C", // Ue
        "\xC3\x83\xC5\xB8" => "\xC3\x9F", // ss
        // Einfach doppelt kodiert
        "\xC3\x83\xC2\xA9" => "\xC3\xA9", // e acute
        "\xC3\x83\xC2\xA8" => "\xC3\xA8", // e grave
        // Typografie-Zeichen
        "\xE2\x80\x93" => "-",  // En-dash zu Minus
        "\xE2\x80\x94" => "-",  // Em-dash zu Minus
        "\xE2\x80\x98" => "'",  // Left single quote
        "\xE2\x80\x99" => "'",  // Right single quote
        "\xE2\x80\x9C" => '"',  // Left double quote
        "\xE2\x80\x9D" => '"',  // Right double quote
        "\xE2\x80\xA6" => "...", // Ellipsis
        "\xE2\x80\xA2" => "-",  // Bullet zu Minus
        "\xC2\xA0" => " ",      // Non-breaking space
        "\xC2\xAB" => '"',      // Guillemet left
        "\xC2\xBB" => '"',      // Guillemet right
        "\xC2\xB0" => " Grad ", // Grad-Zeichen
    ];

    $text = str_replace(array_keys($encodingFixes), array_values($encodingFixes), $text);

    // Steuerzeichen entfernen (ausser Newline, Tab)
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

    // Mehrfache Leerzeichen zu einem
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Mehrfache Zeilenumbruche reduzieren
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    // Leerzeichen am Zeilenende entfernen
    $text = preg_replace('/[ \t]+$/m', '', $text);

    return trim($text);
}

// Prepared Statement fuer Updates
$stmt = $pdo->prepare("UPDATE news SET title = ?, short_content = ?, content = ? WHERE id = ?");

$updated = 0;
$unchanged = 0;

foreach ($news as $n) {
    $newTitle = cleanText($n['title']);
    $newShort = cleanText($n['short_content']);
    $newContent = cleanText($n['content']);

    // Nur updaten wenn sich etwas geaendert hat
    if ($newTitle !== $n['title'] || $newShort !== $n['short_content'] || $newContent !== $n['content']) {
        $stmt->execute([$newTitle, $newShort, $newContent, $n['id']]);
        $updated++;

        if ($updated <= 10) {
            // Erste 10 Aenderungen anzeigen
            echo "News #{$n['id']}: " . substr($newTitle, 0, 50) . "..." . PHP_EOL;
        }
    } else {
        $unchanged++;
    }

    if (($updated + $unchanged) % 500 === 0) {
        echo "  Verarbeitet: " . ($updated + $unchanged) . " ($updated geaendert)..." . PHP_EOL;
    }
}

echo PHP_EOL . "=== Ergebnis ===" . PHP_EOL;
echo "Bereinigt: $updated News" . PHP_EOL;
echo "Unveraendert: $unchanged News" . PHP_EOL;

// Beispiele anzeigen
echo PHP_EOL . "Beispiel-Berichte nach Bereinigung:" . PHP_EOL;
$examples = $pdo->query("
    SELECT id, title, short_content
    FROM news
    ORDER BY timestamp DESC
    LIMIT 3
")->fetchAll();

foreach ($examples as $ex) {
    echo PHP_EOL . "--- News #{$ex['id']} ---" . PHP_EOL;
    echo "Titel: " . $ex['title'] . PHP_EOL;
    echo "Vorschau: " . substr($ex['short_content'], 0, 200) . "..." . PHP_EOL;
}

echo PHP_EOL . "=== Bereinigung abgeschlossen! ===" . PHP_EOL;
