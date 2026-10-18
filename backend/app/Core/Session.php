<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Config;

require_once __DIR__ . '/../Config/Config.php';

class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;
            return;
        }

        $isSecure = Config::get('APP_ENV', 'development') === 'production'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $lifetime = max(300, Config::getInt('SESSION_LIFETIME', 7200));
        $sameSite = $isSecure ? 'None' : 'Lax';

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => $sameSite
        ]);

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_start();
        self::$started = true;

        // Auto-generate CSRF token if missing
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateCsrfToken();
        }
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['csrf_token'] = self::generateCsrfToken();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$started = false;
    }

    public static function getCsrfToken(): string
    {
        self::start();
        return $_SESSION['csrf_token'] ?? '';
    }

    public static function validateCsrfToken(?string $token): bool
    {
        if (!$token) {
            return false;
        }
        self::start();
        $stored = $_SESSION['csrf_token'] ?? '';
        return hash_equals($stored, $token);
    }

    private static function generateCsrfToken(): string
    {
        $secret = (string) Config::get('CSRF_SECRET', '');
        $nonce = bin2hex(random_bytes(32));

        return $secret === '' ? $nonce : hash_hmac('sha256', $nonce, $secret);
    }
}
