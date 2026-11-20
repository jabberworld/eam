<?php
declare(strict_types=1);

namespace Eam\Http;

use Eam\Config;
use RuntimeException;

final class Session
{
    public function __construct(private readonly Config $config)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $savePath = (string) $this->config->get('storage.sessions_path', sys_get_temp_dir());
        if (!is_dir($savePath) && !@mkdir($savePath, 0770, true) && !is_dir($savePath)) {
            throw new RuntimeException('Cannot create session directory: ' . $savePath);
        }
        if (!is_writable($savePath)) {
            throw new RuntimeException('Session directory is not writable: ' . $savePath);
        }
        session_save_path($savePath);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', (string) $this->config->get('app.session_samesite', 'Lax'));

        $secure = $this->config->get('app.session_secure');
        if ($secure === null) {
            $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
            $secure = $https !== '' && $https !== 'off';
        }
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => (bool) $secure,
            'httponly' => true,
            'samesite' => (string) $this->config->get('app.session_samesite', 'Lax'),
        ]);
        session_name((string) $this->config->get('app.session_cookie', 'eam_session'));
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function csrfToken(): string
    {
        $token = $_SESSION['_csrf'] ?? null;
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $_SESSION['_csrf'] = $token;
        }
        return $token;
    }

    public function verifyCsrf(mixed $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? null;
        return is_string($token) && is_string($expected) && hash_equals($expected, $token);
    }

    public function flash(string $type, string $message): void
    {
        $_SESSION['_flash'][$type] = $message;
    }

    public function pullFlash(): array
    {
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return is_array($flash) ? $flash : [];
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
