<?php
/**
 * Instanz-Einstellungen: alles, was eine konkrete Installation von einer anderen unterscheidet
 * (Name, Adresse, Mail-Kennung, Texte). Werte kommen aus config/instance.php (nicht im Repo,
 * Vorlage: config/instance.example.php); fehlende Werte fallen auf neutrale Vorgaben zurueck.
 *
 * Name und Untertitel lassen sich zusaetzlich im Admin unter "Design" ueberschreiben;
 * dort gespeicherte Werte haben Vorrang (siehe Instance::siteName()).
 */
class Instance
{
    private const DEFAULTS = [
        'site_name'      => 'Liga Manager Online',  // Anzeigename, z. B. "HAFO.de"
        'site_tagline'   => '',                     // Untertitel, z. B. "Hamburger Fußball Online"
        'base_url'       => '',                     // https://example.org (ohne / am Ende); sonst aus config/mail.php bzw. Anfrage
        'mail_tag'       => '',                     // Kuerzel im Betreff interner Mails, z. B. "HAFO" -> "[HAFO] ..."; leer = site_name
        'tip_label'      => 'Tipp',                 // Beschriftung des Tipps in Spieltagsvorschauen, z. B. "HAFO-Tipp"
        'region'         => '',                     // fuer Texte, z. B. "Hamburger Amateurfußball"
        'legacy_forum'   => false,                  // true: Hinweis zur Uebernahme alter Forenkonten bei der Registrierung
        'legacy_host'    => '',                     // Host der frueheren Website (Altbilder/-links in Berichten), z. B. "hafo.de"
    ];

    private static ?array $cfg = null;

    public static function all(): array
    {
        if (self::$cfg === null) {
            $file = __DIR__ . '/../config/instance.php';
            $own = is_file($file) ? (require $file) : [];
            self::$cfg = array_merge(self::DEFAULTS, is_array($own) ? $own : []);
        }
        return self::$cfg;
    }

    public static function get(string $key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /** Anzeigename; ein im Design-Editor gespeicherter Name hat Vorrang. */
    public static function siteName(): string
    {
        static $name = null;
        if ($name === null) {
            $fromDesign = self::themeSetting('siteName');
            $name = $fromDesign !== '' ? $fromDesign : (string)self::get('site_name');
        }
        return $name;
    }

    /** Kuerzel fuer Betreffzeilen interner Mails: "[HAFO] ..." */
    public static function mailTag(): string
    {
        $tag = trim((string)self::get('mail_tag'));
        return $tag !== '' ? $tag : self::siteName();
    }

    /** Basis-URL ohne abschliessenden Schraegstrich. $mailBase: base_url aus config/mail.php (Altbestand). */
    public static function baseUrl(?string $mailBase = null): string
    {
        $url = trim((string)self::get('base_url'));
        if ($url === '' && $mailBase) $url = $mailBase;
        if ($url === '' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $url = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        }
        return rtrim($url, '/');
    }

    /** Grussformel unter Mails an Nutzer */
    public static function teamSignature(): string
    {
        return 'Dein ' . self::siteName() . '-Team';
    }

    /** Oeffentliche Werte fuer die Seiten (ueber api/theme.php) */
    public static function publicSettings(): array
    {
        return [
            'tipLabel'    => (string)self::get('tip_label'),
            'region'      => (string)self::get('region'),
            'legacyForum' => (bool)self::get('legacy_forum'),
        ];
    }

    private static function themeSetting(string $key): string
    {
        try {
            require_once __DIR__ . '/LmoDatabase.php';
            $st = LmoDatabase::getInstance()->prepare("SELECT value FROM settings WHERE key = ?");
            $st->execute(['theme_' . strtolower(preg_replace('/([A-Z])/', '_$1', $key))]);
            $v = $st->fetchColumn();
            return $v === false || $v === null ? '' : trim((string)$v);
        } catch (Throwable $e) {
            return '';
        }
    }
}
