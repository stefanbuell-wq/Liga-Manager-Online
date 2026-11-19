<?php
require_once __DIR__ . '/Instance.php';
/**
 * Taegliches Briefing per E-Mail: was heute fuer Redaktion/Admins ansteht.
 *
 * Bereiche (je Empfaenger waehlbar, Admin > Einstellungen):
 *   anfragen        – offene Kontaktanfragen (neu / in Bearbeitung)
 *   registrierungen – Forenkonten, die auf Freigabe warten (+ unbestaetigte E-Mails)
 *   forum           – neue Themen und Beitraege seit dem letzten Briefing (hoechstens 48 h)
 *   banner          – Sponsoren-Banner, die in den naechsten 14 Tagen auslaufen
 *
 * Konfiguration in app_settings:
 *   briefing_recipients  JSON [{name, email, sections:[...]}]   (max. 8)
 *   briefing_send_empty  '1' = auch senden, wenn nichts ansteht
 *   briefing_last_sent   Datum des letzten Versands (Schutz gegen Doppelversand)
 *
 * Versand: cron/daily-briefing.php (Plesk "Geplante Aufgaben", taeglich morgens).
 */

require_once __DIR__ . '/LmoDatabase.php';
require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/Mailer.php';

class BriefingService
{
    public const SECTIONS = [
        'anfragen'        => 'Kontaktanfragen',
        'registrierungen' => 'Registrierungen (Forum)',
        'forum'           => 'Forum: neue Beiträge',
        'meldungen'       => 'Gemeldete Forenbeiträge, Kommentare zur Freigabe',
        'banner'          => 'Auslaufende Sponsoren-Banner',
    ];
    public const MAX_RECIPIENTS = 8;
    private const TZ = 'Europe/Berlin';

    private PDO $pdo;
    private SettingsRepository $settings;
    private string $base;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->settings = new SettingsRepository();
        $file = __DIR__ . '/../config/mail.php';
        $cfg = file_exists($file) ? (require $file) : [];
        $this->base = Instance::baseUrl($cfg['base_url'] ?? null);
    }

    /* ---------- Konfiguration ---------- */

    public function getConfig(): array
    {
        $list = json_decode($this->settings->get('briefing_recipients', '[]'), true);
        return [
            'recipients' => is_array($list) ? $list : [],
            'send_empty' => $this->settings->get('briefing_send_empty', '0') === '1',
            'last_sent'  => $this->settings->get('briefing_last_sent'),
        ];
    }

    /** Prueft und speichert die Empfaengerliste. Wirft InvalidArgumentException mit lesbarer Meldung. */
    public function saveConfig(array $recipients, bool $sendEmpty): array
    {
        $clean = [];
        $seen = [];
        foreach (array_values($recipients) as $i => $r) {
            $name = trim((string)($r['name'] ?? ''));
            $email = trim((string)($r['email'] ?? ''));
            $sections = array_values(array_intersect(array_keys(self::SECTIONS), (array)($r['sections'] ?? [])));
            if ($name === '' && $email === '') continue;
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
                throw new InvalidArgumentException('Empfänger ' . ($i + 1) . ': keine gültige E-Mail-Adresse.');
            }
            if (mb_strlen($name) > 80) {
                throw new InvalidArgumentException('Empfänger ' . ($i + 1) . ': Name ist zu lang.');
            }
            $key = mb_strtolower($email);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Die Adresse ' . $email . ' steht doppelt in der Liste.');
            }
            $seen[$key] = true;
            $clean[] = ['name' => $name, 'email' => $email, 'sections' => $sections];
        }
        if (count($clean) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('Höchstens ' . self::MAX_RECIPIENTS . ' Empfänger.');
        }
        $this->settings->set('briefing_recipients', json_encode($clean, JSON_UNESCAPED_UNICODE));
        $this->settings->set('briefing_send_empty', $sendEmpty ? '1' : '0');
        return $clean;
    }

    /** Redaktion/Admins mit E-Mail-Adresse als Vorschlag fuer die Empfaengerliste */
    public function teamSuggestions(): array
    {
        return $this->pdo->query("SELECT COALESCE(NULLIF(display_name, ''), username) AS name, email, role
            FROM users WHERE role IN ('admin', 'editor') AND email IS NOT NULL AND email <> ''
              AND COALESCE(status, 'active') = 'active'
            ORDER BY role, name COLLATE NOCASE")->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ---------- Inhalte ---------- */

    /** Alle Bereiche mit Eintraegen; je Bereich ['count' => n, 'lines' => [...], 'link' => url] */
    public function collect(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return [
            'anfragen'        => $this->openRequests($now),
            'registrierungen' => $this->pendingRegistrations($now),
            'forum'           => $this->forumActivity($now),
            'meldungen'       => $this->forumReports(),
            'banner'          => $this->expiringBanners($now),
        ];
    }

    private function openRequests(DateTimeImmutable $now): array
    {
        if (!$this->tableExists('contact_requests')) return $this->empty('admin/anfragen.html');
        $rows = $this->pdo->query("SELECT id, topic, name, company, status, created_at FROM contact_requests
            WHERE status IN ('neu', 'in_bearbeitung')
            ORDER BY CASE status WHEN 'neu' THEN 0 ELSE 1 END, created_at")->fetchAll(PDO::FETCH_ASSOC);
        $topics = ['sponsoring' => 'Sponsoring', 'allgemein' => 'Allgemein', 'fehler' => 'Fehlermeldung'];
        $lines = [];
        foreach ($rows as $r) {
            $age = $this->ageDays($r['created_at'], $now);
            $lines[] = sprintf('#%d  %s  %s – %s%s  [%s%s]',
                $r['id'], $this->localDate($r['created_at']), $topics[$r['topic']] ?? $r['topic'], $r['name'],
                $r['company'] ? ' (' . $r['company'] . ')' : '',
                $r['status'] === 'neu' ? 'NEU' : 'in Bearbeitung',
                $age >= 3 ? ', seit ' . $age . ' Tagen offen' : '');
        }
        return ['count' => count($rows), 'lines' => $lines, 'link' => $this->base . '/admin/anfragen.html'];
    }

    private function pendingRegistrations(DateTimeImmutable $now): array
    {
        $cols = $this->columns('users');
        if (!in_array('status', $cols, true)) return $this->empty('admin/index.html#users');
        $rows = $this->pdo->query("SELECT username, display_name, email, status, legacy_claim, created_at, email_verified_at
            FROM users WHERE status IN ('pending_approval', 'pending_email') ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
        $lines = [];
        $approve = 0;
        $unconfirmed = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'pending_approval') {
                $approve++;
                $lines[] = sprintf('%s  %s <%s>%s  – wartet auf Freigabe',
                    $this->localDate($r['email_verified_at'] ?: $r['created_at']), $r['username'], $r['email'],
                    !empty($r['legacy_claim']) ? ', beansprucht Altnamen "' . $r['legacy_claim'] . '"' : '');
            } else {
                $unconfirmed++;
            }
        }
        if ($unconfirmed) {
            $lines[] = $unconfirmed . ' Registrierung(en) mit noch nicht bestätigter E-Mail (keine Aktion nötig).';
        }
        // Nur Freigaben sind Aufgaben; unbestaetigte allein loesen kein Briefing aus
        return ['count' => $approve, 'lines' => $approve ? $lines : [], 'link' => $this->base . '/admin/index.html#users'];
    }

    private function forumActivity(DateTimeImmutable $now): array
    {
        if (!$this->tableExists('forum_posts')) return $this->empty('forum.html');
        $since = $this->forumSince($now);
        $stmt = $this->pdo->prepare("SELECT p.id, p.topic_id, p.is_first_post, p.content, p.created_at, p.guest_name,
                COALESCE(NULLIF(u.display_name, ''), u.username) AS author, t.title
            FROM forum_posts p
            JOIN forum_topics t ON t.id = p.topic_id
            LEFT JOIN users u ON u.id = p.user_id
            WHERE p.created_at >= ?
            ORDER BY p.created_at");
        $stmt->execute([$since->format('Y-m-d H:i:s')]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $lines = [];
        foreach ($rows as $r) {
            $text = trim(preg_replace('/\s+/u', ' ', strip_tags(preg_replace('/\[[^\]]{1,20}\]/', ' ', (string)$r['content']))));
            if (mb_strlen($text) > 90) $text = mb_substr($text, 0, 87) . '…';
            $lines[] = sprintf('%s  %s%s – "%s": %s',
                $this->localTime($r['created_at']), $r['is_first_post'] ? 'NEUES THEMA von ' : '',
                $r['author'] ?: (($r['guest_name'] ?: 'Gast') . ' (Gast)'), $r['title'], $text);
            $lines[] = '      ' . $this->base . '/forum.html?topic=' . (int)$r['topic_id'];
        }
        return ['count' => count($rows), 'lines' => $lines, 'link' => $this->base . '/forum.html'];
    }

    private function forumReports(): array
    {
        require_once __DIR__ . '/ForumComfort.php';
        $lines = [];
        foreach ($this->tableExists('forum_reports') ? (new ForumComfort())->openReports() : [] as $r) {
            $why = array_filter(array_map(fn($x) => $x['reason'], $r['reports']));
            $lines[] = sprintf('"%s" – Beitrag von %s, %d× gemeldet%s', $r['title'] ?? '?', $r['author'] ?? '?', count($r['reports']),
                $why ? ': ' . mb_substr(implode(' / ', $why), 0, 120) : '');
        }
        $link = $this->base . '/admin/forum.html';
        // Kommentare unter Artikeln: wartende Freigaben und Meldungen
        if ($this->tableExists('news_comments')) {
            require_once __DIR__ . '/CommentService.php';
            $cs = new CommentService();
            $c = $cs->counts();
            foreach (array_merge($cs->moderationList('pending', 30), $c['reported'] ? $cs->moderationList('reported', 30) : []) as $r) {
                $text = trim(preg_replace('/\s+/u', ' ', $r['content']));
                if (mb_strlen($text) > 80) $text = mb_substr($text, 0, 77) . '…';
                $lines[] = sprintf('Kommentar von %s zu "%s"%s: %s', $r['name'], mb_substr((string)$r['title'], 0, 60),
                    $r['status'] === 'pending' ? ' – wartet auf Freigabe' : ' – ' . $r['reports'] . '× gemeldet', $text);
            }
            if ($c['pending'] || $c['reported']) {
                $lines[] = '      ' . $this->base . '/admin/kommentare.html';
                if (!$this->tableExists('forum_reports') || !(new ForumComfort())->openReports()) $link = $this->base . '/admin/kommentare.html';
            }
        }
        return ['count' => count(array_filter($lines, fn($l) => !str_starts_with($l, '      '))), 'lines' => $lines, 'link' => $link];
    }

    private function expiringBanners(DateTimeImmutable $now): array
    {
        if (!$this->tableExists('sponsor_banners')) return $this->empty('admin/sponsoren.html');
        $today = $now->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
        $until = $now->setTimezone(new DateTimeZone(self::TZ))->modify('+14 days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT sponsor, position, end_date FROM sponsor_banners
            WHERE active = 1 AND end_date IS NOT NULL AND end_date <> '' AND end_date BETWEEN ? AND ?
            ORDER BY end_date, sponsor COLLATE NOCASE");
        $stmt->execute([$today, $until]);
        $pos = ['top' => 'Header', 'left' => 'links', 'right' => 'rechts', 'bericht' => 'Spielbericht'];
        $lines = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = DateTimeImmutable::createFromFormat('Y-m-d', $r['end_date']);
            $lines[] = sprintf('%s (%s) läuft am %s aus', $r['sponsor'], $pos[$r['position']] ?? $r['position'],
                $d ? $d->format('d.m.Y') : $r['end_date']);
        }
        return ['count' => count($lines), 'lines' => $lines, 'link' => $this->base . '/admin/sponsoren.html'];
    }

    /* ---------- Mail ---------- */

    /** Betreff und Text fuer einen Empfaenger; null, wenn nichts ansteht und leere Mails aus sind */
    public function render(array $recipient, array $data, bool $sendEmpty): ?array
    {
        $sections = array_values(array_intersect(array_keys(self::SECTIONS), $recipient['sections'] ?? []));
        if (!$sections) return null;
        $total = 0;
        $parts = [];
        $summary = [];
        foreach ($sections as $s) {
            $d = $data[$s];
            $total += $d['count'];
            if ($d['count']) $summary[] = $d['count'] . ' ' . $this->shortLabel($s, $d['count']);
            $parts[] = mb_strtoupper(self::SECTIONS[$s]) . ($d['count'] ? ' (' . $d['count'] . ')' : '');
            $parts[] = str_repeat('-', 40);
            if ($d['count']) {
                foreach ($d['lines'] as $l) $parts[] = $l;
                $parts[] = '→ ' . $d['link'];
            } else {
                $parts[] = 'Nichts offen.';
            }
            $parts[] = '';
        }
        if ($total === 0 && !$sendEmpty) return null;

        $day = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)));
        $weekday = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)$day->format('w')];
        $greeting = 'Guten Morgen' . ($recipient['name'] !== '' ? ' ' . $recipient['name'] : '') . ',';
        $intro = $total ? 'heute steht auf ' . Instance::siteName() . ' an: ' . implode(', ', $summary) . '.' : 'heute steht auf ' . Instance::siteName() . ' nichts an.';
        $body = $greeting . "\n\n" . $intro . "\n\n" . implode("\n", $parts)
            . "\n--\nTägliches Briefing von " . $this->base . "\n"
            . 'Welche Bereiche Sie bekommen, legt ein Administrator unter ' . $this->base . "/admin/einstellungen.html fest.\n";
        $subject = '[' . Instance::mailTag() . '] Briefing ' . $weekday . ' ' . $day->format('d.m.') . ': '
            . ($total ? implode(', ', $summary) : 'nichts offen');
        return ['subject' => $subject, 'body' => $body, 'total' => $total];
    }

    /**
     * Versand an alle Empfaenger.
     * @param bool $force  auch wenn heute schon gesendet wurde (Test-Knopf, --force)
     * @return array<string,string> email => 'gesendet' | 'nichts offen' | Fehlermeldung
     */
    public function sendAll(bool $force = false, bool $test = false): array
    {
        $cfg = $this->getConfig();
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');
        if (!$force && $cfg['last_sent'] === $today) {
            return ['_' => 'heute schon gesendet'];
        }
        $data = $this->collect();
        $result = [];
        foreach ($cfg['recipients'] as $r) {
            $mail = $this->render($r, $data, $cfg['send_empty'] || $test);
            if ($mail === null) {
                $result[$r['email']] = $r['sections'] ? 'nichts offen' : 'keine Bereiche gewählt';
                continue;
            }
            try {
                (new Mailer())->send($r['email'], ($test ? '[TEST] ' : '') . $mail['subject'], $mail['body']);
                $result[$r['email']] = 'gesendet';
            } catch (Throwable $e) {
                $result[$r['email']] = 'Fehler: ' . $e->getMessage();
                error_log('Briefing an ' . $r['email'] . ' fehlgeschlagen: ' . $e->getMessage());
            }
        }
        if (!$test) {
            $this->settings->set('briefing_last_sent', $today);
            $this->settings->set('briefing_last_run', gmdate('Y-m-d H:i:s'));
        }
        return $result;
    }

    /* ---------- Hilfsfunktionen ---------- */

    /** Forum: seit dem letzten Lauf, hoechstens 48 h, mindestens 24 h zurueck */
    private function forumSince(DateTimeImmutable $now): DateTimeImmutable
    {
        $last = $this->settings->get('briefing_last_run');
        $min = $now->modify('-48 hours');
        $max = $now->modify('-24 hours');
        if ($last && ($t = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $last, new DateTimeZone('UTC')))) {
            if ($t < $min) return $min;
            if ($t > $max) return $max;
            return $t;
        }
        return $max;
    }

    private function shortLabel(string $s, int $n): string
    {
        return [
            'anfragen' => $n === 1 ? 'Anfrage' : 'Anfragen',
            'registrierungen' => $n === 1 ? 'Freigabe' : 'Freigaben',
            'forum' => $n === 1 ? 'Forenbeitrag' : 'Forenbeiträge',
            'banner' => $n === 1 ? 'Banner läuft aus' : 'Banner laufen aus',
            'meldungen' => $n === 1 ? 'gemeldeter Beitrag' : 'gemeldete Beiträge',
        ][$s];
    }

    private function empty(string $path): array
    {
        return ['count' => 0, 'lines' => [], 'link' => $this->base . '/' . $path];
    }

    private function tableExists(string $t): bool
    {
        $st = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$t]);
        return (bool)$st->fetchColumn();
    }

    private function columns(string $t): array
    {
        return array_column($this->pdo->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /** DB-Zeiten (CURRENT_TIMESTAMP) sind UTC */
    private function toLocal(?string $utc): ?DateTimeImmutable
    {
        if (!$utc) return null;
        try {
            return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(self::TZ));
        } catch (Throwable $e) {
            return null;
        }
    }

    private function localDate(?string $utc): string
    {
        $d = $this->toLocal($utc);
        return $d ? $d->format('d.m.') : '';
    }

    private function localTime(?string $utc): string
    {
        $d = $this->toLocal($utc);
        return $d ? $d->format('d.m. H:i') : '';
    }

    private function ageDays(?string $utc, DateTimeImmutable $now): int
    {
        $d = $this->toLocal($utc);
        return $d ? (int)floor(($now->getTimestamp() - $d->getTimestamp()) / 86400) : 0;
    }
}
