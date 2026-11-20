<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

$private = ['/_app', '/server', '/tests', '/docs', '/AGENTS.md', '/CLAUDE.md'];
foreach ($private as $prefix) {
    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        return true;
    }
}
if (str_starts_with($path, '/.') && !str_starts_with($path, '/.well-known/')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    return true;
}

$rootReal = realpath($root);
$file = realpath($root . $path);
if ($path !== '/' && $rootReal !== false && $file !== false
    && str_starts_with($file, $rootReal . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $root . '/index.php';
