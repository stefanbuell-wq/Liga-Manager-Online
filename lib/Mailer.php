<?php
require_once __DIR__ . '/Instance.php';
/**
 * Minimaler SMTP-Mailer (SSL/465 oder STARTTLS/587) (z. B. Hostinger, netcup).
 * Konfiguration: config/mail.php (siehe config/mail.example.php). Ohne Konfiguration
 * wird PHPs mail() verwendet. Keine externen Abhaengigkeiten.
 */
class Mailer
{
    private array $cfg;

    public function __construct()
    {
        $file = __DIR__ . '/../config/mail.php';
        $this->cfg = file_exists($file) ? (require $file) : [];
    }

    public function isConfigured(): bool
    {
        return !empty($this->cfg['host']) && !empty($this->cfg['username']) && !empty($this->cfg['password']);
    }

    public function fromAddress(): string
    {
        return $this->cfg['from'] ?? ($this->cfg['username'] ?? ('noreply@' . (parse_url(Instance::baseUrl($this->cfg['base_url'] ?? null), PHP_URL_HOST) ?: 'localhost')));
    }

    /** Sendet eine Text-Mail. Wirft Exception bei Fehler. */
    public function send(string $to, string $subject, string $body): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Ungueltige Empfaengeradresse');
        }
        // Testumgebung (config/instance.php: mail_mode = 'log'): nichts versenden, nur protokollieren
        if (Instance::get('mail_mode') === 'log') {
            $line = '[' . date('c') . "] AN: $to | BETREFF: $subject\n" . $body . "\n" . str_repeat('-', 60) . "\n";
            @file_put_contents(__DIR__ . '/../data/mail-log.txt', $line, FILE_APPEND | LOCK_EX);
            return;
        }
        $from = $this->fromAddress();
        $fromName = ($this->cfg['from_name'] ?? '') ?: Instance::siteName();
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'From: ' . '=?UTF-8?B?' . base64_encode($fromName) . '?=' . " <$from>",
            'Reply-To: ' . ($this->cfg['reply_to'] ?? $from),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
        ];

        if (!$this->isConfigured()) {
            if (!@mail($to, $subjectEnc, $body, implode("\r\n", $headers), '-f' . $from)) {
                throw new Exception('E-Mail-Versand fehlgeschlagen (mail())');
            }
            return;
        }
        $this->smtpSend($from, $to, "To: <$to>\r\nSubject: $subjectEnc\r\n" . implode("\r\n", $headers), $body);
    }

    private function smtpSend(string $from, string $to, string $headers, string $body): void
    {
        $host = $this->cfg['host'];
        $port = (int)($this->cfg['port'] ?? 465);
        $secure = $this->cfg['secure'] ?? ($port === 465 ? 'ssl' : 'tls');
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port; // 'none' nur fuer lokale Tests

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new Exception("SMTP-Verbindung fehlgeschlagen: $errstr");
        }
        stream_set_timeout($fp, 15);
        try {
            $this->expect($fp, 220);
            $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
            $this->cmd($fp, $ehlo, 250);
            if ($secure === 'tls') {
                $this->cmd($fp, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('STARTTLS fehlgeschlagen');
                }
                $this->cmd($fp, $ehlo, 250);
            }
            $this->cmd($fp, 'AUTH LOGIN', 334);
            $this->cmd($fp, base64_encode($this->cfg['username']), 334);
            $this->cmd($fp, base64_encode($this->cfg['password']), 235);
            $this->cmd($fp, "MAIL FROM:<$from>", 250);
            $this->cmd($fp, "RCPT TO:<$to>", [250, 251]);
            $this->cmd($fp, 'DATA', 354);
            $data = $headers . "\r\n\r\n" . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body)));
            $this->cmd($fp, $data . "\r\n.", 250);
            $this->cmd($fp, 'QUIT', 221);
        } finally {
            fclose($fp);
        }
    }

    private function cmd($fp, string $line, $expect): void
    {
        fwrite($fp, $line . "\r\n");
        $this->expect($fp, $expect);
    }

    private function expect($fp, $codes): string
    {
        $codes = (array)$codes;
        $resp = '';
        while (($l = fgets($fp, 515)) !== false) {
            $resp .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new Exception('SMTP-Fehler: ' . trim(substr($resp, 0, 200)));
        }
        return $resp;
    }
}
