<?php
declare(strict_types=1);

const APP_NAME = 'RSS Motor Penedo';
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');
define('STORAGE_PATH', BASE_PATH . '/storage');
define('DB_PATH', STORAGE_PATH . '/rssmotor.sqlite');

if (!is_dir(STORAGE_PATH)) {
    mkdir(STORAGE_PATH, 0755, true);
}

ini_set('default_charset', 'UTF-8');
date_default_timezone_set('America/Maceio');

session_name('RSSMOTORSESSID');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

foreach (glob(APP_PATH . '/*.php') as $file) {
    if (basename($file) !== 'bootstrap.php') {
        require_once $file;
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function base_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($dir === '' || $dir === '.') {
        $dir = '';
    }
    return $scheme . '://' . $host . $dir . '/' . ltrim($path, '/');
}

function redirect_to(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Token de segurança inválido. Recarregue a página e tente novamente.');
    }
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function installed(): bool
{
    return is_file(DB_PATH) && Database::hasUser();
}
