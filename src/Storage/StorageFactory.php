<?php
declare(strict_types=1);

namespace Eam\Storage;

use Eam\Config;
use Eam\Support\Logger;

final class StorageFactory
{
    public static function create(Config $config, Logger $logger): StorageInterface
    {
        $driver = (string) $config->get('storage.driver', 'auto');
        $secret = (string) $config->get('app.secret');
        if ($driver === 'files') {
            return new FileStorage((string) $config->get('storage.files_path'), $secret, $logger);
        }
        if ($driver === 'sqlite' || ($driver === 'auto' && class_exists('SQLite3'))) {
            try {
                return new SqliteStorage((string) $config->get('storage.sqlite_path'), $secret, $logger);
            } catch (\Throwable $e) {
                if ($driver === 'sqlite') {
                    throw $e;
                }
                $logger->warning('SQLite storage unavailable, falling back to files', ['error' => $e->getMessage()]);
            }
        }
        return new FileStorage((string) $config->get('storage.files_path'), $secret, $logger);
    }
}
