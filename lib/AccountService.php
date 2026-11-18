<?php
require_once __DIR__ . '/Instance.php';
/**
 * Konten fuer Forennutzer: Registrierung, E-Mail-Bestaetigung, Freigabe durch Admin,
 * Uebernahme alter Forennamen (Claim), Passwort zuruecksetzen, Inaktivitaet.
 *
 * Status (Spalte users.status):
 *   pending_email    -> E-Mail noch nicht bestaetigt
 *   pending_approval -> wartet auf Freigabe durch Admin
 *   active           -> darf sich anmelden (zusaetzlich users.active = 1)
 *   rejected         -> abgelehnt
 *   inactive         -> automatisch deaktiviert (laenger nicht angemeldet)
 */
require_once __DIR__ . '/LmoDatabase.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Mailer.php';

class AccountService
{
    const VERIFY_TTL = 86400 * 2;     // 48 h
    const RESET_TTL = 3600;           // 1 h
    const INACTIVE_DAYS = 365;        // 12 Monate

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        self::ensureSchema($this->pdo);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $cols = array_column($pdo->query("PRAGMA table_info(users)")->fetchAll(), 'name');
        $add = [
            'email' => "ALTER TABLE users ADD COLUMN email TEXT",
            'status' => "ALTER TABLE users ADD COLUMN status TEXT DEFAULT 'active'",
            'email_verified_at' => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME",
            'legacy_claim' => "ALTER TABLE users ADD COLUMN legacy_claim TEXT",
            'status_changed_at' => "ALTER TABLE users ADD COLUMN status_changed_at DATETIME",
        ];
        foreach ($add as $c => $sql) {
            if (!in_array($c, $cols, true)) $pdo->exec($sql);
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            token_hash TEXT NOT NULL UNIQUE,
            expires_at INTEGER NOT NULL,
            used_at INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users(email)");
        $done = true;
    }

    // ------------------------------------------------------------ Registrierung
    public function register(string $username, string $email, string $password, string $displayName = '', string $legacyClaim = ''): array
    {
        $username = trim($username);
        $email = strtolower(trim($email));
        $legacyClaim = trim($legacyClaim);

        if (str_contains($username, '@')) {
            throw new InvalidArgumentException('Benutzername: bitte keine E-Mail-Adresse, sondern deinen Namen im Forum (z. B. „Stefan B.“). Die E-Mail gehört ins Feld darunter.');
        }
        if (!preg_match('/^[\p{L}0-9 ._-]{3,30}$/u', $username)) {
            throw new InvalidArgumentException('Benutzername: 3–30 Zeichen, Buchstaben, Zahlen, Leerzeichen, . _ -');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        $errors = Security::validatePassword($password);
        if ($errors) throw new InvalidArgumentException(implode('. ', $errors));

        $st = $this->pdo->prepare("SELECT id, role, password_hash, last_login, email FROM users WHERE lower(username) = lower(?)");
        $st->execute([$username]);
        if ($existing = $st->fetch()) {
            throw new InvalidArgumentException(
                $this->isUnclaimedLegacy($existing)
                    ? 'Dieser Name gehört zu einem früheren Forenkonto. Wähle einen anderen Benutzernamen und trage den alten Namen unter „Früherer Forenname“ ein – nach Prüfung übernehmen wir ihn.'
                    : 'Dieser Benutzername ist bereits vergeben.'
            );
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM users WHERE lower(email) = ?");
        $st->execute([$email]);
        if ($st->fetchColumn() > 0) {
            // Keine Aussage, ob die Adresse existiert (Enumeration vermeiden)
            throw new InvalidArgumentException('Registrierung mit dieser E-Mail-Adresse nicht möglich. Nutze ggf. „Passwort vergessen“.');
        }
        if ($legacyClaim !== '') {
            $st = $this->pdo->prepare("SELECT id, role, password_hash, last_login, email FROM users WHERE lower(username) = lower(?)");
            $st->execute([$legacyClaim]);
            $legacy = $st->fetch();
            if (!$legacy || !$this->isUnclaimedLegacy($legacy)) {
                throw new InvalidArgumentException('Der frühere Forenname wurde nicht gefunden oder ist bereits übernommen.');
            }
            $legacyClaim = $this->pdo->query("SELECT username FROM users WHERE id = " . (int)$legacy['id'])->fetchColumn();
        }

        $st = $this->pdo->prepare("INSERT INTO users (username, password_hash, display_name, role, active, email, status, legacy_claim, status_changed_at)
                                   VALUES (?, ?, ?, 'user', 0, ?, 'pending_email', ?, datetime('now'))");
        $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName ?: $username, $email, $legacyClaim ?: null]);
        $userId = (int)$this->pdo->lastInsertId();

        $token = $this->createToken($userId, 'verify', self::VERIFY_TTL);
        try {
        $this->mail($email, Instance::siteName() . ' – Bitte bestätige deine E-Mail-Adresse',
            "Hallo $username,\n\nbitte bestätige deine Registrierung bei " . Instance::siteName() . " mit diesem Link (48 Stunden gültig):\n\n"
            . $this->url("konto.html?verify=$token") . "\n\nDanach prüft ein Administrator dein Konto und schaltet es frei.\n"
            . "Wenn du dich nicht registriert hast, ignoriere diese E-Mail einfach.\n\n" . Instance::teamSignature());
        } catch (Exception $e) {
            // Ohne Bestaetigungsmail ist das Konto unbrauchbar -> wieder entfernen
            $this->pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$userId]);
            $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            throw $e;
        }
        return ['user_id' => $userId];
    }

    /** Altes SMF-Konto: Rolle user, nie angemeldet, keine E-Mail hinterlegt */
    private function isUnclaimedLegacy(array $u): bool
    {
        return $u['role'] === 'user' && empty($u['last_login']) && empty($u['email']);
    }

    public function verifyEmail(string $token): string
    {
        $row = $this->consumeToken($token, 'verify');
        $this->pdo->prepare("UPDATE users SET email_verified_at = datetime('now'), status = 'pending_approval', status_changed_at = datetime('now')
                             WHERE id = ? AND status = 'pending_email'")->execute([$row['user_id']]);
        $notify = $this->mailConfig('admin_notify');
        if ($notify) {
            $u = $this->getUser($row['user_id']);
            try {
                $this->mail($notify, Instance::siteName() . ' – Neue Registrierung wartet auf Freigabe',
                    "Neue Registrierung: {$u['username']} <{$u['email']}>"
                    . ($u['legacy_claim'] ? "\nMöchte früheren Forennamen übernehmen: {$u['legacy_claim']}" : '')
                    . "\n\nFreigabe im Admin unter Benutzer: " . $this->url('admin/index.html'));
            } catch (Exception $e) { error_log('Admin-Notify: ' . $e->getMessage()); }
        }
        return 'Danke! Deine E-Mail-Adresse ist bestätigt. Sobald ein Administrator dein Konto freigegeben hat, bekommst du eine E-Mail.';
    }

    // ------------------------------------------------------------ Freigabe (Admin)
    public function listPending(): array
    {
        return $this->pdo->query("SELECT id, username, display_name, email, status, legacy_claim, created_at, email_verified_at
                                  FROM users WHERE status IN ('pending_email','pending_approval') ORDER BY created_at")->fetchAll();
    }

    public function approve(int $userId, bool $acceptClaim = true): string
    {
        $u = $this->getUser($userId);
        if ($u['status'] !== 'pending_approval') {
            throw new InvalidArgumentException('Konto wartet nicht auf Freigabe (E-Mail noch nicht bestätigt?).');
        }
        $this->pdo->beginTransaction();
        try {
            $loginName = $u['username'];
            if ($acceptClaim && $u['legacy_claim']) {
                // Altes Forenkonto uebernehmen: Zugangsdaten auf das alte Konto uebertragen
                $st = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
                $st->execute([$u['legacy_claim']]);
                $legacy = $st->fetch();
                if (!$legacy || !$this->isUnclaimedLegacy($legacy)) {
                    throw new InvalidArgumentException('Früheres Forenkonto nicht mehr verfügbar.');
                }
                $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
                $this->pdo->prepare("UPDATE users SET password_hash = ?, email = ?, email_verified_at = ?, status = 'active', active = 1,
                                     status_changed_at = datetime('now') WHERE id = ?")
                    ->execute([$u['password_hash'], $u['email'], $u['email_verified_at'], $legacy['id']]);
                Security::markAuthChanged($this->pdo, (int)$legacy['id']);
                $this->pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$userId]);
                $loginName = $legacy['username'];
            } else {
                $this->pdo->prepare("UPDATE users SET status = 'active', active = 1, legacy_claim = NULL, status_changed_at = datetime('now') WHERE id = ?")
                    ->execute([$userId]);
            }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
        $this->mail($u['email'], Instance::siteName() . ' – Dein Konto ist freigeschaltet',
            "Hallo,\n\ndein Konto bei " . Instance::siteName() . " ist freigeschaltet. Du kannst dich jetzt anmelden mit dem Benutzernamen:\n\n    $loginName\n\n"
            . $this->url('konto.html') . "\n\n" . Instance::teamSignature());
        return $loginName;
    }

    public function reject(int $userId): void
    {
        $u = $this->getUser($userId);
        if (!in_array($u['status'], ['pending_email', 'pending_approval'], true)) {
            throw new InvalidArgumentException('Nur offene Registrierungen können abgelehnt werden.');
        }
        $this->pdo->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$userId]);
        $this->pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }

    // ------------------------------------------------------------ Passwort vergessen
    /** Antwortet immer gleich (keine Aussage, ob die Adresse existiert). */
    public function requestReset(string $login): void
    {
        $login = trim($login);
        $st = $this->pdo->prepare("SELECT * FROM users WHERE (lower(email) = lower(?) OR lower(username) = lower(?)) AND email IS NOT NULL AND email <> ''");
        $st->execute([$login, $login]);
        $u = $st->fetch();
        if (!$u || (int)$u['active'] !== 1 || ($u['status'] ?? 'active') !== 'active') return;
        $this->pdo->prepare("UPDATE auth_tokens SET used_at = strftime('%s','now') WHERE user_id = ? AND type = 'reset' AND used_at IS NULL")->execute([$u['id']]);
        $token = $this->createToken((int)$u['id'], 'reset', self::RESET_TTL);
        $this->mail($u['email'], Instance::siteName() . ' – Passwort zurücksetzen',
            "Hallo {$u['username']},\n\nfür dein Konto wurde ein neues Passwort angefordert. Der Link ist 1 Stunde gültig:\n\n"
            . $this->url("konto.html?reset=$token") . "\n\nWenn du das nicht warst, ignoriere diese E-Mail – dein Passwort bleibt unverändert.\n\n" . Instance::teamSignature());
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $errors = Security::validatePassword($newPassword);
        if ($errors) throw new InvalidArgumentException(implode('. ', $errors));
        $row = $this->consumeToken($token, 'reset');
        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $row['user_id']]);
        Security::markAuthChanged($this->pdo, (int)$row['user_id']); // offene Sitzungen beenden
    }

    /** Admin loest Reset-Mail fuer ein Konto aus */
    public function sendResetFor(int $userId): void
    {
        $u = $this->getUser($userId);
        if (empty($u['email'])) throw new InvalidArgumentException('Für dieses Konto ist keine E-Mail-Adresse hinterlegt.');
        $this->requestReset($u['email']);
    }

    // ------------------------------------------------------------ Inaktivitaet
    /** Deaktiviert Forennutzer (Rolle user), die laenger als INACTIVE_DAYS nicht angemeldet waren. */
    public function deactivateInactive(): int
    {
        $st = $this->pdo->prepare("UPDATE users SET active = 0, status = 'inactive', status_changed_at = datetime('now')
            WHERE role = 'user' AND active = 1 AND status = 'active'
              AND NOT (last_login IS NULL AND (email IS NULL OR email = ''))   -- unbeanspruchte Altkonten bleiben reserviert
              AND COALESCE(last_login, email_verified_at, created_at) < datetime('now', ?)");
        $st->execute(['-' . self::INACTIVE_DAYS . ' days']);
        return $st->rowCount();
    }

    /** Hoechstens einmal pro Tag (wird z. B. beim Laden der Benutzerliste angestossen). */
    public function deactivateInactiveDaily(): void
    {
        $flag = __DIR__ . '/../data/cache/.inactive-check';
        if (file_exists($flag) && filemtime($flag) > time() - 86400) return;
        @touch($flag);
        $n = $this->deactivateInactive();
        if ($n > 0) error_log("AccountService: $n inaktive Konten deaktiviert");
    }

    // ------------------------------------------------------------ Hilfen
    public function getUser(int $id): array
    {
        $st = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $st->execute([$id]);
        $u = $st->fetch();
        if (!$u) throw new InvalidArgumentException('Benutzer nicht gefunden');
        return $u;
    }

    private function createToken(int $userId, string $type, int $ttl): string
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare("INSERT INTO auth_tokens (user_id, type, token_hash, expires_at) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $type, hash('sha256', $token), time() + $ttl]);
        return $token;
    }

    private function consumeToken(string $token, string $type): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new InvalidArgumentException('Der Link ist ungültig.');
        $st = $this->pdo->prepare("SELECT * FROM auth_tokens WHERE token_hash = ? AND type = ?");
        $st->execute([hash('sha256', $token), $type]);
        $row = $st->fetch();
        if (!$row || $row['used_at'] || $row['expires_at'] < time()) {
            throw new InvalidArgumentException('Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.');
        }
        $this->pdo->prepare("UPDATE auth_tokens SET used_at = ? WHERE id = ?")->execute([time(), $row['id']]);
        return $row;
    }

    private function mailConfig(string $key)
    {
        $f = __DIR__ . '/../config/mail.php';
        $c = file_exists($f) ? (require $f) : [];
        return $c[$key] ?? null;
    }

    private function url(string $path): string
    {
        $base = Instance::baseUrl($this->mailConfig('base_url'));
        return $base . '/' . ltrim($path, '/');
    }

    private function mail(string $to, string $subject, string $body): void
    {
        (new Mailer())->send($to, $subject, $body);
    }
}
