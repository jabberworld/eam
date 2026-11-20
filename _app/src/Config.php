<?php
declare(strict_types=1);

namespace Eam;

use RuntimeException;

final class Config
{
    private static ?self $instance = null;

    private function __construct(private readonly array $data)
    {
    }

    public static function load(string $file): self
    {
        if (!is_file($file)) {
            throw new RuntimeException('Config file not found: ' . $file);
        }
        $data = require $file;
        if (!is_array($data)) {
            throw new RuntimeException('Config file must return an array.');
        }
        $config = new self($data);
        $config->validate();
        self::$instance = $config;
        return $config;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Config is not loaded.');
        }
        return self::$instance;
    }

    public function all(): array
    {
        return $this->data;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $value = $this->data;
        foreach (explode('.', $path) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public function has(string $path): bool
    {
        $sentinel = new \stdClass();
        return $this->get($path, $sentinel) !== $sentinel;
    }

    public function basePath(): string
    {
        $path = parse_url((string) $this->get('app.base_url', ''), PHP_URL_PATH) ?? '';
        $path = '/' . trim($path, '/');
        return $path === '/' ? '' : $path;
    }

    public function isDebug(): bool
    {
        return (bool) $this->get('app.debug', false);
    }

    private function validate(): void
    {
        $required = [
            'app.base_url',
            'app.secret',
            'app.default_locale',
            'app.locales',
            'ejabberd.rest_url',
            'registration.hosts',
            'mail.from_email',
        ];
        foreach ($required as $key) {
            if (!$this->has($key) || $this->get($key) === '') {
                throw new RuntimeException('Missing required config key: ' . $key);
            }
        }
        $secret = (string) $this->get('app.secret');
        if (strlen($secret) < 16 || str_contains($secret, 'CHANGE_ME')) {
            throw new RuntimeException('app.secret must be a random string of at least 16 characters.');
        }
        $locales = $this->get('app.locales');
        if (!is_array($locales) || $locales === []) {
            throw new RuntimeException('app.locales must be a non-empty array.');
        }
        if (!in_array($this->get('app.default_locale'), $locales, true)) {
            throw new RuntimeException('app.default_locale must be present in app.locales.');
        }
    }
}
