<?php
declare(strict_types=1);

namespace Eam\Validation;

use Eam\Config;
use Eam\Support\Logger;

final class DisposableEmailChecker
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly string $cacheDir
    ) {
    }

    public function isDisposable(string $email): bool
    {
        if (!(bool) $this->config->get('validation.block_disposable', false)) {
            return false;
        }
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        if ($domain === '' || filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return false;
        }
        $cached = $this->readCache($domain);
        if ($cached !== null) {
            return $cached;
        }
        $url = rtrim((string) $this->config->get('validation.usercheck_url', 'https://api.usercheck.com/domain/'), '/') . '/' . rawurlencode($domain);
        $timeout = (int) $this->config->get('validation.timeout', 5);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'EAM/1.0',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            $this->logger->warning('Disposable email check failed', [
                'domain' => $domain,
                'status' => $status,
                'error' => $error,
            ]);
            return false;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('disposable', $data)) {
            return false;
        }
        $disposable = (bool) $data['disposable'];
        $this->writeCache($domain, $disposable);
        return $disposable;
    }

    private function readCache(string $domain): ?bool
    {
        $file = $this->cacheFile($domain);
        if (!is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || (int) ($data['expires'] ?? 0) < time()) {
            return null;
        }
        return isset($data['disposable']) ? (bool) $data['disposable'] : null;
    }

    private function writeCache(string $domain, bool $disposable): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0770, true);
        }
        $ttl = (int) $this->config->get('validation.cache_ttl', 86400);
        $payload = json_encode([
            'disposable' => $disposable,
            'expires' => time() + $ttl,
        ]);
        if (is_string($payload)) {
            @file_put_contents($this->cacheFile($domain), $payload, LOCK_EX);
        }
    }

    private function cacheFile(string $domain): string
    {
        return rtrim($this->cacheDir, '/') . '/' . hash('sha256', $domain) . '.json';
    }
}
