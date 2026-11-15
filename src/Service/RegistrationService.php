<?php
declare(strict_types=1);

namespace Eam\Service;

use Eam\Ejabberd\ApiException;
use Eam\Validation\Validator;

final class RegistrationService extends AbstractService
{
    private const ACTIVATION_REASON = 'Activation required';

    public function formData(): array
    {
        return [
            'hosts' => (array) $this->config->get('registration.hosts', []),
            'two_step' => (bool) $this->config->get('registration.two_step', true),
        ];
    }

    public function activate(string $code, string $ip): Result
    {
        $payload = $this->storage->peekToken('registration', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        $user = (string) ($payload['username'] ?? '');
        $host = (string) ($payload['host'] ?? '');
        if ($user === '' || $host === '') {
            return Result::blocked($this->t('error.token_invalid'));
        }
        try {
            $reason = $this->client->getBanReason($user, $host);
            if ($reason === self::ACTIVATION_REASON) {
                $this->client->unbanAccount($user, $host);
                $this->storage->consumeToken('registration', $code);
                $this->welcomeMessage($user, $host);
                if ((bool) $this->config->get('registration.watch.activation', false)) {
                    $this->notifyWatchers(
                        $host,
                        (array) $this->config->get('registration.watchers', []),
                        sprintf('[%s] Account %s@%s was activated from IP address %s', date('Y-m-d H:i:s'), $user, $host, $ip)
                    );
                }
                return Result::success($this->t('message.registration.activated', [':jid' => $user . '@' . $host]));
            }
            if ($reason === null) {
                $this->storage->consumeToken('registration', $code);
                return Result::success($this->t('message.registration.already_activated', [':jid' => $user . '@' . $host]));
            }
            return Result::blocked($this->t('error.account_blocked'));
        } catch (ApiException $e) {
            $this->logger->error('Registration activation failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }
    }

    public function submit(array $input, string $ip): Result
    {
        if (!(bool) $this->config->get('registration.enabled', true)) {
            return Result::blocked($this->t('error.disabled.registration'));
        }
        $limit = $this->rateLimit('registration:ip:' . $ip, 'registration');
        if ($limit['limited']) {
            return Result::blocked($this->t('error.rate_limited'));
        }

        $username = Validator::normalizeUsername((string) ($input['username'] ?? ''));
        $host = strtolower(trim((string) ($input['host'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $email = trim((string) ($input['email'] ?? ''));

        $codes = [];
        $usernameError = Validator::validateUsername($username, $this->config);
        if ($usernameError !== null) {
            $codes['username'] = $usernameError;
        }
        if (!Validator::hostAllowed($host, (array) $this->config->get('registration.hosts', []))) {
            $codes['host'] = 'host_invalid';
        }
        $passwordError = Validator::validatePassword($password, $this->config);
        if ($passwordError !== null) {
            $codes['password'] = $passwordError;
        }
        $emailError = Validator::validateEmail($email, $this->config, $this->disposable);
        if ($emailError !== null) {
            $codes['email'] = $emailError;
        }
        if (!$this->captchaOk($input, $ip)) {
            $codes['captcha'] = 'captcha';
        }
        if ($codes !== []) {
            return Result::blocked($this->t('error.fix_fields'), $this->mapErrors($codes));
        }

        try {
            if ($this->client->checkAccount($username, $host)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'username' => $this->t('error.username_taken'),
                ]);
            }
        } catch (ApiException $e) {
            $this->logger->error('check_account failed during registration', ['status' => $e->status]);
            return Result::error($this->t('error.server'));
        }

        try {
            $this->client->register($username, $host, $password);
        } catch (ApiException $e) {
            if ($this->isConflict($e)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'username' => $this->t('error.username_taken'),
                ]);
            }
            $this->logger->error('register failed', ['status' => $e->status, 'body' => $e->body]);
            return Result::error($this->t('error.server'));
        }

        try {
            $this->client->privateSetEmail($username, $host, $email);
        } catch (ApiException $e) {
            $this->safeUnregister($username, $host);
            $this->logger->error('private_set failed during registration', ['status' => $e->status]);
            return Result::error($this->t('error.server'));
        }

        if (!(bool) $this->config->get('registration.two_step', true)) {
            $this->welcomeMessage($username, $host);
            $this->notifyRegistration($username, $host, $email, $ip);
            $this->hitRate($limit['key'], $limit['window']);
            return Result::success($this->t('message.registration.success', [':jid' => $username . '@' . $host]));
        }

        try {
            $this->client->banAccount($username, $host, self::ACTIVATION_REASON);
        } catch (ApiException $e) {
            $this->safeUnregister($username, $host);
            $this->logger->error('ban_account failed during registration', ['status' => $e->status]);
            return Result::error($this->t('error.server'));
        }

        $ttl = max(60, (int) $this->config->get('registration.activation_ttl', 3600));
        $token = $this->storage->createToken('registration', [
            'username' => $username,
            'host' => $host,
            'email' => $email,
            'locale' => $this->translator->locale(),
            'created_at' => time(),
        ], $ttl, $ip);
        $link = url('/registration', ['code' => $token]);
        $data = [
            'name' => $this->displayName($username, $host),
            'username' => $username,
            'jid' => $username . '@' . $host,
            'host' => $host,
            'email' => $email,
            'link' => $link,
            'ttl_minutes' => (int) ceil($ttl / 60),
        ];
        try {
            $this->sendEmail('registration', $email, $data, [':host' => $host]);
        } catch (\Throwable $e) {
            $this->safeUnregister($username, $host);
            $this->logger->error('Activation email failed', ['user' => $username, 'host' => $host, 'error' => $e->getMessage()]);
            return Result::error($this->t('error.email_failed'));
        }

        $this->notifyRegistration($username, $host, $email, $ip);
        $this->hitRate($limit['key'], $limit['window']);
        return Result::success($this->t('message.registration.email_sent'));
    }

    private function notifyRegistration(string $username, string $host, string $email, string $ip): void
    {
        if (!(bool) $this->config->get('registration.watch.registration', false)) {
            return;
        }
        $this->notifyWatchers(
            $host,
            (array) $this->config->get('registration.watchers', []),
            sprintf(
                '[%s] The account %s@%s with the email address %s was registered from IP address %s',
                date('Y-m-d H:i:s'),
                $username,
                $host,
                $email,
                $ip
            )
        );
    }

    private function isConflict(ApiException $e): bool
    {
        $body = strtolower($e->body);
        return $e->status === 409
            || str_contains($body, 'conflict')
            || str_contains($body, 'already')
            || str_contains($body, 'registered');
    }
}
