<?php
declare(strict_types=1);

namespace Eam;

use Eam\Http\Request;
use Throwable;

final class Bootstrap
{
    /**
     * @param array<int, string> $configCandidates Ordered config paths, first existing wins.
     */
    public static function run(string $appDir, array $configCandidates): void
    {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $appDir = rtrim($appDir, '/\\');
        if (!defined('EAM_APP_DIR')) {
            define('EAM_APP_DIR', $appDir);
        }

        $configFile = self::resolveConfig($configCandidates);
        if ($configFile === null) {
            self::fail(
                "Configuration file not found.\n"
                . "Place config.php inside _app/config/ or one level above the web root.\n"
                . "For the standard layout use config/config.php.\n"
                . "Expected candidates:\n- " . implode("\n- ", $configCandidates),
                500
            );
            return;
        }

        try {
            $config = Config::load($configFile);
            date_default_timezone_set((string) $config->get('app.timezone', 'UTC'));
            mb_internal_encoding('UTF-8');

            $request = Request::fromGlobals($config->basePath());
            (new App($config, $request))->run();
        } catch (Throwable $e) {
            error_log('EAM bootstrap error: ' . $e->getMessage());
            $message = getenv('EAM_DEBUG') === '1'
                ? 'Application error: ' . $e->getMessage()
                : 'Internal Server Error';
            self::fail($message, 500);
        }
    }

    /**
     * Resolve the config path. EAM_CONFIG wins; then the provided candidates in order.
     *
     * @param array<int, string> $candidates
     */
    public static function resolveConfig(array $candidates): ?string
    {
        $env = getenv('EAM_CONFIG');
        if (is_string($env) && $env !== '' && @is_file($env) && @is_readable($env)) {
            return $env;
        }
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            if (@is_file($candidate) && @is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function fail(string $message, int $status): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $message, "\n";
    }
}
