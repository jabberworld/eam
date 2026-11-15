<?php
declare(strict_types=1);

use Eam\Bootstrap;

require __DIR__ . '/../src/autoload.php';

Bootstrap::run(dirname(__DIR__), [
    dirname(__DIR__) . '/config/config.php',
]);
