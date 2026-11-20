<?php
declare(strict_types=1);

use Eam\Bootstrap;
use Eam\Config;
use Eam\Storage\StorageFactory;
use Eam\Support\Logger;

require __DIR__ . '/../_app/src/autoload.php';

$root = dirname(__DIR__);
$appDir = $root . '/_app';
if (!defined('EAM_APP_DIR')) {
    define('EAM_APP_DIR', $appDir);
}

$configFile = Bootstrap::resolveConfig([
    $root . '/config.php',
    $appDir . '/config/config.php',
]);
if ($configFile === null) {
    fwrite(STDERR, "Configuration file not found.\n");
    exit(1);
}

$config = Config::load($configFile);
$logger = Logger::fromConfig($config);
$storage = StorageFactory::create($config, $logger);
$storage->cleanup();
echo "Storage cleanup completed.\n";
