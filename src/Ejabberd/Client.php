<?php
declare(strict_types=1);

namespace Eam\Ejabberd;

use DOMDocument;
use Eam\Config;
use Eam\Support\Logger;

final class Client
{
    private readonly string $baseUrl;
    private readonly string $login;
    private readonly string $password;
    private readonly string $oauthToken;
    private readonly bool $verifyTls;
    private readonly int $timeout;
    private readonly int $retries;
    private readonly Logger $logger;
    /** @var null|array<int, string> */
    private ?array $vhostsCache = null;

    public function __construct(Config $config, Logger $logger)
    {
        $this->baseUrl = rtrim((string) $config->get('ejabberd.rest_url'), '/');
        $this->login = (string) $config->get('ejabberd.login', '');
        $this->password = (string) $config->get('ejabberd.password', '');
        $this->oauthToken = (string) $config->get('ejabberd.oauth_token', '');
        $this->verifyTls = (bool) $config->get('ejabberd.verify_tls', true);
        $this->timeout = max(1, (int) $config->get('ejabberd.timeout', 15));
        $this->retries = max(0, (int) $config->get('ejabberd.retries', 1));
        $this->logger = $logger;
    }

    public function call(string $command, array $arguments = []): mixed
    {
        $url = $this->baseUrl . '/' . ltrim($command, '/');
        $body = json_encode((object) $arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->oauthToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->oauthToken;
        } elseif ($this->login !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->login . ':' . $this->password);
        }

        $attempt = 0;
        do {
            $attempt++;
            $started = microtime(true);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => $this->verifyTls,
                CURLOPT_SSL_VERIFYHOST => $this->verifyTls ? 2 : 0,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            $duration = (int) round((microtime(true) - $started) * 1000);
            $this->logger->debug('ejabberd API call', [
                'command' => $command,
                'status' => $status,
                'duration_ms' => $duration,
                'attempt' => $attempt,
                'arguments' => $this->sanitize($arguments),
            ]);

            if (is_string($response) && $status !== 0) {
                if ($status >= 200 && $status < 300) {
                    return $this->decode($response);
                }
                throw new ApiException($command, $status, $response, $this->sanitize($arguments));
            }
            if ($attempt > $this->retries) {
                throw new ApiException($command, 0, $error !== '' ? $error : 'Connection failed', $this->sanitize($arguments));
            }
        } while ($attempt <= $this->retries);

        throw new ApiException($command, 0, 'Connection failed', $this->sanitize($arguments));
    }

    public function checkAccount(string $user, string $host): bool
    {
        return $this->isZero($this->call('check_account', ['user' => $user, 'host' => $host]));
    }

    public function checkPassword(string $user, string $host, string $password): bool
    {
        return $this->isZero($this->call('check_password', [
            'user' => $user,
            'host' => $host,
            'password' => $password,
        ]));
    }

    /** @return array<int, string> */
    public function registeredVhosts(): array
    {
        if ($this->vhostsCache !== null) {
            return $this->vhostsCache;
        }
        $result = $this->call('registered_vhosts');
        $vhosts = is_array($result) ? array_values(array_filter($result, 'is_string')) : [];
        $this->vhostsCache = $vhosts;
        return $vhosts;
    }

    public function register(string $user, string $host, string $password): void
    {
        $result = $this->call('register', ['user' => $user, 'host' => $host, 'password' => $password]);
        if (is_int($result) && $result !== 0) {
            throw new ApiException('register', 200, 'register failed', ['user' => $user, 'host' => $host]);
        }
        if (is_string($result) && trim($result) !== '' && !str_contains(strtolower($result), 'success')) {
            throw new ApiException('register', 200, $result, ['user' => $user, 'host' => $host]);
        }
    }

    public function unregister(string $user, string $host): void
    {
        $this->assertZero('unregister', $this->call('unregister', ['user' => $user, 'host' => $host]));
    }

    public function changePassword(string $user, string $host, string $newPassword): void
    {
        $this->assertZero('change_password', $this->call('change_password', [
            'user' => $user,
            'host' => $host,
            'newpass' => $newPassword,
        ]));
    }

    public function privateSetEmail(string $user, string $host, string $email): void
    {
        $this->assertZero('private_set', $this->call('private_set', [
            'user' => $user,
            'host' => $host,
            'element' => "<private xmlns='email'>" . htmlspecialchars($email, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</private>',
        ]));
    }

    public function privateGetEmail(string $user, string $host): ?string
    {
        $result = $this->call('private_get', [
            'user' => $user,
            'host' => $host,
            'element' => 'private',
            'ns' => 'email',
        ]);
        $xml = is_string($result) ? $result : (is_array($result) ? (string) ($result['xml'] ?? '') : '');
        if ($xml === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        foreach ($dom->getElementsByTagName('private') as $node) {
            if ($node->getAttribute('xmlns') === 'email') {
                $value = trim($node->textContent);
                return $value !== '' ? $value : null;
            }
        }
        return null;
    }

    public function banAccount(string $user, string $host, string $reason): void
    {
        $this->assertZero('ban_account', $this->call('ban_account', [
            'user' => $user,
            'host' => $host,
            'reason' => $reason,
        ]));
    }

    public function unbanAccount(string $user, string $host): void
    {
        $this->assertZero('unban_account', $this->call('unban_account', ['user' => $user, 'host' => $host]));
    }

    public function getBanReason(string $user, string $host): ?string
    {
        $result = $this->call('get_ban_details', ['user' => $user, 'host' => $host]);
        if (is_array($result) && isset($result['reason']) && is_scalar($result['reason'])) {
            $reason = trim((string) $result['reason']);
            return $reason !== '' ? $reason : null;
        }
        return null;
    }

    public function kickUser(string $user, string $host): void
    {
        $result = $this->call('kick_user', ['user' => $user, 'host' => $host]);
        if (is_int($result)) {
            $this->assertZero('kick_user', $result);
        }
    }

    public function unbanIp(string $address): void
    {
        $this->assertZero('unban_ip', $this->call('unban_ip', ['address' => $address]));
    }

    public function sendMessage(string $from, string $to, string $subject, string $body, string $type = 'chat'): void
    {
        $this->assertZero('send_message', $this->call('send_message', [
            'type' => $type,
            'from' => $from,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ]));
    }

    public function getVcardName(string $user, string $host): ?string
    {
        foreach (['NICKNAME', 'FN'] as $field) {
            try {
                $result = $this->call('get_vcard', ['user' => $user, 'host' => $host, 'name' => $field]);
                if (is_string($result)) {
                    $value = trim($result);
                    if ($value === '' || $value === 'error_no_vcard_found') {
                        continue;
                    }
                    $decoded = json_decode($value, true);
                    if (is_string($decoded) && $decoded !== '') {
                        return $decoded;
                    }
                    return $value;
                }
            } catch (ApiException $e) {
                if ($e->isKnownVcardMissing()) {
                    continue;
                }
                $this->logger->warning('get_vcard failed', [
                    'user' => $user,
                    'host' => $host,
                    'status' => $e->status,
                ]);
                return null;
            }
        }
        return null;
    }

    private function decode(string $body): mixed
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $body;
    }

    private function isZero(mixed $value): bool
    {
        if (is_int($value)) {
            return $value === 0;
        }
        if (is_string($value)) {
            return trim($value) === '0';
        }
        return false;
    }

    private function assertZero(string $command, mixed $value): void
    {
        if (is_string($value) && trim($value) === '') {
            return;
        }
        if (!$this->isZero($value)) {
            $body = is_scalar($value) ? (string) $value : (json_encode($value) ?: 'invalid response');
            throw new ApiException($command, 200, $body);
        }
    }

    private function sanitize(array $arguments): array
    {
        foreach (['password', 'newpass', 'element', 'body', 'subject'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $arguments[$key] = '[redacted]';
            }
        }
        return $arguments;
    }
}
