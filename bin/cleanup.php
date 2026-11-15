<?php
declare(strict_types=1);

use Eam\Config;
use Eam\Storage\StorageFactory;
use Eam\Support\Logger;

require __DIR__ . '/../src/autoload.php';

$configFile = getenv('EAM_CONFIG') ?: dirname(__DIR__) . '/config/config.php';
$config = Config::load($configFile);
$logger = Logger::fromConfig($config);
$storage = StorageFactory::create($config, $logger);
$storage->cleanup();
echo "Storage cleanup completed.\n";
