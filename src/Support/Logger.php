<?php
declare(strict_types=1);

namespace Eam\Support;

use Eam\Config;

final class Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(
        private readonly string $path,
        private readonly string $minLevel = 'warning',
        private readonly bool $enabled = true
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            (string) $config->get('logging.path', sys_get_temp_dir() . '/eam.log'),
            (string) $config->get('logging.level', 'warning'),
            (bool) $config->get('logging.enabled', true)
        );
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }
        $threshold = self::LEVELS[$this->minLevel] ?? 2;
        if ((self::LEVELS[$level] ?? 1) < $threshold) {
            return;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        $line = json_encode([
            'time' => date('c'),
            'level' => $level,
            'message' => $message,
            'context' => $this->redact($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($line)) {
            @file_put_contents($this->path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }

    private function redact(array $context): array
    {
        $secretKeys = ['password', 'newpass', 'secret', 'token', 'authorization', 'cookie', 'oauth_token'];
        $result = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $secretKeys, true)) {
                $result[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }
            if ($lower === 'email' && is_string($value)) {
                $result[$key] = mask_email($value);
                continue;
            }
            if ($lower === 'element' && is_string($value)) {
                $result[$key] = '[redacted-xml]';
                continue;
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
