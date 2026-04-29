<?php
declare(strict_types=1);

final class Settings
{
    public static function get(string $key, string $default = ''): string
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
    }

    public static function cronKey(): string
    {
        $key = self::get('cron_key');
        if ($key === '') {
            $key = bin2hex(random_bytes(24));
            self::set('cron_key', $key);
        }
        return $key;
    }
}
