<?php
declare(strict_types=1);

use Eam\App;
use Eam\Config;
use Eam\Http\Request;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/../src/autoload.php';

$configFile = getenv('EAM_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Configuration file not found.\nCopy config/config.example.php to config/config.php and fill it in.\n";
    exit;
}

try {
    $config = Config::load($configFile);
    date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
    mb_internal_encoding('UTF-8');

    $request = Request::fromGlobals($config->basePath());
    (new App($config, $request))->run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    if (getenv('EAM_DEBUG') === '1') {
        echo 'Application error: ' . $e->getMessage() . "\n";
    } else {
        echo "Internal Server Error\n";
    }
}
