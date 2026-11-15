<?php
declare(strict_types=1);

use Eam\Bootstrap;

$appDir = __DIR__ . '/_app';
$autoload = $appDir . '/src/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Application directory is missing: _app/src/autoload.php\n";
    exit;
}

require $autoload;

Bootstrap::run($appDir, [
    dirname(__DIR__) . '/config.php',
    $appDir . '/config/config.php',
]);
