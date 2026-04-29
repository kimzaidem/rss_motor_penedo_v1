<?php
declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function require(): void
    {
        if (!self::check()) {
            redirect_to('index.php?page=login');
        }
    }

    public static function login(string $email, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function create(string $name, string $email, string $password): void
    {
        $stmt = Database::pdo()->prepare('INSERT INTO users(name, email, password_hash, created_at, updated_at) VALUES(?, ?, ?, ?, ?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), now(), now()]);
    }
}
