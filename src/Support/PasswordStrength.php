<?php
declare(strict_types=1);

namespace Eam\Support;

final class PasswordStrength
{
    private const COMMON = [
        'password', 'password1', 'password123', '12345678', '123456789', 'qwerty',
        'qwerty123', 'admin', 'admin123', 'letmein', 'welcome', 'monkey', 'dragon',
        'iloveyou', 'sunshine', 'princess', 'football', 'baseball', 'abc123',
        'qwertyuiop', '11111111', '00000000', 'passw0rd', 'p@ssw0rd',
    ];

    public static function score(string $password): int
    {
        $length = strlen($password);
        if ($length === 0) {
            return 0;
        }
        $lower = strtolower($password);
        if (in_array($lower, self::COMMON, true)) {
            return 0;
        }
        if (preg_match('/^(.)\1+$/', $password) === 1) {
            return 0;
        }
        if (preg_match('/^(?:0123|1234|2345|3456|4567|5678|6789|abcd|bcde|cdef|qwer|asdf|zxcv)/i', $password) === 1) {
            return 0;
        }

        $pool = 0;
        if (preg_match('/[a-z]/', $password)) {
            $pool += 26;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $pool += 26;
        }
        if (preg_match('/[0-9]/', $password)) {
            $pool += 10;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $pool += 33;
        }
        if ($pool === 0) {
            $pool = 1;
        }
        $entropy = $length * log($pool, 2);

        $repeats = preg_match_all('/(.)\1{2,}/', $password);
        if ($repeats !== false && $repeats > 0) {
            $entropy -= 8 * $repeats;
        }
        if (preg_match('/^(?:\d+|[a-zA-Z]+)$/', $password) === 1) {
            $entropy -= 8;
        }

        if ($entropy < 28) {
            return 0;
        }
        if ($entropy < 36) {
            return 1;
        }
        if ($entropy < 60) {
            return 2;
        }
        if ($entropy < 80) {
            return 3;
        }
        return 4;
    }

    public static function labelKey(int $score): string
    {
        return 'hint.password_strength.' . max(0, min(4, $score));
    }
}
