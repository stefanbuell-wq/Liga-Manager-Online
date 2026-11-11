<?php
/**
 * Aufbereitung von News-/Spielbericht-Inhalten fuer die Anzeige.
 *
 * - Altbilder der frueheren hafo.de-Seite (../images, www.hafo.de/news/uploads, ...)
 *   werden auf den lokalen Ordner legacy/ umgeschrieben (Bilder dort 1:1 abgelegt).
 * - Wappen von stillgelegten Hosts (v-manager, dfd-systems) werden auf
 *   vereinswappen.de umgeschrieben und ueber api/img-proxy.php geladen (CSP img-src 'self').
 * - Vorhandene Wappen, Banner und Autorzeilen bleiben unveraendert. Nur wenn ein
 *   Spielbericht gar kein Bild im Kopf hat, werden lokale Vereinswappen ergaenzt.
 */
class NewsContent
{
    /** Hosts, die der Bild-Proxy ausliefern darf (muss zu api/img-proxy.php passen) */
    public const PROXY_HOSTS = [
        'www.vereinswappen.de', 'vereinswappen.de',
        'www.stpaulicoffee.de', 'stpaulicoffee.de',
        'www.lotto-hh.de', 'lotto-hh.de',
    ];

    /** Stillgelegte Wappen-Hosts mit identischer Pfadstruktur wie vereinswappen.de */
    private const DEAD_WAPPEN_HOSTS = [
        'static.v-manager.de', 'vereinsmanager.dfd-systems.de', 'www.sport-finden.de', 'vereinswappen.de',
    ];

    private const IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private static ?array $teamLogoIndex = null;

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Komplett-Aufbereitung eines Artikels fuer die Anzeige.
     * $teams: optional [Heim, Gast] aus der Spielverknuepfung (fuer fehlende Wappen)
     */
    public static function prepare(?string $html, ?array $teams = null): string
    {
        if (!$html) return '';
        $html = self::rewriteImages($html);
        $html = self::addMissingLogos($html, $teams);
        return self::sanitize($html);
    }

    /** Erlaubte Tags fuer Artikel (alles andere wird entfernt, Inhalt bleibt) */
    private const ALLOWED_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'br', 'p', 'div', 'span', 'center',
        'font', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'hr', 'img', 'small', 'big',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'pre', 'code', 'cite', 'q',
    ];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button',
        'textarea', 'select', 'link', 'meta', 'base', 'svg', 'math', 'frame', 'frameset', 'applet', 'template', 'noscript'];
    private const ALLOWED_ATTRS = ['href', 'src', 'alt', 'title', 'style', 'class', 'align', 'width', 'height',
        'border', 'colspan', 'rowspan', 'cellpadding', 'cellspacing', 'valign', 'color', 'size', 'face', 'target', 'rel', 'loading'];

    /**
     * Allowlist-Filter gegen Skript-Einschleusung (Redaktions-HTML wird per innerHTML angezeigt):
     * keine Skripte/Event-Handler, Links nur http(s)/mailto/relativ, Bilder nur lokal (nach rewriteImages).
     */
    public static function sanitize(string $html): string
    {
        if (trim($html) === '') return '';
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('__root');
        if (!$root) return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        self::cleanNode($root);
        $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }

    private static function cleanNode(DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $child = $node->childNodes->item($i);
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }
                self::cleanNode($child);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    // Unbekanntes Tag aufloesen, Inhalt behalten
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $val = trim($attr->value);
                    $ok = in_array($name, self::ALLOWED_ATTRS, true);
                    if ($ok && $name === 'href') {
                        $ok = (bool)preg_match('#^(https?://|mailto:|\?|/|\#|[a-z0-9._\-]+(/|\.|$))#i', $val)
                            && !preg_match('#^\s*(javascript|data|vbscript):#i', $val);
                    }
                    if ($ok && $name === 'src') {
                        $ok = (bool)preg_match('#^(img/|legacy/|icons/|api/img-proxy\.php\?)#', $val);
                    }
                    if ($ok && $name === 'style') {
                        $ok = !preg_match('#expression|javascript:|url\s*\(|behavior|@import#i', $val);
                    }
                    if (!$ok) $child->removeAttribute($attr->name);
                }
                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }
                if ($tag === 'img' && !$child->hasAttribute('src')) {
                    $node->removeChild($child);
                }
            } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    /** Bildquelle -> anzeigbare Quelle (oder null = Bild entfernen) */
    public static function mapImageSrc(string $src): ?string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES, 'UTF-8'));
        if ($src === '' || stripos($src, 'data:') === 0) return null;

        // Bereits lokale Pfade der neuen Seite
        if (preg_match('#^(?:\.\./)?(img/|legacy/|icons/|api/img-proxy\.php)#i', $src)) {
            return preg_replace('#^\.\./(?=img/|legacy/|icons/|api/)#i', '', $src);
        }

        // Altpfade der frueheren hafo.de-Seite -> legacy/<pfad>
        $legacy = null;
        if (preg_match('#^https?://(?:www\.)?hafo\.de/(.+)$#i', $src, $m)) {
            $legacy = $m[1];
        } elseif (strpos($src, '../') === 0) {
            $legacy = substr($src, 3);
        } elseif (preg_match('#^images/#i', $src)) {
            $legacy = 'news/' . $src;          // FusionNews lag unter /news/
        }
        if ($legacy !== null) {
            $legacy = rawurldecode(strtok($legacy, '?#'));
            if (strpos($legacy, '..') !== false) return null;
            $ext = strtolower(pathinfo($legacy, PATHINFO_EXTENSION));
            if (!in_array($ext, self::IMG_EXT, true)) return null;
            $dir = self::root() . '/legacy';
            // Ist der Bilderordner hochgeladen, fehlende Dateien gleich weglassen
            if (is_dir($dir) && !is_file($dir . '/' . $legacy)) return null;
            return 'legacy/' . implode('/', array_map('rawurlencode', explode('/', $legacy)));
        }

        if (!preg_match('#^https?://#i', $src)) return null;
        $host = strtolower((string)parse_url($src, PHP_URL_HOST));
        $path = (string)parse_url($src, PHP_URL_PATH);

        // Tote Wappen-Hosts -> vereinswappen.de (gleiche Vereinsnummern)
        if (in_array($host, self::DEAD_WAPPEN_HOSTS, true) && preg_match('#/wappen/verein/\d+\.(gif|png|jpg)$#i', $path)) {
            $src = 'http://www.vereinswappen.de' . $path;
            $host = 'www.vereinswappen.de';
        }
        if (in_array($host, self::PROXY_HOSTS, true)) {
            return 'api/img-proxy.php?u=' . rawurlencode($src);
        }
        return null; // andere Fremdquellen sind per CSP gesperrt
    }

    public static function rewriteImages(string $html): string
    {
        return preg_replace_callback('#<img\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)>#is', function ($m) {
            $new = self::mapImageSrc($m[3]);
            if ($new === null) return '';
            $attrs = $m[1] . $m[4];
            // Altlasten: onerror-Handler und feste Breiten entfernen, Bild responsiv
            $attrs = preg_replace('#\s(?:onerror|onload)\s*=\s*(["\']).*?\1#is', '', $attrs);
            $attrs = preg_replace('#\sloading\s*=\s*(["\']).*?\1#is', '', $attrs);
            return '<img' . rtrim($attrs) . ' src="' . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . '" loading="lazy">';
        }, $html) ?? $html;
    }

    /** Kopfzeile "Heim – Gast 2:1" aus dem Inhalt lesen */
    public static function teamsFromScoreLine(string $html): ?array
    {
        if (preg_match('#<b>\s*([^<>\r\n]{3,60}?)\s+[–-]\s+([^<>\r\n]{3,60}?)\s+\d+\s*:\s*\d+#u', $html, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return null;
    }

    /** Lokales Wappen (img/teams/*.gif) zu einem Vereinsnamen, sonst null */
    public static function teamLogo(?string $name): ?string
    {
        if (!$name) return null;
        if (self::$teamLogoIndex === null) {
            self::$teamLogoIndex = [];
            foreach (glob(self::root() . '/img/teams/*.{gif,png,jpg}', GLOB_BRACE) ?: [] as $f) {
                $base = pathinfo($f, PATHINFO_FILENAME);
                if (strtolower($base) === 'blank') continue;
                self::$teamLogoIndex[strtolower($base)] = basename($f);
            }
        }
        // Gleiche Regel wie im Frontend: alles ausser a-z0-9 entfernen
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $name));
        if ($key !== '' && isset(self::$teamLogoIndex[$key])) return 'img/teams/' . self::$teamLogoIndex[$key];
        // Variante mit umschriebenen Umlauten
        $alt = strtolower(preg_replace('/[^a-z0-9]/i', '', strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss'])));
        if ($alt !== '' && isset(self::$teamLogoIndex[$alt])) return 'img/teams/' . self::$teamLogoIndex[$alt];
        return null;
    }

    /** Wappen nur ergaenzen, wenn der Bericht im Kopf gar keine Bilder hat */
    public static function addMissingLogos(string $html, ?array $teams = null): string
    {
        $teams = $teams ?: self::teamsFromScoreLine($html);
        if (!$teams || count($teams) !== 2) return $html;
        $scorePos = mb_stripos($html, '<b>' . $teams[0]);
        $head = $scorePos !== false ? mb_substr($html, 0, $scorePos) : '';
        // Bereits Wappen/Bild vor der Ergebniszeile -> nichts tun
        if (stripos($head, '<img') !== false) return $html;
        // Nur bei echten Spielberichten (Ergebniszeile im ersten Teil)
        if ($scorePos === false || $scorePos > 600) return $html;
        $l1 = self::teamLogo($teams[0]);
        $l2 = self::teamLogo($teams[1]);
        if (!$l1 || !$l2) return $html;
        $e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $block = '<center class="news-logos"><img src="' . $e($l1) . '" alt="' . $e($teams[0]) . '"> &nbsp;vs.&nbsp; <img src="' . $e($l2) . '" alt="' . $e($teams[1]) . '"></center><br>';
        return $block . $html;
    }

    /**
     * Autorname: Viele Artikel wurden ueber das Sammelkonto "Redakteur" eingestellt,
     * der echte Name steht dann fett zentriert in der ersten Zeile.
     */
    public static function displayAuthor(?string $author, ?string $html): string
    {
        $author = trim((string)$author);
        $generic = ['', 'redakteur', 'redaktion', 'admin', 'hafo', 'hafo.de'];
        if (!in_array(mb_strtolower($author), $generic, true)) return $author;
        if ($html && preg_match('#^\s*<center>\s*(?:<[^>]+>\s*)*([^<>]{4,40}?)\s*(?:</[^>]+>\s*)*</center>#u', $html, $m)) {
            $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
            // Nur echte Namen: 2-4 Woerter, Buchstaben, kein Satz
            if (preg_match('/^\p{Lu}[\p{L}\.\-\']+(?:\s+[\p{L}\.\-\']+){1,3}$/u', $name)) return $name;
        }
        return $author !== '' ? $author : 'Redaktion';
    }

    /** Klartext-Vorschau fuer Listen */
    public static function excerpt(?string $short, ?string $html, int $len = 220): string
    {
        $text = trim((string)$short);
        // Kurztexte, die nur aus Ergebniszeile/Spieltag bestehen, sind als Vorschau zu duenn
        if (mb_strlen($text) < 60 && $html) {
            $body = preg_replace('#<center>.*?</center>#is', ' ', $html);
            $body = preg_replace('#<(br|p|div)[^>]*>#i', ' ', $body);
            $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($body), ENT_QUOTES, 'UTF-8')));
            $text = $text !== '' ? $text . ' – ' . $plain : $plain;
        } else {
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8')));
        }
        if (mb_strlen($text) > $len) {
            $cut = mb_substr($text, 0, $len);
            $sp = mb_strrpos($cut, ' ');
            $text = ($sp > $len * 0.6 ? mb_substr($cut, 0, $sp) : $cut) . ' …';
        }
        return $text;
    }

    /** Art des Artikels fuer die Kennzeichnung in Listen */
    public static function kind(string $title, ?string $html, bool $linked): string
    {
        if (preg_match('/^\s*Vorschau\b/iu', $title)) return 'Vorschau';
        if ($linked || ($html && self::teamsFromScoreLine(mb_substr($html, 0, 2500)))) return 'Spielbericht';
        return 'News';
    }

    /**
     * Banner, die in den letzten Jahren oben in Berichten verwendet wurden
     * (fuer die Auswahl im Editor). Liefert src (anzeigbar), link, Anzahl, letzte Nutzung, Vereine.
     */
    public static function bannerCatalog(PDO $pdo, int $years = 3): array
    {
        $since = time() - $years * 365 * 86400;
        $stmt = $pdo->prepare('SELECT id, timestamp, content FROM news WHERE timestamp > ? ORDER BY timestamp DESC');
        $stmt->execute([$since]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $head = mb_substr((string)$row['content'], 0, 1500);
            if (!preg_match('#(?:<a[^>]+href=["\']([^"\']+)["\'][^>]*>\s*)?<img[^>]+src=["\']([^"\']+)["\']#i', $head, $m)) continue;
            $raw = $m[2];
            if (preg_match('#wappen|img/teams#i', $raw)) continue; // erstes Bild ist ein Wappen -> kein Banner
            $src = self::mapImageSrc($raw);
            if ($src === null || strpos($src, 'api/img-proxy') === 0) continue;
            if (!isset($out[$src])) {
                $out[$src] = ['src' => $src, 'link' => $m[1] ?: '', 'count' => 0, 'last_used' => (int)$row['timestamp'], 'teams' => []];
            }
            $out[$src]['count']++;
            if ($teams = self::teamsFromScoreLine($head)) {
                foreach ($teams as $t) $out[$src]['teams'][$t] = ($out[$src]['teams'][$t] ?? 0) + 1;
            }
        }
        foreach ($out as &$b) {
            arsort($b['teams']);
            $b['teams'] = array_slice(array_keys($b['teams']), 0, 3);
            $b['label'] = self::bannerLabel($b['src']);
        }
        unset($b);
        return array_values($out);
    }

    private static function bannerLabel(string $src): string
    {
        $name = rawurldecode(pathinfo(parse_url($src, PHP_URL_PATH) ?: $src, PATHINFO_FILENAME));
        $name = preg_replace('/[-_]+/', ' ', $name);
        $name = preg_replace('/\s*banner\s*/i', ' ', $name);
        return trim(ucwords(trim($name))) ?: 'Banner';
    }
}
