<?php
declare(strict_types=1);

namespace Eam\Service;

use Eam\Captcha\CaptchaInterface;
use Eam\Config;
use Eam\Ejabberd\Client;
use Eam\I18n\Translator;
use Eam\Mail\MailerInterface;
use Eam\Storage\StorageInterface;
use Eam\Support\Logger;
use Eam\Support\View;
use Eam\Validation\DisposableEmailChecker;

abstract class AbstractService
{
    public function __construct(
        protected readonly Config $config,
        protected readonly Translator $translator,
        protected readonly Client $client,
        protected readonly StorageInterface $storage,
        protected readonly MailerInterface $mailer,
        protected readonly CaptchaInterface $captcha,
        protected readonly View $view,
        protected readonly Logger $logger,
        protected readonly DisposableEmailChecker $disposable
    ) {
    }

    protected function t(string $key, array $params = []): string
    {
        $value = $this->translator->get($key, $params);
        return is_string($value) ? $value : $key;
    }

    protected function mapErrors(array $codes): array
    {
        $result = [];
        foreach ($codes as $field => $code) {
            $result[$field] = $this->t('error.' . $code);
        }
        return $result;
    }

    protected function rateLimit(string $key, string $configPrefix): array
    {
        $settings = $this->config->get($configPrefix . '.rate_limit', ['count' => 10, 'window' => 3600]);
        $count = max(1, (int) ($settings['count'] ?? 10));
        $window = max(1, (int) ($settings['window'] ?? 3600));
        return ['key' => $key, 'count' => $count, 'window' => $window, 'limited' => $this->storage->isRateLimited($key, $count)];
    }

    protected function hitRate(string $key, int $window): void
    {
        try {
            $this->storage->hitRate($key, $window);
        } catch (\Throwable $e) {
            $this->logger->warning('Rate-limit write failed', ['error' => $e->getMessage()]);
        }
    }

    protected function captchaOk(array $input, string $ip): bool
    {
        try {
            return $this->captcha->verify($input, $ip);
        } catch (\Throwable $e) {
            $this->logger->warning('Captcha verification failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function sendEmail(string $template, string $to, array $data, array $subjectParams = []): void
    {
        $subject = $this->t('email.' . $template . '.subject', $subjectParams);
        $html = $this->view->renderEmail($template, $data);
        $this->mailer->send($to, $subject, $html);
    }

    protected function notifyWatchers(string $from, array $watchers, string $text): void
    {
        foreach ($watchers as $watcher) {
            if (!is_string($watcher) || $watcher === '') {
                continue;
            }
            try {
                $this->client->sendMessage($from, $watcher, '', $text, 'chat');
            } catch (\Throwable $e) {
                $this->logger->warning('Watcher notification failed', [
                    'from' => $from,
                    'to' => $watcher,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    protected function welcomeMessage(string $user, string $host): void
    {
        if (!(bool) $this->config->get('registration.welcome.enabled', false)) {
            return;
        }
        $body = (string) $this->config->get('registration.welcome.body', '');
        $subject = (string) $this->config->get('registration.welcome.subject', '');
        if ($body === '') {
            return;
        }
        $replace = [
            '{jid}' => $user . '@' . $host,
            '{username}' => $user,
            '{host}' => $host,
            '{site}' => (string) $this->config->get('app.name', ''),
        ];
        $body = strtr($body, $replace);
        $subject = strtr($subject, $replace);
        try {
            $this->client->sendMessage($host, $user . '@' . $host, $subject, $body, $subject === '' ? 'chat' : 'normal');
        } catch (\Throwable $e) {
            $this->logger->warning('Welcome message failed', [
                'user' => $user,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function displayName(string $user, string $host): string
    {
        try {
            return $this->client->getVcardName($user, $host) ?? $user;
        } catch (\Throwable $e) {
            return $user;
        }
    }

    protected function safeUnregister(string $user, string $host): void
    {
        try {
            $this->client->unregister($user, $host);
        } catch (\Throwable $e) {
            $this->logger->error('Rollback unregister failed', [
                'user' => $user,
                'host' => $host,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
