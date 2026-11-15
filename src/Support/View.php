<?php
declare(strict_types=1);

namespace Eam\Support;

use Eam\Config;
use Eam\Http\Request;
use Eam\Http\Session;
use Eam\I18n\Translator;
use RuntimeException;

final class View
{
    public function __construct(
        private readonly Translator $translator,
        private readonly Config $config,
        private readonly Request $request,
        private readonly Session $session,
        private readonly string $templateDir
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        $data += [
            'title' => (string) $this->translator->get('site.title'),
            'active' => '',
            'flash' => $this->session->pullFlash(),
        ];
        $content = $this->capture($template, $data);
        $data['content'] = $content;
        return $this->capture('layout', $data);
    }

    public function renderRaw(string $template, array $data = []): string
    {
        return $this->capture($template, $data);
    }

    public function renderEmail(string $template, array $data = []): string
    {
        $data['siteName'] = (string) $this->config->get('app.name', 'Jabber');
        $content = $this->capture('emails/' . $template, $data);
        $data['content'] = $content;
        return $this->capture('emails/layout', $data);
    }

    public function alertClass(string $status): string
    {
        return match ($status) {
            'success' => 'success',
            'blocked' => 'warning',
            'error' => 'error',
            default => 'info',
        };
    }

    private function capture(string $template, array $data): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        $t = fn (string $key, array $params = []): string|array => $this->translator->get($key, $params);
        $config = $this->config;
        $request = $this->request;
        $session = $this->session;
        $csrf = $this->session->csrfToken();
        $locale = $this->translator->locale();
        $locales = $this->translator->availableLocales();
        $translator = $this->translator;

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $file;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
