<?php
/**
 * Sponsoren-Banner: Verwaltung, Auslieferung, Statistik
 *
 * Positionen und erlaubte Groessen (jeweils 1x oder 2x fuer hochaufloesende Displays):
 *   top   – Leaderboard 728x90, optional Mobil-Variante 320x50
 *   left  – Skyscraper 160x600, optional Kompakt-Variante 300x250 (kleine Bildschirme)
 *   right – wie left
 *   bericht – Banner oben in Spielberichten, freies Format (Breite 200–1600, Hoehe 40–800 px).
 *             Jedem Bericht ist hoechstens ein Banner zugeordnet (Redaktion waehlt im Editor),
 *             Bild, Link und Laufzeit gelten fuer alle Berichte mit diesem Banner.
 *
 * Oeffentliche Pfade heissen bewusst "foerderer" statt "banner"/"ad"/"sponsor",
 * damit Werbeblocker-Filterlisten die Banner nicht pauschal ausblenden.
 */

require_once __DIR__ . '/LmoDatabase.php';

class SponsorRepository
{
    public const POSITIONS = [
        'top'   => ['label' => 'Header',          'main' => [728, 90],  'alt' => [320, 50],  'altLabel' => 'Mobil'],
        'left'  => ['label' => 'Links',           'main' => [160, 600], 'alt' => [300, 250], 'altLabel' => 'Kompakt'],
        'right' => ['label' => 'Rechts',          'main' => [160, 600], 'alt' => [300, 250], 'altLabel' => 'Kompakt'],
        'bericht' => ['label' => 'Spielbericht',  'main' => null,       'alt' => null,       'altLabel' => null,
                      'free' => ['minW' => 200, 'maxW' => 1600, 'minH' => 40, 'maxH' => 800]],
    ];

    /** Merker in app_settings: bisherige Bericht-Banner wurden einmalig uebernommen */
    private const IMPORT_FLAG = 'report_banners_imported';

    public const MAX_FILE_BYTES = 1048576; // 1 MB
    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];

    private PDO $pdo;
    private string $imageDir;
    private string $imageUrlPrefix = 'img/foerderer/';
    private string $cacheFile;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->imageDir = __DIR__ . '/../img/foerderer';
        $this->cacheFile = __DIR__ . '/../data/cache/foerderer.json';
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sponsor_banners (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sponsor TEXT NOT NULL,
            position TEXT NOT NULL,
            image TEXT NOT NULL,
            image_alt TEXT,
            link_url TEXT,
            alt_text TEXT,
            start_date TEXT,
            end_date TEXT,
            weight INTEGER NOT NULL DEFAULT 1,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS sponsor_stats (
            banner_id INTEGER NOT NULL,
            day TEXT NOT NULL,
            views INTEGER NOT NULL DEFAULT 0,
            clicks INTEGER NOT NULL DEFAULT 0,
            PRIMARY KEY (banner_id, day),
            FOREIGN KEY(banner_id) REFERENCES sponsor_banners(id) ON DELETE CASCADE
        )");
        // Bericht-Banner: Vereine (fuer den Vorschlag im Editor) und Herkunft aus Altberichten
        $cols = array_column($this->pdo->query("PRAGMA table_info(sponsor_banners)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('teams', $cols, true)) {
            $this->pdo->exec("ALTER TABLE sponsor_banners ADD COLUMN teams TEXT");
        }
        if (!in_array('legacy_src', $cols, true)) {
            $this->pdo->exec("ALTER TABLE sponsor_banners ADD COLUMN legacy_src TEXT");
        }
    }

    /* ---------- Oeffentlich ---------- */

    /** Aktive Banner je Position (5 Minuten gecacht, Cache gilt nur fuer den aktuellen Tag) */
    public function getPublicBanners(): array
    {
        $today = date('Y-m-d');
        if (is_file($this->cacheFile) && time() - filemtime($this->cacheFile) < 300) {
            $cached = json_decode((string)file_get_contents($this->cacheFile), true);
            if (is_array($cached) && ($cached['day'] ?? '') === $today) {
                return $cached['data'];
            }
        }

        $stmt = $this->pdo->prepare("SELECT id, sponsor, position, image, image_alt, alt_text, link_url, weight
            FROM sponsor_banners
            WHERE active = 1
              AND (start_date IS NULL OR start_date = '' OR start_date <= :d)
              AND (end_date IS NULL OR end_date = '' OR end_date >= :d)
            ORDER BY id");
        $stmt->execute([':d' => $today]);

        $data = ['top' => [], 'left' => [], 'right' => []];
        foreach ($stmt->fetchAll() as $row) {
            if (!isset($data[$row['position']])) {
                continue;
            }
            $data[$row['position']][] = [
                'id'     => (int)$row['id'],
                'img'    => $this->imageUrlPrefix . $row['image'],
                'imgAlt' => $row['image_alt'] ? $this->imageUrlPrefix . $row['image_alt'] : null,
                'alt'    => $row['alt_text'] ?: $row['sponsor'],
                'link'   => !empty($row['link_url']),
                'weight' => max(1, (int)$row['weight']),
            ];
        }

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($this->cacheFile, json_encode(['day' => $today, 'data' => $data]));
        return $data;
    }

    /** Ziel-URL fuer Klick-Weiterleitung (nur aktive Banner, nur http/https) */
    public function getLinkForClick(int $id): ?string
    {
        $stmt = $this->pdo->prepare("SELECT link_url FROM sponsor_banners WHERE id = ? AND active = 1");
        $stmt->execute([$id]);
        $url = $stmt->fetchColumn();
        return ($url && self::isValidUrl($url)) ? $url : null;
    }

    public function countClick(int $id): void
    {
        $this->bump($id, 0, 1);
    }

    /** @param array<int|string,int> $views  banner_id => Anzahl */
    public function countViews(array $views): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM sponsor_banners WHERE active = 1
            AND (start_date IS NULL OR start_date = '' OR start_date <= :d)
            AND (end_date IS NULL OR end_date = '' OR end_date >= :d)");
        $stmt->execute([':d' => date('Y-m-d')]);
        $valid = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $valid = array_flip(array_map('intval', $valid));
        $total = 0;
        $this->pdo->beginTransaction();
        foreach ($views as $id => $n) {
            $id = (int)$id;
            $n = min(30, max(0, (int)$n));   // Schutz gegen aufgeblaehte Zaehler
            if ($n === 0 || !isset($valid[$id]) || $total >= 100) {
                continue;
            }
            $total += $n;
            $this->bump($id, $n, 0);
        }
        $this->pdo->commit();
    }

    private function bump(int $id, int $views, int $clicks): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO sponsor_stats (banner_id, day, views, clicks)
            SELECT id, :day, :v, :c FROM sponsor_banners WHERE id = :id
            ON CONFLICT(banner_id, day) DO UPDATE SET
                views = views + excluded.views,
                clicks = clicks + excluded.clicks");
        $stmt->execute([':day' => date('Y-m-d'), ':v' => $views, ':c' => $clicks, ':id' => $id]);
    }

    /* ---------- Verwaltung ---------- */

    public function listAll(?string $from = null, ?string $to = null): array
    {
        $from = self::isDate($from) ? $from : '0000-00-00';
        $to = self::isDate($to) ? $to : '9999-12-31';
        $stmt = $this->pdo->prepare("SELECT b.*,
                COALESCE(SUM(s.views), 0) AS views,
                COALESCE(SUM(s.clicks), 0) AS clicks
            FROM sponsor_banners b
            LEFT JOIN sponsor_stats s ON s.banner_id = b.id AND s.day BETWEEN :f AND :t
            GROUP BY b.id
            ORDER BY CASE b.position WHEN 'top' THEN 0 WHEN 'left' THEN 1 WHEN 'right' THEN 2 ELSE 3 END, b.sponsor COLLATE NOCASE");
        $stmt->execute([':f' => $from, ':t' => $to]);
        $rows = $stmt->fetchAll();
        $usage = $this->pdo->prepare("SELECT COUNT(*) FROM news WHERE content LIKE :ref OR (:file <> '' AND content LIKE :file)");
        foreach ($rows as &$r) {
            $r['image_url'] = $this->imageUrlPrefix . $r['image'];
            $r['image_alt_url'] = $r['image_alt'] ? $this->imageUrlPrefix . $r['image_alt'] : null;
            $r['teams'] = self::decodeTeams($r['teams'] ?? null);
            if ($r['position'] === 'bericht') {
                $file = $r['legacy_src'] ? rawurldecode(basename($r['legacy_src'])) : '';
                $usage->execute([':ref' => '%hafo-banner-' . (int)$r['id'] . '"%', ':file' => $file !== '' ? '%/' . $file . '%' : '']);
                $r['reports'] = (int)$usage->fetchColumn();
            }
        }
        return $rows;
    }

    /** Tageswerte fuer CSV-Export */
    public function dailyStats(string $from, string $to, ?int $bannerId = null): array
    {
        $sql = "SELECT s.day, b.sponsor, b.position, b.id AS banner_id, s.views, s.clicks
            FROM sponsor_stats s JOIN sponsor_banners b ON b.id = s.banner_id
            WHERE s.day BETWEEN :f AND :t";
        $params = [':f' => $from, ':t' => $to];
        if ($bannerId) {
            $sql .= " AND b.id = :id";
            $params[':id'] = $bannerId;
        }
        $sql .= " ORDER BY b.sponsor COLLATE NOCASE, b.id, s.day";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Anlegen oder aendern.
     * @param array $in      Formularfelder
     * @param array $files   ['image' => $_FILES-Eintrag|null, 'image_alt' => ...]
     */
    public function save(array $in, array $files): int
    {
        $id = (int)($in['id'] ?? 0);
        $existing = null;
        if ($id) {
            $stmt = $this->pdo->prepare("SELECT * FROM sponsor_banners WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new InvalidArgumentException('Banner nicht gefunden');
            }
        }

        $sponsor = trim((string)($in['sponsor'] ?? ''));
        $position = (string)($in['position'] ?? '');
        $link = trim((string)($in['link_url'] ?? ''));
        $altText = trim((string)($in['alt_text'] ?? ''));
        $start = trim((string)($in['start_date'] ?? ''));
        $end = trim((string)($in['end_date'] ?? ''));
        $weight = min(10, max(1, (int)($in['weight'] ?? 1)));
        $active = !empty($in['active']) && $in['active'] !== '0' && $in['active'] !== 'false' ? 1 : 0;
        $removeAlt = !empty($in['remove_image_alt']) && $in['remove_image_alt'] !== '0';
        $teams = self::parseTeams((string)($in['teams'] ?? ''));

        if ($sponsor === '' || mb_strlen($sponsor) > 120) {
            throw new InvalidArgumentException('Sponsorname fehlt oder ist zu lang');
        }
        if (!isset(self::POSITIONS[$position])) {
            throw new InvalidArgumentException('Ungueltige Position');
        }
        if ($link !== '' && !self::isValidUrl($link)) {
            throw new InvalidArgumentException('Link muss mit http:// oder https:// beginnen');
        }
        if (($start !== '' && !self::isDate($start)) || ($end !== '' && !self::isDate($end))) {
            throw new InvalidArgumentException('Ungueltiges Datum');
        }
        if ($start !== '' && $end !== '' && $end < $start) {
            throw new InvalidArgumentException('Ende liegt vor dem Start');
        }
        if (mb_strlen($altText) > 200) {
            throw new InvalidArgumentException('Alternativtext ist zu lang');
        }

        $pos = self::POSITIONS[$position];
        $hasNewMain = self::hasUpload($files['image'] ?? null);
        $hasNewAlt = $pos['alt'] !== null && self::hasUpload($files['image_alt'] ?? null);
        if ($pos['alt'] === null) {
            $removeAlt = true;   // Bericht-Banner haben kein Zweitbild
            $weight = 1;         // und rotieren nicht
        } else {
            $teams = [];
        }

        if (!$existing && !$hasNewMain) {
            throw new InvalidArgumentException('Bitte ein Bannerbild hochladen');
        }
        // Positionswechsel mit altem Bild in falscher Groesse verhindern
        if ($existing && $existing['position'] !== $position) {
            $oldPos = self::POSITIONS[$existing['position']] ?? null;
            if (!$hasNewMain && (!$oldPos || $oldPos['main'] !== $pos['main'] || $pos['main'] === null)) {
                throw new InvalidArgumentException('Bei Wechsel der Position bitte ein Bild in passender Groesse hochladen');
            }
            if (!$hasNewAlt && $existing['image_alt'] && (!$oldPos || $oldPos['alt'] !== $pos['alt'])) {
                $removeAlt = true;
            }
        }

        // Bilder zuerst pruefen, dann speichern (keine halben Zustaende)
        $mainInfo = $hasNewMain ? $this->checkImage($files['image'], $pos['main'], 'Bannerbild') : null;
        $altInfo = $hasNewAlt ? $this->checkImage($files['image_alt'], $pos['alt'], $pos['altLabel'] . '-Bild') : null;
        if ($mainInfo === null && $existing && $position === 'bericht' && $existing['position'] !== 'bericht') {
            throw new InvalidArgumentException('Bei Wechsel der Position bitte ein Bild in passender Groesse hochladen');
        }

        $image = $existing['image'] ?? null;
        $imageAlt = $existing['image_alt'] ?? null;
        $toDelete = [];

        if ($mainInfo) {
            if ($image) $toDelete[] = $image;
            $image = $this->storeImage($files['image'], $mainInfo);
        }
        if ($altInfo) {
            if ($imageAlt) $toDelete[] = $imageAlt;
            $imageAlt = $this->storeImage($files['image_alt'], $altInfo);
        } elseif ($removeAlt && $imageAlt) {
            $toDelete[] = $imageAlt;
            $imageAlt = null;
        }

        $params = [
            ':sponsor' => $sponsor, ':position' => $position, ':image' => $image, ':image_alt' => $imageAlt,
            ':link' => $link ?: null, ':alt' => $altText ?: null,
            ':start' => $start ?: null, ':end' => $end ?: null,
            ':weight' => $weight, ':active' => $active,
            ':teams' => $teams ? json_encode($teams, JSON_UNESCAPED_UNICODE) : null,
        ];
        if ($existing) {
            $params[':id'] = $id;
            $this->pdo->prepare("UPDATE sponsor_banners SET sponsor = :sponsor, position = :position,
                image = :image, image_alt = :image_alt, link_url = :link, alt_text = :alt,
                start_date = :start, end_date = :end, weight = :weight, active = :active, teams = :teams,
                updated_at = datetime('now') WHERE id = :id")->execute($params);
        } else {
            $this->pdo->prepare("INSERT INTO sponsor_banners
                (sponsor, position, image, image_alt, link_url, alt_text, start_date, end_date, weight, active, teams)
                VALUES (:sponsor, :position, :image, :image_alt, :link, :alt, :start, :end, :weight, :active, :teams)")
                ->execute($params);
            $id = (int)$this->pdo->lastInsertId();
        }

        foreach ($toDelete as $f) {
            $this->deleteImage($f);
        }
        $this->clearCache();
        return $id;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->pdo->prepare("UPDATE sponsor_banners SET active = ?, updated_at = datetime('now') WHERE id = ?")
            ->execute([$active ? 1 : 0, $id]);
        $this->clearCache();
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare("SELECT image, image_alt FROM sponsor_banners WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new InvalidArgumentException('Banner nicht gefunden');
        }
        $this->pdo->prepare("DELETE FROM sponsor_stats WHERE banner_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM sponsor_banners WHERE id = ?")->execute([$id]);
        $this->deleteImage($row['image']);
        if ($row['image_alt']) {
            $this->deleteImage($row['image_alt']);
        }
        $this->clearCache();
    }

    /* ---------- Bericht-Banner ---------- */

    /**
     * Alle Bericht-Banner fuer Editor und Ausgabe (einmal je Anfrage geladen).
     * @return array<int,array> id => [id, sponsor, img, alt, link(bool), live(bool), teams, legacy_src]
     */
    public function reportBanners(): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        $today = date('Y-m-d');
        $rows = $this->pdo->query("SELECT * FROM sponsor_banners WHERE position = 'bericht' ORDER BY sponsor COLLATE NOCASE")->fetchAll();
        $memo = [];
        foreach ($rows as $r) {
            $live = (int)$r['active'] === 1
                && (empty($r['start_date']) || $r['start_date'] <= $today)
                && (empty($r['end_date']) || $r['end_date'] >= $today);
            $memo[(int)$r['id']] = [
                'id'         => (int)$r['id'],
                'sponsor'    => $r['sponsor'],
                'img'        => $this->imageUrlPrefix . $r['image'],
                'alt'        => $r['alt_text'] ?: $r['sponsor'],
                'link'       => !empty($r['link_url']),
                'live'       => $live,
                'teams'      => self::decodeTeams($r['teams'] ?? null),
                'legacy_src' => $r['legacy_src'] ?: null,
            ];
        }
        return $memo;
    }

    /** HTML fuer ein Bericht-Banner (Link ueber die Klick-Weiterleitung, data-fd-id fuer Sichtkontakte) */
    public static function reportBannerHtml(array $b): string
    {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $img = '<img src="' . $h($b['img']) . '" alt="' . $h($b['alt']) . '" loading="lazy">';
        if ($b['link']) {
            return '<a href="api/foerderer.php?go=' . (int)$b['id'] . '" target="_blank" rel="sponsored noopener" class="hafo-fd" data-fd-id="'
                . (int)$b['id'] . '" title="' . $h($b['alt']) . '">' . $img . '</a>';
        }
        return '<span class="hafo-fd" data-fd-id="' . (int)$b['id'] . '">' . $img . '</span>';
    }

    /**
     * Einmalig: Banner, die bisher fest in Berichten standen (NewsContent::bannerCatalog),
     * als Bericht-Banner anlegen. Danach nie wieder automatisch (geloeschte bleiben geloescht).
     * @return int Anzahl uebernommener Banner
     */
    public function importReportBannersOnce(): int
    {
        require_once __DIR__ . '/SettingsRepository.php';
        require_once __DIR__ . '/NewsContent.php';
        $settings = new SettingsRepository();
        if ($settings->get(self::IMPORT_FLAG) !== '') return 0;

        $root = dirname(__DIR__);
        $known = $this->pdo->query("SELECT legacy_src FROM sponsor_banners WHERE legacy_src IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        // Lesbare Namen fuer die bekannten Altbanner (sonst aus dem Dateinamen abgeleitet)
        $names = [
            'stpcoffee.jpg' => 'St. Pauli Coffee', 'Stehn-Banner.jpg' => 'Stehn & Co.',
            'curslack-foerderkreis.jpg' => 'Förderkreis SV Curslack-Neuengamme', 'BannerElbhandwerk.png' => 'Elbhandwerk',
            'hetax-banner.jpg' => 'HE-TAX Steuerberatung', 'hfo-banner.gif' => 'HAFO (Eigenwerbung)',
            'Paloma_Banner_Helle.png' => 'helle idee (USC Paloma)', 'Germania_Banner.png' => 'handyalarm (Germania Schnelsen)',
            'neue-eilbeker-apotheke.png' => 'Neue Eilbeker Apotheke',
        ];
        $n = 0;
        foreach (NewsContent::bannerCatalog($this->pdo, 5) as $b) {
            $b['label'] = $names[rawurldecode(basename($b['src']))] ?? $b['label'];
            $src = $b['src'];
            if (strpos($src, 'legacy/') !== 0 || in_array($src, $known, true)) continue;
            $path = $root . '/' . rawurldecode($src);
            if (strpos($src, '..') !== false || !is_file($path)) continue;
            $info = @getimagesize($path);
            if (!$info || !isset(self::MIME_EXT[$info['mime']]) || filesize($path) > 2 * self::MAX_FILE_BYTES) continue;
            if (!is_dir($this->imageDir) && !@mkdir($this->imageDir, 0755, true)) break;
            $name = bin2hex(random_bytes(12)) . '.' . self::MIME_EXT[$info['mime']];
            if (!@copy($path, $this->imageDir . '/' . $name)) continue;
            @chmod($this->imageDir . '/' . $name, 0644);
            $link = trim((string)$b['link']);
            $this->pdo->prepare("INSERT INTO sponsor_banners
                (sponsor, position, image, link_url, weight, active, teams, legacy_src)
                VALUES (?, 'bericht', ?, ?, 1, 1, ?, ?)")
                ->execute([
                    mb_substr($b['label'], 0, 120), $name,
                    self::isValidUrl($link) ? $link : null,
                    $b['teams'] ? json_encode(array_values($b['teams']), JSON_UNESCAPED_UNICODE) : null,
                    $src,
                ]);
            $n++;
        }
        $settings->set(self::IMPORT_FLAG, date('c') . ' (' . $n . ')');
        return $n;
    }

    /** Vereinsliste aus dem Formular: eine pro Zeile oder durch Komma getrennt, hoechstens 10 */
    private static function parseTeams(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) as $t) {
            $t = trim($t);
            if ($t === '') continue;
            if (mb_strlen($t) > 80) throw new InvalidArgumentException('Vereinsname ist zu lang');
            $out[mb_strtolower($t)] = $t;
        }
        if (count($out) > 10) throw new InvalidArgumentException('Hoechstens 10 Vereine je Banner');
        return array_values($out);
    }

    private static function decodeTeams(?string $json): array
    {
        $v = $json ? json_decode($json, true) : null;
        return is_array($v) ? array_values(array_filter($v, 'is_string')) : [];
    }

    /* ---------- Hilfsfunktionen ---------- */

    private static function hasUpload($file): bool
    {
        return is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    }

    /**
     * @param array|null $size  [Breite, Hoehe] (exakt oder doppelt) oder null = freies Format (Bericht)
     * @return array{ext:string}
     */
    private function checkImage(array $file, ?array $size, string $label): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException("$label: Upload fehlgeschlagen");
        }
        if ($file['size'] > self::MAX_FILE_BYTES) {
            throw new InvalidArgumentException("$label: Datei groesser als 1 MB");
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !isset(self::MIME_EXT[$info['mime']])) {
            throw new InvalidArgumentException("$label: nur JPG, PNG, GIF oder WebP erlaubt");
        }
        [$w, $h] = [$info[0], $info[1]];
        if ($size === null) {
            $f = self::POSITIONS['bericht']['free'];
            if ($w < $f['minW'] || $w > $f['maxW'] || $h < $f['minH'] || $h > $f['maxH']) {
                throw new InvalidArgumentException(sprintf('%s hat %dx%d Pixel, erlaubt sind %d–%d breit und %d–%d hoch',
                    $label, $w, $h, $f['minW'], $f['maxW'], $f['minH'], $f['maxH']));
            }
            return ['ext' => self::MIME_EXT[$info['mime']]];
        }
        [$sw, $sh] = $size;
        if (!(($w === $sw && $h === $sh) || ($w === 2 * $sw && $h === 2 * $sh))) {
            throw new InvalidArgumentException(sprintf(
                '%s hat %dx%d Pixel, erwartet %dx%d (oder %dx%d)', $label, $w, $h, $sw, $sh, 2 * $sw, 2 * $sh));
        }
        return ['ext' => self::MIME_EXT[$info['mime']]];
    }

    private function storeImage(array $file, array $info): string
    {
        if (!is_dir($this->imageDir) && !mkdir($this->imageDir, 0755, true)) {
            throw new RuntimeException('Bildordner kann nicht angelegt werden');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $info['ext'];
        if (!move_uploaded_file($file['tmp_name'], $this->imageDir . '/' . $name)) {
            throw new RuntimeException('Bild konnte nicht gespeichert werden');
        }
        @chmod($this->imageDir . '/' . $name, 0644);
        return $name;
    }

    private function deleteImage(?string $name): void
    {
        if ($name && preg_match('/^[a-f0-9]{24}\.(jpg|png|gif|webp)$/', $name)) {
            @unlink($this->imageDir . '/' . $name);
        }
    }

    public function clearCache(): void
    {
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    public static function isValidUrl(string $url): bool
    {
        return (bool)filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url);
    }

    public static function isDate(?string $d): bool
    {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
    }
}
