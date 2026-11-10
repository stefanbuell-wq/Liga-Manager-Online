<?php
/**
 * LegacyContentConverter
 *
 * Zentrale, verlustfreie Konvertierung fuer:
 *  1. SMF 1.x / 2.x Forenbeitraege (BBCode, Zitate, Smileys, HTML-Entities, Mojibake)
 *  2. FusionNews 3.6.1 Spielberichte/News (&br;, doppelt maskierte Entities, BBCode, Tabellen)
 *  3. LMO 4 Liga-Dateien (.l98, Windows-1252 -> UTF-8, Strafpunkte SP, Spielberichts-IDs)
 */

class LegacyContentConverter
{
    /**
     * Windows-1252 Mapping fuer numerische HTML-Entities im Bereich &#128; bis &#159;
     */
    private static array $win1252EntityMap = [
        128 => '€', 130 => '‚', 131 => 'ƒ', 132 => '„', 133 => '…',
        134 => '†', 135 => '‡', 136 => 'ˆ', 137 => '‰', 138 => 'Š',
        139 => '‹', 140 => 'Œ', 142 => 'Ž', 145 => '‘', 146 => '’',
        147 => '“', 148 => '”', 149 => '•', 150 => '–', 151 => '—',
        152 => '˜', 153 => '™', 154 => 'š', 155 => '›', 156 => 'œ',
        158 => 'ž', 159 => 'Ÿ',
    ];

    /**
     * Doppelte UTF-8-Kodierung (UTF-8 als Windows-1252/ISO-8859-1 interpretiert) gezielt reparieren
     */
    public static function fixMojibake(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $fixes = [
            "\xC3\x83\xC2\xA4" => 'ä',
            "\xC3\x83\xC2\xB6" => 'ö',
            "\xC3\x83\xC2\xBC" => 'ü',
            "\xC3\x83\xE2\x80\x9E" => 'Ä',
            "\xC3\x83\xC2\x84" => 'Ä',
            "\xC3\x83\xE2\x80\x93" => 'Ö',
            "\xC3\x83\xC2\x96" => 'Ö',
            "\xC3\x83\xC5\x93" => 'Ü',
            "\xC3\x83\xC2\x9C" => 'Ü',
            "\xC3\x83\xC5\xB8" => 'ß',
            "\xC3\x83\xC2\x9F" => 'ß',
            "\xC3\x83\xC2\xA9" => 'é',
            "\xC3\x83\xC2\xA8" => 'è',
            "\xC3\x83\xC2\xA1" => 'á',
            "\xC3\x83\xC2\xA0" => 'à',
            "\xC3\x83\xC2\xB3" => 'ó',
            "\xC3\x83\xC2\xBA" => 'ú',
            "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9C" => '–',
            "\xC3\xA2\xE2\x82\xAC\xE2\x80\x9D" => '—',
            "\xC3\xA2\xE2\x82\xAC\xC5\xBE" => '„',
            "\xC3\xA2\xE2\x82\xAC\xC5\x93" => '“',
            "\xC3\xA2\xE2\x82\xAC\xC2\x9D" => '”',
            "\xC3\xA2\xE2\x82\xAC\xCB\x9C" => '‘',
            "\xC3\xA2\xE2\x82\xAC\xE2\x84\xA2" => '’',
            "\xC3\xA2\xE2\x82\xAC\xC2\xA6" => '…',
            "\xC3\xA2\xE2\x82\xAC\xC2\xAC" => '€',
        ];

        return strtr($text, $fixes);
    }

    /**
     * Konvertiert Windows-1252 / ISO-8859-1 Rohbytes sicher nach UTF-8
     */
    public static function toUtf8(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        // Falls bereits valides UTF-8 mit Multibyte-Sequenzen vorliegt, nicht nochmal konvertieren
        if (mb_check_encoding($raw, 'UTF-8') && preg_match('/[\xC2-\xF4][\x80-\xBF]/', $raw)) {
            return self::fixMojibake($raw);
        }
        $utf8 = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        return self::fixMojibake($utf8);
    }

    /**
     * Dekodiert numerische HTML-Entities (inkl. Windows-1252 Bereich 128..159 und Emojis > 65535)
     * Optional koennen <, >, & geschuetzt bleiben ($preserveLtGtAmp = true fuer SMF vor BBCode-Parsing).
     */
    public static function decodeNumericAndNamedEntities(string $text, bool $preserveLtGtAmp = false): string
    {
        if ($text === '') {
            return '';
        }

        // Mehrfach verschachtelte &amp;#123; oder &amp;quot; aufloesen
        $text = preg_replace('/&amp;(#\d+|#x[0-9a-fA-F]+|[a-zA-Z0-9]+);/', '&$1;', $text);
        $text = preg_replace('/&amp;(#\d+|#x[0-9a-fA-F]+|[a-zA-Z0-9]+);/', '&$1;', $text);

        // Dezimale Entities &#1234;
        $text = preg_replace_callback('/&#(\d+);/', function ($m) use ($preserveLtGtAmp) {
            $code = (int)$m[1];
            if ($preserveLtGtAmp && ($code === 60 || $code === 62 || $code === 38)) {
                return $m[0];
            }
            if (isset(self::$win1252EntityMap[$code])) {
                return self::$win1252EntityMap[$code];
            }
            if ($code === 160) {
                return ' ';
            }
            if ($code >= 32 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF)) {
                $chr = @mb_chr($code, 'UTF-8');
                if ($chr !== false) {
                    return $chr;
                }
            }
            return $m[0];
        }, $text);

        // Hexadezimale Entities &#x1F600;
        $text = preg_replace_callback('/&#x([0-9a-fA-F]+);/i', function ($m) use ($preserveLtGtAmp) {
            $code = hexdec($m[1]);
            if ($preserveLtGtAmp && ($code === 60 || $code === 62 || $code === 38)) {
                return $m[0];
            }
            if (isset(self::$win1252EntityMap[$code])) {
                return self::$win1252EntityMap[$code];
            }
            if ($code === 160) {
                return ' ';
            }
            if ($code >= 32 && $code <= 0x10FFFF && ($code < 0xD800 || $code > 0xDFFF)) {
                $chr = @mb_chr($code, 'UTF-8');
                if ($chr !== false) {
                    return $chr;
                }
            }
            return $m[0];
        }, $text);

        if ($preserveLtGtAmp) {
            // Schuetze &lt;, &gt;, &amp; temporaer
            $placeholders = [
                '&lt;'  => "\x01LT\x01",
                '&LT;'  => "\x01LT\x01",
                '&#60;' => "\x01LT\x01",
                '&gt;'  => "\x01GT\x01",
                '&GT;'  => "\x01GT\x01",
                '&#62;' => "\x01GT\x01",
                '&amp;' => "\x01AMP\x01",
                '&AMP;' => "\x01AMP\x01",
                '&#38;' => "\x01AMP\x01",
            ];
            $text = strtr($text, $placeholders);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = strtr($text, [
                "\x01LT\x01"  => '&lt;',
                "\x01GT\x01"  => '&gt;',
                "\x01AMP\x01" => '&amp;',
            ]);
        } else {
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Non-breaking space (U+00A0) in normales Leerzeichen wandeln
        $text = str_replace("\xC2\xA0", ' ', $text);

        // Kombiniertes Diaeresis-Zeichen (U+0308, kam als &#776; 123x in SMF vor, z.B. a&#776; -> ä)
        $combiningMap = [
            "a\xCC\x88" => 'ä', "o\xCC\x88" => 'ö', "u\xCC\x88" => 'ü',
            "A\xCC\x88" => 'Ä', "O\xCC\x88" => 'Ö', "U\xCC\x88" => 'Ü',
        ];
        $text = strtr($text, $combiningMap);

        return $text;
    }

    /**
     * Bereinigt einen einfachen Text (z.B. Foren-Betreff, News-Titel, Autor) zu reinem UTF-8 Klartext
     */
    public static function cleanPlainTitle(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $text = self::fixMojibake($text);
        $text = str_replace([' &br;', '&br;', '<br />', '<br>', '<BR>'], ' ', $text);
        $text = self::decodeNumericAndNamedEntities($text, false);
        $text = strip_tags($text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Konvertiert einen SMF-Forenbeitrag (body) aus dem Rohdump in sauberes, semantisches HTML
     */
    public static function convertSmfPost(string $body): string
    {
        if ($body === '') {
            return '';
        }

        // 1. Mojibake reparieren
        $text = self::fixMojibake($body);

        // 2. [code]...[/code] Bloecke vorab sichern
        $codeBlocks = [];
        $text = preg_replace_callback('/\[code(?:=[^\]]*)?\](.*?)\[\/code\]/is', function ($m) use (&$codeBlocks) {
            $idx = count($codeBlocks);
            $inner = str_replace(['<br />', '<br>'], "\n", $m[1]);
            $inner = self::decodeNumericAndNamedEntities($inner, true);
            $codeBlocks[$idx] = '<pre class="forum-code"><code>' . trim($inner) . '</code></pre>';
            return "\x02CODEBLOCK_{$idx}\x02";
        }, $text);

        // 3. Entities dekodieren (&quot;, &#039;, &#8211;, &#128514;, &nbsp; -> UTF-8), aber &lt; / &gt; erhalten
        $text = self::decodeNumericAndNamedEntities($text, true);

        // 4. <br /> vereinheitlichen
        $text = preg_replace('/<br\s*\/?>/i', "<br>\n", $text);

        // 5. Verschachtelte [quote...]...[/quote] von innen nach aussen aufloesen (O(N) ohne Backtracking-Limit!)
        $quotePattern = '/\[quote([^\]]*)\]([^[]*+(?:\[(?!quote\b|\/quote\])[^[]*+)*+)\[\/quote\]/is';
        $maxIterations = 30;
        $iter = 0;
        while ($iter++ < $maxIterations && stripos($text, '[/quote]') !== false && preg_match($quotePattern, $text)) {
            $res = preg_replace_callback(
                $quotePattern,
                function ($m) {
                    return self::renderSmfQuote($m[1], $m[2]);
                },
                $text
            );
            if ($res === null || $res === $text) {
                break;
            }
            $text = $res;
        }

        // Falls unbalancierte [quote...] oder [/quote] uebrig geblieben sind:
        if (stripos($text, '[quote') !== false || stripos($text, '[/quote]') !== false) {
            $openCount = preg_match_all('/\[quote([^\]]*)\]/i', $text);
            $closeCount = preg_match_all('/\[\/quote\]/i', $text);
            if ($openCount > $closeCount) {
                $text .= str_repeat('[/quote]', $openCount - $closeCount);
                $iter = 0;
                while ($iter++ < 10 && preg_match($quotePattern, $text)) {
                    $res = preg_replace_callback(
                        $quotePattern,
                        fn($m) => self::renderSmfQuote($m[1], $m[2]),
                        $text
                    );
                    if ($res === null || $res === $text) {
                        break;
                    }
                    $text = $res;
                }
            }
            // Einzelne verwaiste [quote...] oder [/quote] entfernen
            $text = preg_replace('/\[\/?quote[^\]]*\]/i', '', $text) ?? $text;
        }

        // 6. Standard-BBCodes (mehrfach von innen nach aussen fuer Verschachtelungen)
        $text = self::convertBbcodeTags($text);

        // 7. SMF Smileys in Unicode-Emojis umwandeln (nur ausserhalb von HTML-Tags)
        $text = self::convertSmfSmileys($text);

        // 8. Code-Bloecke zuruecksetzen
        foreach ($codeBlocks as $idx => $html) {
            $text = str_replace("\x02CODEBLOCK_{$idx}\x02", $html, $text);
        }

        // 9. Ueberfluessige <br> direkt nach/vor Block-Elementen glaetten (ohne verschachteltes \s* vor UND nach <br>)
        $text = preg_replace('/(<\/blockquote>)\s*(?:<br>\s*)+/i', "$1\n", $text) ?? $text;
        $text = preg_replace('/(<div class="quote-body">)\s*(?:<br>\s*)+/i', '$1', $text) ?? $text;
        $text = preg_replace('/(?:<br>\s*)+(<\/div><\/blockquote>)/i', '$1', $text) ?? $text;
        $text = preg_replace('/(?:<br>\s*){3,}/i', "<br>\n<br>\n", $text) ?? $text;

        // Steuerzeichen entfernen
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        return trim($text);
    }

    /**
     * Erzeugt HTML fuer ein SMF-Zitat inkl. Autor und Datum
     */
    private static function renderSmfQuote(string $attrString, string $inner): string
    {
        $attrString = trim($attrString);
        $author = null;
        $dateStr = null;

        if ($attrString !== '') {
            // Format 1: [quote author=Name link=... date=123456789]
            if (preg_match('/author=([^;\]]+?)(?=\s+link=|\s+date=|$)/i', $attrString, $am)) {
                $author = trim($am[1], " '\"");
            } elseif (preg_match('/^=\s*(.+)$/i', $attrString, $am)) {
                // Format 2: [quote=Name]
                $author = trim($am[1], " '\"");
            }
            if (preg_match('/date=(\d+)/i', $attrString, $dm)) {
                $ts = (int)$dm[1];
                if ($ts > 0) {
                    $dateStr = date('d.m.Y, H:i', $ts) . ' Uhr';
                }
            }
        }

        // Fuehrende und abschliessende <br> im Zitat entfernen
        $inner = preg_replace('/^\s*(?:<br>\s*)+/i', '', $inner) ?? $inner;
        $inner = preg_replace('/(?:<br>\s*)+$/i', '', $inner) ?? $inner;
        $inner = trim($inner);

        $header = 'Zitat';
        if ($author !== null && $author !== '') {
            $safeAuthor = htmlspecialchars(self::decodeNumericAndNamedEntities($author, false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $header = "Zitat von <strong>{$safeAuthor}</strong>";
            if ($dateStr) {
                $header .= " <span class=\"quote-date\">({$dateStr})</span>";
            }
        } elseif ($dateStr) {
            $header = "Zitat <span class=\"quote-date\">({$dateStr})</span>";
        }

        return '<blockquote class="forum-quote"><div class="quote-header">' . $header . ':</div><div class="quote-body">' . $inner . '</div></blockquote>';
    }

    /**
     * Konvertiert BBCode-Tags in HTML (ohne unbekannte eckige Klammern wie [1:0] oder [HSV] zu loeschen!)
     */
    public static function convertBbcodeTags(string $text): string
    {
        // [img width=X height=Y]...[/img] und [img]...[/img]
        $text = preg_replace_callback(
            '/\[img([^\]]*)\]\s*([^\[\]]+?)\s*\[\/img\]/is',
            function ($m) {
                $attrs = $m[1];
                $src = trim(str_replace(['<br>', "\n", "\r"], '', $m[2]));
                $src = self::decodeNumericAndNamedEntities($src, false);
                if ($src === '') {
                    return '';
                }
                $style = 'max-width:100%;height:auto;vertical-align:middle;';
                $widthAttr = '';
                $heightAttr = '';
                if (preg_match('/width\s*=\s*(\d+)/i', $attrs, $wm)) {
                    $widthAttr = ' width="' . (int)$wm[1] . '"';
                }
                if (preg_match('/height\s*=\s*(\d+)/i', $attrs, $hm)) {
                    $heightAttr = ' height="' . (int)$hm[1] . '"';
                }
                return '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" alt=""' . $widthAttr . $heightAttr . ' style="' . $style . '" loading="lazy">';
            },
            $text
        );

        // [url=...]...[/url] und [iurl=...]...[/iurl] und [ftp=...]...[/ftp]
        $text = preg_replace_callback(
            '/\[(?:i?url|ftp)\s*=\s*([^\]]+)\](.*?)\[\/(?:i?url|ftp)\]/is',
            function ($m) {
                $href = trim(str_replace(['<br>', "\n", "\r"], '', $m[1]), " '\"");
                $href = self::decodeNumericAndNamedEntities($href, false);
                if ($href !== '' && !preg_match('#^(https?://|ftp://|mailto:|/|\#)#i', $href)) {
                    $href = 'http://' . $href;
                }
                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_blank" rel="noopener">' . $m[2] . '</a>';
            },
            $text
        );

        // [url]...[/url]
        $text = preg_replace_callback(
            '/\[(?:i?url|ftp)\]\s*([^\[\]]+?)\s*\[\/(?:i?url|ftp)\]/is',
            function ($m) {
                $rawUrl = trim(str_replace(['<br>', "\n", "\r"], '', $m[1]));
                $href = self::decodeNumericAndNamedEntities($rawUrl, false);
                if ($href !== '' && !preg_match('#^(https?://|ftp://|mailto:)#i', $href)) {
                    $href = 'http://' . $href;
                }
                $safeHref = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $safeLabel = htmlspecialchars(self::decodeNumericAndNamedEntities($rawUrl, false), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="' . $safeHref . '" target="_blank" rel="noopener">' . $safeLabel . '</a>';
            },
            $text
        );

        // [email=...]...[/email] und [email]...[/email]
        $text = preg_replace_callback(
            '/\[email\s*=\s*([^\]]+)\](.*?)\[\/email\]/is',
            function ($m) {
                $mail = trim($m[1], " '\"");
                return '<a href="mailto:' . htmlspecialchars($mail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $m[2] . '</a>';
            },
            $text
        );
        $text = preg_replace_callback(
            '/\[email\]\s*([^\[\]]+?)\s*\[\/email\]/is',
            function ($m) {
                $mail = trim($m[1]);
                $safe = htmlspecialchars($mail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return '<a href="mailto:' . $safe . '">' . $safe . '</a>';
            },
            $text
        );

        // Verschachtelte [color=...], [size=...], [font=...], [align=...], [glow=...], [shadow=...] (O(N) ohne Backtracking!)
        for ($pass = 0; $pass < 6; $pass++) {
            $prev = $text;

            if (stripos($text, '[/color]') !== false) {
                $text = preg_replace_callback(
                    '/\[color\s*=\s*([^\]]+)\]([^[]*+(?:\[(?!color\b|\/color\])[^[]*+)*+)\[\/color\]/is',
                    function ($m) {
                        $color = preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', trim($m[1], " '\""));
                        if ($color === '') {
                            return $m[2];
                        }
                        return '<span style="color:' . htmlspecialchars($color, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
                    },
                    $text
                ) ?? $text;
            }

            if (stripos($text, '[/size]') !== false) {
                $text = preg_replace_callback(
                    '/\[size\s*=\s*([^\]]+)\]([^[]*+(?:\[(?!size\b|\/size\])[^[]*+)*+)\[\/size\]/is',
                    function ($m) {
                        $cssSize = self::mapBbcodeSize(trim($m[1], " '\""));
                        return '<span style="font-size:' . $cssSize . ';">' . $m[2] . '</span>';
                    },
                    $text
                ) ?? $text;
            }

            if (stripos($text, '[/font]') !== false) {
                $text = preg_replace_callback(
                    '/\[font\s*=\s*([^\]]+)\]([^[]*+(?:\[(?!font\b|\/font\])[^[]*+)*+)\[\/font\]/is',
                    function ($m) {
                        $font = preg_replace('/[^a-zA-Z0-9\s,\'-]/', '', trim($m[1], " '\""));
                        if ($font === '') {
                            return $m[2];
                        }
                        return '<span style="font-family:' . htmlspecialchars($font, ENT_QUOTES, 'UTF-8') . ';">' . $m[2] . '</span>';
                    },
                    $text
                ) ?? $text;
            }

            if (stripos($text, '[/align]') !== false) {
                $text = preg_replace_callback(
                    '/\[align\s*=\s*([^\]]+)\]([^[]*+(?:\[(?!align\b|\/align\])[^[]*+)*+)\[\/align\]/is',
                    function ($m) {
                        $align = strtolower(trim($m[1], " '\""));
                        if (!in_array($align, ['left', 'center', 'right', 'justify'], true)) {
                            $align = 'left';
                        }
                        return '<div style="text-align:' . $align . ';">' . $m[2] . '</div>';
                    },
                    $text
                ) ?? $text;
            }

            if (stripos($text, '[/glow]') !== false || stripos($text, '[/shadow]') !== false) {
                $text = preg_replace_callback(
                    '/\[(?:glow|shadow)\s*=\s*([^,\]]+)[^\]]*\]([^[]*+(?:\[(?!(?:glow|shadow)\b|\/(?:glow|shadow)\])[^[]*+)*+)\[\/(?:glow|shadow)\]/is',
                    function ($m) {
                        $color = preg_replace('/[^#a-zA-Z0-9]/', '', trim($m[1]));
                        return $color ? '<span style="color:' . $color . ';">' . $m[2] . '</span>' : $m[2];
                    },
                    $text
                ) ?? $text;
            }

            if ($text === $prev) {
                break;
            }
        }

        // Listen: [list]...[/list], [li]...[/li], [*]
        if (stripos($text, '[/list]') !== false) {
            $text = preg_replace_callback('/\[list(?:=([^\]]+))?\]([^[]*+(?:\[(?!\/list\])[^[]*+)*+)\[\/list\]/is', function ($m) {
                $type = trim($m[1] ?? '');
                $inner = $m[2];
                $inner = preg_replace('/^\s*(?:<br>\s*)+/i', '', $inner) ?? $inner;
                if (strpos($inner, '[*]') !== false) {
                    $parts = preg_split('/\[\*\]/', $inner);
                    $items = '';
                    foreach ($parts as $idx => $part) {
                        $part = trim(preg_replace('/(?:<br>\s*)+$/i', '', $part) ?? $part);
                        if ($idx === 0 && $part === '') {
                            continue;
                        }
                        $items .= '<li>' . $part . '</li>';
                    }
                    $inner = $items;
                } else {
                    $inner = preg_replace('/\[li\]([^[]*+(?:\[(?!\/li\])[^[]*+)*+)\[\/li\]/is', '<li>$1</li>', $inner) ?? $inner;
                }
                $tag = ($type !== '') ? 'ol' : 'ul';
                return "<{$tag}>{$inner}</{$tag}>";
            }, $text) ?? $text;
        }

        // Einzelne [li]...[/li] ausserhalb von [list]
        if (stripos($text, '[/li]') !== false) {
            $text = preg_replace('/\[li\]([^[]*+(?:\[(?!\/li\])[^[]*+)*+)\[\/li\]/is', '<li>$1</li>', $text) ?? $text;
        }

        // Einfache symmetrische Tags: [b], [i], [u], [s], [sub], [sup], [tt], [center], [left], [right], [move]
        $simplePairs = [
            'b'      => ['<strong>', '</strong>'],
            'i'      => ['<em>', '</em>'],
            'u'      => ['<u>', '</u>'],
            's'      => ['<s>', '</s>'],
            'strike' => ['<s>', '</s>'],
            'sub'    => ['<sub>', '</sub>'],
            'sup'    => ['<sup>', '</sup>'],
            'tt'     => ['<code>', '</code>'],
            'center' => ['<div style="text-align:center;">', '</div>'],
            'left'   => ['<div style="text-align:left;">', '</div>'],
            'right'  => ['<div style="text-align:right;">', '</div>'],
            'move'   => ['<span>', '</span>'],
            'table'  => ['<table>', '</table>'],
            'tr'     => ['<tr>', '</tr>'],
            'td'     => ['<td>', '</td>'],
            'th'     => ['<th>', '</th>'],
        ];

        foreach ($simplePairs as $bb => [$openHtml, $closeHtml]) {
            if (stripos($text, "[/$bb]") === false) {
                continue;
            }
            $pat = '/\[' . $bb . '\]([^[]*+(?:\[(?!' . $bb . '\]|\/' . $bb . '\])[^[]*+)*+)\[\/' . $bb . '\]/is';
            for ($p = 0; $p < 4; $p++) {
                $next = preg_replace($pat, $openHtml . '$1' . $closeHtml, $text);
                if ($next === null || $next === $text) {
                    break;
                }
                $text = $next;
            }
        }

        // [hr] und [br]
        $text = preg_replace('/\[hr\]/i', '<hr>', $text);
        $text = preg_replace('/\[br\]/i', '<br>', $text);

        // Verwaiste bekannte BBCode-Tags (z.B. nicht geschlossenes [size=2] oder [/color]) bereinigen,
        // ABER niemals unbekannte Klammern wie [1:0] oder [HSV] antasten!
        $knownOrphanPattern = '/\[\/?(?:b|i|u|s|strike|sub|sup|tt|center|left|right|move|color|size|font|align|glow|shadow|url|iurl|ftp|email|img|flash|list|li|table|tr|td|th|quote|code)(?:=[^\]]*|\s+[^\]]*)?\]/i';
        $text = preg_replace($knownOrphanPattern, '', $text) ?? $text;

        return $text;
    }

    /**
     * Mappt SMF / FusionNews [size=...] Werte auf lesbare CSS-Groessen
     */
    private static function mapBbcodeSize(string $sizeVal): string
    {
        $sizeVal = strtolower(trim($sizeVal));
        $map = [
            '1' => '0.8em',
            '2' => '0.95em',
            '3' => '1.1em',
            '4' => '1.25em',
            '5' => '1.45em',
            '6' => '1.7em',
            '7' => '2.0em',
        ];
        if (isset($map[$sizeVal])) {
            return $map[$sizeVal];
        }
        if (preg_match('/^(\d+(?:\.\d+)?)(pt|px|em|%)$/', $sizeVal, $m)) {
            $num = (float)$m[1];
            $unit = $m[2];
            if ($unit === 'pt' && ($num < 6 || $num > 36)) {
                return '1em';
            }
            if ($unit === 'px' && ($num < 8 || $num > 48)) {
                return '1em';
            }
            return $sizeVal;
        }
        return '1em';
    }

    /**
     * Ersetzt SMF-Smileys ausserhalb von HTML-Tags durch Unicode-Emojis
     */
    public static function convertSmfSmileys(string $text): string
    {
        // Reihenfolge: laengere / spezifischere Codes zuerst
        $smileyMap = [
            '>:('  => '😠',
            '>:-(' => '😠',
            '>:D'  => '😈',
            '>:-D' => '😈',
            ":'("  => '😢',
            ':-['  => '😳',
            ':-X'  => '🤐',
            ':-x'  => '🤐',
            ':-\\' => '😕',
            ':-/'  => '😕',
            ':-*'  => '😘',
            '::)'  => '🙄',
            '^-^'  => '☺️',
            ':-)'  => '😊',
            ':)'   => '😊',
            ';-)'  => '😉',
            ';)'   => '😉',
            ':-D'  => '😄',
            ':D'   => '😄',
            ';D'   => '😁',
            ':-('  => '🙁',
            ':('   => '🙁',
            ':-o'  => '😮',
            ':-O'  => '😮',
            ':o'   => '😮',
            ':O'   => '😮',
            '8-)'  => '😎',
            '8)'   => '😎',
            ':-P'  => '😛',
            ':-p'  => '😛',
            ':P'   => '😛',
            ':p'   => '😛',
        ];

        // Text an HTML-Tags splitten, damit Attribute/URLs in <a href="..."> nie verfaelscht werden
        $parts = preg_split('/(<[^>]+>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<') {
                continue;
            }
            // Keine Ersetzung innerhalb von http:// oder https:// im Fliesstext
            foreach ($smileyMap as $code => $emoji) {
                $quoted = preg_quote($code, '/');
                // Smiley nur ersetzen, wenn davor Start/Leerzeichen/Satzzeichen/Zeilenumbruch steht
                // und danach Ende/Leerzeichen/Satzzeichen steht (verhindert Treffer in URLs oder Woertern wie "18) ")
                if ($code === '8)' || $code === ':o' || $code === ':O' || $code === ':P' || $code === ':p' || $code === ':D') {
                    $part = preg_replace('/(?<=^|[\s\(\[\>])' . $quoted . '(?=$|[\s\.\,\!\?\)\]\<])/', $emoji, $part);
                } else {
                    $part = preg_replace('/(?<=^|[\s\(\[\>])' . $quoted . '(?=$|[\s\.\,\!\?\)\]\<])/', $emoji, $part);
                }
            }
            $parts[$i] = $part;
        }

        return implode('', $parts);
    }

    /**
     * Konvertiert einen FusionNews-Text (short_content oder content) aus Windows-1252 Rohdaten in sauberes UTF-8 HTML
     */
    public static function convertFusionNewsContent(string $rawText): string
    {
        if ($rawText === '') {
            return '';
        }

        // 1. ZUERST von Windows-1252 nach UTF-8 konvertieren!
        $text = self::toUtf8($rawText);

        // 2. FusionNews Zeilenumbrueche (&br;) in <br> wandeln
        $text = str_replace([' &br;', '&br;'], '<br>', $text);

        // 3. HTML-Entities vollstaendig dekodieren (da FusionNews alle HTML-Tags als &lt;b&gt; speichert
        //    und Sonderzeichen als &amp;#8222; doppelt maskiert hat)
        $text = self::decodeNumericAndNamedEntities($text, false);

        // Schutz gegen evtl. <?php Fragmente (wie in fullnews.php Zeile 46)
        $text = str_replace('<?', '&lt;?', $text);

        // 4. BBCodes ([img], [color], [size], [align], [b], [url] etc.) in HTML wandeln
        $text = self::convertBbcodeTags($text);

        // 5. Legacy <font ...> Tags in modernes <span style="..."> umwandeln
        $text = preg_replace_callback('/<font\b([^>]*)>/i', function ($m) {
            $attrs = $m[1];
            $styles = [];
            if (preg_match('/\bcolor\s*=\s*([\'"]?)([^\'">\s]+)\1/i', $attrs, $cm)) {
                $col = preg_replace('/[^#a-zA-Z0-9(),.%-]/', '', $cm[2]);
                if ($col !== '') {
                    $styles[] = 'color:' . $col;
                }
            }
            if (preg_match('/\bsize\s*=\s*([\'"]?)([^\'">\s]+)\1/i', $attrs, $sm)) {
                $sz = trim($sm[2]);
                if ($sz === '+1') $styles[] = 'font-size:1.15em';
                elseif ($sz === '+2') $styles[] = 'font-size:1.35em';
                elseif ($sz === '+3') $styles[] = 'font-size:1.6em';
                elseif ($sz === '-1') $styles[] = 'font-size:0.85em';
                elseif ($sz === '-2') $styles[] = 'font-size:0.75em';
                else $styles[] = 'font-size:' . self::mapBbcodeSize($sz);
            }
            if (preg_match('/\bface\s*=\s*([\'"]?)([^\'">]+)\1/i', $attrs, $fm)) {
                $face = preg_replace('/[^a-zA-Z0-9\s,\'-]/', '', $fm[2]);
                if ($face !== '') {
                    $styles[] = 'font-family:' . $face;
                }
            }
            return $styles ? '<span style="' . implode(';', $styles) . ';">' : '<span>';
        }, $text);
        $text = str_ireplace('</font>', '</span>', $text);

        // 6. Gefaehrliche Skript/Objekt-Tags und Inline-Event-Handler entfernen
        $text = preg_replace('#</?(script|style|iframe|object|embed|param)[^>]*>#i', '', $text);
        $text = preg_replace('/\s+on\w+\s*=\s*([\'"]).*?\1/i', '', $text);
        $text = preg_replace('/href\s*=\s*([\'"])\s*javascript:[^\'"]*\1/i', 'href="#"', $text);

        // 7. Mehrfache <br> auf max. 2 begrenzen und Steuerzeichen bereinigen
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = preg_replace('/(<br\s*\/?>\s*){3,}/i', "<br>\n<br>\n", $text);

        return trim($text);
    }

    /**
     * Liest das Autoren-Mapping aus hafo.de/news/users.php (findwriter)
     */
    public static function loadFusionUsersMap(string $usersPhpPath): array
    {
        $map = [];
        if (!file_exists($usersPhpPath)) {
            return $map;
        }
        $lines = file($usersPhpPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return $map;
        }
        array_shift($lines); // <?php die(...)
        foreach ($lines as $line) {
            $utf8Line = self::toUtf8($line);
            $parts = explode('|<|', $utf8Line);
            if (count($parts) >= 2) {
                $user = trim($parts[0]);
                $nick = trim($parts[1]);
                if ($user !== '' && $nick !== '') {
                    $map[$user] = $nick;
                }
            }
        }
        return $map;
    }
}
