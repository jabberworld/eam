<?php
declare(strict_types=1);

namespace Eam\Storage;

use Eam\Support\Logger;
use RuntimeException;

final class FileStorage implements StorageInterface
{
    private readonly string $tokensDir;
    private readonly string $rateDir;

    public function __construct(
        string $baseDir,
        private readonly string $secret,
        private readonly Logger $logger
    ) {
        $this->tokensDir = rtrim($baseDir, '/') . '/tokens';
        $this->rateDir = rtrim($baseDir, '/') . '/rate';
        foreach ([$baseDir, $this->tokensDir, $this->rateDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
                throw new RuntimeException('Cannot create storage directory: ' . $dir);
            }
        }
    }

    public function createToken(string $kind, array $payload, int $ttl, ?string $ip = null): string
    {
        $raw = bin2hex(random_bytes(32));
        $now = time();
        $data = [
            'payload' => $payload,
            'ip' => $ip,
            'created_at' => $now,
            'expires_at' => $now + $ttl,
            'used_at' => null,
        ];
        $this->writeJson($this->tokenFile($kind, $raw), $data);
        return $raw;
    }

    public function peekToken(string $kind, string $token): ?array
    {
        $data = $this->readJson($this->tokenFile($kind, $token));
        if ($data === null || ($data['used_at'] ?? null) !== null || (int) ($data['expires_at'] ?? 0) <= time()) {
            return null;
        }
        return is_array($data['payload'] ?? null) ? $data['payload'] : null;
    }

    public function consumeToken(string $kind, string $token): ?array
    {
        $file = $this->tokenFile($kind, $token);
        if (!is_file($file)) {
            return null;
        }
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            return null;
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                return null;
            }
            $raw = stream_get_contents($fp);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data) || ($data['used_at'] ?? null) !== null || (int) ($data['expires_at'] ?? 0) <= time()) {
                return null;
            }
            $data['used_at'] = time();
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                return null;
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
            return is_array($data['payload'] ?? null) ? $data['payload'] : null;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function isRateLimited(string $key, int $limit): bool
    {
        $data = $this->readJson($this->rateFile($key));
        if ($data === null || (int) ($data['expires_at'] ?? 0) <= time()) {
            return false;
        }
        return (int) ($data['count'] ?? 0) >= $limit;
    }

    public function hitRate(string $key, int $window): void
    {
        $file = $this->rateFile($key);
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            throw new RuntimeException('Cannot open rate-limit file.');
        }
        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RuntimeException('Cannot lock rate-limit file.');
            }
            $raw = stream_get_contents($fp);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            $now = time();
            if (!is_array($data) || (int) ($data['expires_at'] ?? 0) <= $now) {
                $data = ['count' => 1, 'expires_at' => $now + $window];
            } else {
                $data['count'] = (int) ($data['count'] ?? 0) + 1;
            }
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json)) {
                throw new RuntimeException('Cannot encode rate-limit data.');
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, $json);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public function clearRate(string $key): void
    {
        $file = $this->rateFile($key);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public function cleanup(): void
    {
        $now = time();
        $limit = $now - 86400;
        foreach (glob($this->tokensDir . '/*/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null) {
                @unlink($file);
                continue;
            }
            $expires = (int) ($data['expires_at'] ?? 0);
            $used = $data['used_at'] ?? null;
            if ($expires < $limit || (is_int($used) && $used < $limit)) {
                @unlink($file);
            }
        }
        foreach (glob($this->rateDir . '/*.json') ?: [] as $file) {
            $data = $this->readJson($file);
            if ($data === null || (int) ($data['expires_at'] ?? 0) < $now) {
                @unlink($file);
            }
        }
    }

    private function tokenFile(string $kind, string $token): string
    {
        $kind = preg_replace('/[^a-z0-9_-]/i', '_', $kind) ?: 'other';
        $dir = $this->tokensDir . '/' . $kind;
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        return $dir . '/' . hash_hmac('sha256', $token, $this->secret) . '.json';
    }

    private function rateFile(string $key): string
    {
        return $this->rateDir . '/' . hash('sha256', $key) . '.json';
    }

    private function readJson(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function writeJson(string $file, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Cannot encode storage data.');
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create storage directory: ' . $dir);
        }
        if (@file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('Cannot write storage file.');
        }
    }
}
