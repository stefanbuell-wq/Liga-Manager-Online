<?php
/**
 * Kontaktanfragen (Sponsoring, allgemein, Fehlermeldungen)
 *
 * Jede Anfrage wird in der DB gespeichert und ist im Admin unter "Anfragen" sichtbar.
 * Zusaetzlich Mail-Benachrichtigung, wenn in config/mail.php 'contact_notify'
 * (oder ersatzweise 'admin_notify') gesetzt ist.
 *
 * Aufbewahrung: erledigte Anfragen werden nach 12 Monaten geloescht, alle anderen nach 24.
 */

require_once __DIR__ . '/LmoDatabase.php';
require_once __DIR__ . '/Mailer.php';

class ContactService
{
    public const TOPICS = [
        'sponsoring' => 'Sponsoring / Werbung',
        'allgemein'  => 'Allgemeine Anfrage',
        'fehler'     => 'Fehler oder Korrektur melden',
    ];
    public const INTERESTS = [
        'header' => 'Banner im Header',
        'seite'  => 'Banner links/rechts',
        'news'   => 'Präsentation in Spielberichten/News',
        'sonst'  => 'Andere Idee',
    ];
    public const STATUSES = ['neu', 'in_bearbeitung', 'erledigt'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS contact_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            topic TEXT NOT NULL,
            name TEXT NOT NULL,
            company TEXT,
            email TEXT NOT NULL,
            phone TEXT,
            interests TEXT,
            message TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'neu',
            note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    /** Prueft und speichert eine Anfrage. Gibt die ID zurueck. */
    public function create(array $in): int
    {
        $topic = (string)($in['topic'] ?? '');
        $name = self::clean($in['name'] ?? '', 100);
        $company = self::clean($in['company'] ?? '', 150);
        $email = trim((string)($in['email'] ?? ''));
        $phone = self::clean($in['phone'] ?? '', 40);
        $message = trim(str_replace("\r\n", "\n", (string)($in['message'] ?? '')));
        $interests = array_values(array_intersect(array_keys(self::INTERESTS), (array)($in['interests'] ?? [])));

        if (!isset(self::TOPICS[$topic])) {
            throw new InvalidArgumentException('Bitte ein Thema wählen.');
        }
        if (mb_strlen($name) < 2) {
            throw new InvalidArgumentException('Bitte Ihren Namen angeben.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) {
            throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        if ($phone !== '' && !preg_match('/^[0-9 +()\/.-]{5,40}$/', $phone)) {
            throw new InvalidArgumentException('Die Telefonnummer enthält ungültige Zeichen.');
        }
        if (mb_strlen($message) < 10) {
            throw new InvalidArgumentException('Bitte eine Nachricht mit mindestens 10 Zeichen schreiben.');
        }
        if (mb_strlen($message) > 5000) {
            throw new InvalidArgumentException('Die Nachricht ist zu lang (max. 5000 Zeichen).');
        }
        if (preg_match_all('#https?://#i', $message) > 3) {
            throw new InvalidArgumentException('Bitte höchstens drei Links in die Nachricht setzen.');
        }
        if (empty($in['consent'])) {
            throw new InvalidArgumentException('Bitte der Verarbeitung Ihrer Angaben zustimmen.');
        }
        if ($topic !== 'sponsoring') {
            $interests = [];
        }

        $this->pdo->prepare("INSERT INTO contact_requests (topic, name, company, email, phone, interests, message)
            VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$topic, $name, $company ?: null, $email, $phone ?: null,
                $interests ? implode(',', $interests) : null, $message]);
        $id = (int)$this->pdo->lastInsertId();

        $this->notify($id, $topic, $name, $company, $email, $phone, $interests, $message);
        return $id;
    }

    private function notify(int $id, string $topic, string $name, string $company, string $email,
                            string $phone, array $interests, string $message): void
    {
        $file = __DIR__ . '/../config/mail.php';
        $cfg = file_exists($file) ? (require $file) : [];
        $to = $cfg['contact_notify'] ?? ($cfg['admin_notify'] ?? '');
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $base = rtrim($cfg['base_url'] ?? 'https://hafo.buell-digital.de', '/');
        $lines = [
            'Neue Kontaktanfrage #' . $id . ' über ' . $base,
            '',
            'Thema:    ' . self::TOPICS[$topic],
            'Name:     ' . $name,
            'Firma:    ' . ($company ?: '–'),
            'E-Mail:   ' . $email,
            'Telefon:  ' . ($phone ?: '–'),
        ];
        if ($interests) {
            $lines[] = 'Interesse: ' . implode(', ', array_map(fn($k) => self::INTERESTS[$k], $interests));
        }
        $lines[] = '';
        $lines[] = $message;
        $lines[] = '';
        $lines[] = 'Bearbeiten: ' . $base . '/admin/anfragen.html';
        $lines[] = 'Bitte nicht auf diese Mail antworten, sondern direkt an die oben genannte Adresse schreiben.';
        try {
            (new Mailer())->send($to, '[HAFO] ' . self::TOPICS[$topic] . ': ' . $name, implode("\n", $lines));
        } catch (Throwable $e) {
            error_log('Kontakt-Benachrichtigung fehlgeschlagen: ' . $e->getMessage());
        }
    }

    public function listAll(): array
    {
        $this->purgeOld();
        return $this->pdo->query("SELECT * FROM contact_requests
            ORDER BY CASE status WHEN 'neu' THEN 0 WHEN 'in_bearbeitung' THEN 1 ELSE 2 END, created_at DESC")
            ->fetchAll();
    }

    public function countOpen(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM contact_requests WHERE status = 'neu'")->fetchColumn();
    }

    public function update(int $id, string $status, ?string $note): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Ungültiger Status');
        }
        $note = $note !== null ? mb_substr(trim($note), 0, 2000) : null;
        $stmt = $this->pdo->prepare("UPDATE contact_requests SET status = ?, note = ?, updated_at = datetime('now') WHERE id = ?");
        $stmt->execute([$status, $note ?: null, $id]);
        if (!$stmt->rowCount()) {
            throw new InvalidArgumentException('Anfrage nicht gefunden');
        }
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare("DELETE FROM contact_requests WHERE id = ?")->execute([$id]);
    }

    private function purgeOld(): void
    {
        $this->pdo->exec("DELETE FROM contact_requests
            WHERE (status = 'erledigt' AND updated_at < datetime('now', '-12 months'))
               OR created_at < datetime('now', '-24 months')");
    }

    private static function clean($v, int $max): string
    {
        $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
        return mb_substr($v, 0, $max);
    }
}
