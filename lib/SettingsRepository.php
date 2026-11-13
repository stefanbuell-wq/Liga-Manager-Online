<?php
/**
 * Einfache Schluessel/Wert-Einstellungen aus dem Admin (Seite "Einstellungen").
 * Tabelle app_settings in der Haupt-DB; nur Admins duerfen schreiben (api/settings.php).
 */

require_once __DIR__ . '/LmoDatabase.php';

class SettingsRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = LmoDatabase::getInstance();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare("SELECT value FROM app_settings WHERE key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    }

    public function set(string $key, string $value): void
    {
        $this->pdo->prepare("INSERT INTO app_settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at")
            ->execute([$key, $value]);
    }
}
