<?php
declare(strict_types=1);

namespace Eam\Support;

use Eam\Config;

final class Content
{
    public function __construct(
        private readonly string $contentDir,
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function landing(string $locale): string
    {
        return $this->render('landing', $locale);
    }

    public function footer(string $locale): string
    {
        return $this->render('footer', $locale);
    }

    private function render(string $area, string $locale): string
    {
        $file = $this->resolve($area, $locale);
        if ($file === null) {
            $this->logger->warning('Content file not found', ['area' => $area, 'locale' => $locale]);
            return '';
        }
        $html = @file_get_contents($file);
        if (!is_string($html)) {
            $this->logger->warning('Content file is not readable', ['area' => $area, 'locale' => $locale]);
            return '';
        }
        $escaped = [
            '{{site_name}}' => e((string) $this->config->get('app.name', '')),
            '{{url_registration}}' => e(url('/registration')),
            '{{url_change_email}}' => e(url('/change-email')),
            '{{url_reset_password}}' => e(url('/reset-password')),
            '{{url_delete_account}}' => e(url('/delete-account')),
            '{{year}}' => date('Y'),
        ];
        $raw = [
            '{{hosts_chips}}' => $this->hostsChips(),
            '{{hosts_list}}' => $this->hostsList(),
        ];
        return strtr(strtr($html, $escaped), $raw);
    }

    private function resolve(string $area, string $locale): ?string
    {
        $candidates = array_values(array_unique([
            $this->sanitizeLocale($locale),
            $this->sanitizeLocale((string) $this->config->get('app.default_locale', 'en')),
            'en',
        ]));
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $file = rtrim($this->contentDir, '/') . '/' . $area . '/' . $candidate . '.html';
            if (is_file($file) && is_readable($file)) {
                return $file;
            }
        }
        return null;
    }

    private function sanitizeLocale(string $locale): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/i', '', $locale);
    }

    private function hostsChips(): string
    {
        $hosts = (array) $this->config->get('registration.hosts', []);
        $items = [];
        foreach ($hosts as $host) {
            $items[] = '<span class="host-chip">' . e((string) $host) . '</span>';
        }
        return implode('', $items);
    }

    private function hostsList(): string
    {
        $hosts = (array) $this->config->get('registration.hosts', []);
        $items = [];
        foreach ($hosts as $host) {
            $items[] = '<li><code>' . e((string) $host) . '</code></li>';
        }
        return implode('', $items);
    }
}
