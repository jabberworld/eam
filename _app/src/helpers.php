<?php
declare(strict_types=1);

use Eam\Config;

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::instance()->get($key, $default);
}

function url(string $path = '', array $query = []): string
{
    $base = rtrim((string) Config::instance()->get('app.base_url', ''), '/');
    if ($path !== '' && !str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    $url = $base . $path;
    if ($query !== []) {
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    return $url;
}

function asset(string $path): string
{
    $url = url('/assets/' . ltrim($path, '/'));
    $version = (string) Config::instance()->get('app.asset_version', '1');
    if ($version !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }
    return $url;
}

function mask_email(string $email): string
{
    if (!str_contains($email, '@')) {
        return $email;
    }
    [$user, $domain] = explode('@', $email, 2);
    $len = strlen($user);
    if ($len <= 2) {
        return str_repeat('*', max(1, $len)) . '@' . $domain;
    }
    $maskCount = max(1, (int) floor($len * 0.8));
    $offset = (int) floor(($len - $maskCount) / 2);
    return substr($user, 0, $offset) . str_repeat('*', $maskCount) . substr($user, $maskCount + $offset) . '@' . $domain;
}

function now(): int
{
    return time();
}
