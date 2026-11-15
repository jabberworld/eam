<?php
declare(strict_types=1);

namespace Eam\I18n;

use Eam\Config;
use Eam\Http\Request;

final class Translator
{
    /** @var array<string, array<string, mixed>> */
    private array $dictionaries = [];

    public function __construct(
        private readonly string $langDir,
        private readonly string $locale,
        private readonly array $locales,
        private readonly string $fallback = 'en'
    ) {
    }

    public static function negotiate(Request $request, Config $config): string
    {
        $locales = (array) $config->get('app.locales', ['en']);
        $candidate = (string) $request->query('lang', '');
        if ($candidate !== '' && in_array($candidate, $locales, true)) {
            return $candidate;
        }
        $cookie = (string) $request->cookie('eam_lang', '');
        if ($cookie !== '' && in_array($cookie, $locales, true)) {
            return $cookie;
        }
        $accept = (string) $request->header('accept-language', '');
        foreach (explode(',', $accept) as $part) {
            $lang = strtolower(trim(explode(';', $part)[0]));
            $short = substr($lang, 0, 2);
            foreach ($locales as $available) {
                if ($lang === $available || $short === $available) {
                    return $available;
                }
            }
        }
        $default = (string) $config->get('app.default_locale', 'en');
        return in_array($default, $locales, true) ? $default : (string) ($locales[0] ?? 'en');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function availableLocales(): array
    {
        return $this->locales;
    }

    public function get(string $key, array $params = []): string|array
    {
        $value = $this->lookup($this->locale, $key);
        if ($value === null && $this->locale !== $this->fallback) {
            $value = $this->lookup($this->fallback, $key);
        }
        if ($value === null) {
            return $key;
        }
        if (is_array($value)) {
            return $value;
        }
        $value = (string) $value;
        if ($params !== []) {
            $replace = [];
            foreach ($params as $name => $replacement) {
                $replace[':' . ltrim((string) $name, ':')] = (string) $replacement;
            }
            $value = strtr($value, $replace);
        }
        return $value;
    }

    public function has(string $key): bool
    {
        return $this->lookup($this->locale, $key) !== null || $this->lookup($this->fallback, $key) !== null;
    }

    private function lookup(string $locale, string $key): mixed
    {
        $dictionary = $this->dictionary($locale);
        $value = $dictionary;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private function dictionary(string $locale): array
    {
        if (isset($this->dictionaries[$locale])) {
            return $this->dictionaries[$locale];
        }
        $safe = preg_replace('/[^a-z_\-]/i', '', $locale) ?: $this->fallback;
        $file = $this->langDir . '/' . $safe . '.php';
        if (!is_file($file)) {
            $this->dictionaries[$locale] = [];
            return [];
        }
        $data = require $file;
        $this->dictionaries[$locale] = is_array($data) ? $data : [];
        return $this->dictionaries[$locale];
    }
}
