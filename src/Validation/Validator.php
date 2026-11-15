<?php
declare(strict_types=1);

namespace Eam\Validation;

use Eam\Config;
use Eam\Support\PasswordStrength;

final class Validator
{
    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function validateUsername(string $username, Config $config): ?string
    {
        if ($username === '') {
            return 'required';
        }
        $allowed = (string) $config->get('registration.allowed_username_regexp', '');
        if ($allowed !== '') {
            $result = @preg_match('/' . str_replace('/', '\\/', $allowed) . '/i', $username);
            if ($result !== 1) {
                return 'username_invalid';
            }
        }
        $blocked = (string) $config->get('registration.blocked_username_regexp', '');
        if ($blocked !== '') {
            $result = @preg_match('/' . str_replace('/', '\\/', $blocked) . '/i', $username);
            if ($result === 1) {
                return 'username_forbidden';
            }
        }
        return null;
    }

    /**
     * @return null|array{user: string, host: string}
     */
    public static function parseJid(string $jid): ?array
    {
        $jid = trim($jid);
        if ($jid === '' || str_contains($jid, '/') || substr_count($jid, '@') !== 1) {
            return null;
        }
        [$user, $host] = explode('@', $jid, 2);
        $user = strtolower(trim($user));
        $host = strtolower(trim($host));
        if ($user === '' || $host === '' || strlen($user) > 1023 || strlen($host) > 253) {
            return null;
        }
        if (preg_match('/[\s\/@]/u', $user) === 1) {
            return null;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $user) === 1) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }
        return ['user' => $user, 'host' => $host];
    }

    public static function validateEmail(
        string $email,
        Config $config,
        DisposableEmailChecker $disposableChecker
    ): ?string {
        $email = trim($email);
        if ($email === '') {
            return 'required';
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'email_invalid';
        }
        $domain = (string) substr(strrchr($email, '@') ?: '', 1);
        if ($domain === '') {
            return 'email_invalid';
        }
        if ((bool) $config->get('validation.check_mx', true)) {
            $hasMx = @checkdnsrr($domain, 'MX');
            if (!$hasMx) {
                return 'email_invalid';
            }
        }
        if ($disposableChecker->isDisposable($email)) {
            return 'email_disposable';
        }
        return null;
    }

    public static function validatePassword(string $password, Config $config): ?string
    {
        if ($password === '') {
            return 'required';
        }
        $min = (int) $config->get('password.min_length', 8);
        $max = (int) $config->get('password.max_length', 128);
        if (strlen($password) < $min || strlen($password) > $max) {
            return 'password_weak';
        }
        $minStrength = (int) $config->get('password.min_strength', 3);
        if (PasswordStrength::score($password) < $minStrength) {
            return 'password_weak';
        }
        return null;
    }

    public static function hostAllowed(string $host, array $hosts): bool
    {
        return in_array(strtolower($host), array_map('strtolower', $hosts), true);
    }
}
