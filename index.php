<?php
declare(strict_types=1);

use Eam\Bootstrap;

require __DIR__ . '/_app/src/autoload.php';

Bootstrap::run(__DIR__ . '/_app', [
    dirname(__DIR__) . '/config.php',
    __DIR__ . '/_app/config/config.php',
]);
