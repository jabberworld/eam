<?php
declare(strict_types=1);

namespace Eam\Service;

use Eam\Ejabberd\ApiException;
use Eam\Validation\Validator;

final class EmailChangeService extends AbstractService
{
    public function showCode(string $code): Result
    {
        $payload = $this->storage->peekToken('email_change', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        return Result::success(null, [
            'token' => $code,
            'user' => (string) ($payload['user'] ?? ''),
            'host' => (string) ($payload['host'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
        ]);
    }

    public function submitRequest(array $input, string $ip): Result
    {
        if (!(bool) $this->config->get('change_email.enabled', true)) {
            return Result::blocked($this->t('error.disabled.change_email'));
        }
        $limit = $this->rateLimit('change_email:ip:' . $ip, 'change_email');
        if ($limit['limited']) {
            return Result::blocked($this->t('error.rate_limited'));
        }

        $login = strtolower(trim((string) ($input['login'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $email = trim((string) ($input['email'] ?? ''));
        $codes = [];

        $jid = Validator::parseJid($login);
        if ($jid === null) {
            $codes['login'] = 'credentials_invalid';
            $codes['password'] = 'credentials_invalid';
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

        $user = (string) $jid['user'];
        $host = (string) $jid['host'];
        try {
            $vhosts = $this->client->registeredVhosts();
            if (!in_array($host, $vhosts, true)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'login' => $this->t('error.credentials_invalid'),
                    'password' => $this->t('error.credentials_invalid'),
                ]);
            }
            if (!$this->client->checkPassword($user, $host, $password)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'login' => $this->t('error.credentials_invalid'),
                    'password' => $this->t('error.credentials_invalid'),
                ]);
            }
            $current = $this->client->privateGetEmail($user, $host);
            if ($current !== null && strcasecmp($current, $email) === 0) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'email' => $this->t('error.email_same'),
                ]);
            }
        } catch (ApiException $e) {
            $this->logger->error('Email change preparation failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }

        $ttl = max(60, (int) $this->config->get('change_email.token_ttl', 900));
        $token = $this->storage->createToken('email_change', [
            'user' => $user,
            'host' => $host,
            'email' => $email,
            'locale' => $this->translator->locale(),
            'created_at' => time(),
        ], $ttl, $ip);
        $link = url('/change-email', ['code' => $token]);
        $data = [
            'name' => $this->displayName($user, $host),
            'user' => $user,
            'host' => $host,
            'jid' => $user . '@' . $host,
            'email' => $email,
            'link' => $link,
            'ttl_minutes' => (int) ceil($ttl / 60),
        ];
        try {
            $this->sendEmail('change_email', $email, $data, [':host' => $host]);
        } catch (\Throwable $e) {
            $this->logger->error('Email change confirmation failed', ['user' => $user, 'host' => $host, 'error' => $e->getMessage()]);
            return Result::error($this->t('error.email_failed'));
        }

        if ((bool) $this->config->get('change_email.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf(
                    '[%s] User %s@%s requested from IP address %s to change the email address to %s',
                    date('Y-m-d H:i:s'),
                    $user,
                    $host,
                    $ip,
                    $email
                )
            );
        }
        $this->hitRate($limit['key'], $limit['window']);
        return Result::success($this->t('message.change_email.email_sent', [':email' => mask_email($email)]));
    }

    public function confirm(string $code): Result
    {
        $payload = $this->storage->peekToken('email_change', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        $user = (string) ($payload['user'] ?? '');
        $host = (string) ($payload['host'] ?? '');
        $email = (string) ($payload['email'] ?? '');
        if ($user === '' || $host === '' || $email === '') {
            return Result::blocked($this->t('error.token_invalid'));
        }
        try {
            $this->client->privateSetEmail($user, $host, $email);
        } catch (ApiException $e) {
            $this->logger->error('Email change confirmation failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }
        $this->storage->consumeToken('email_change', $code);
        if ((bool) $this->config->get('change_email.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf('[%s] User %s@%s has changed the email address to %s', date('Y-m-d H:i:s'), $user, $host, $email)
            );
        }
        return Result::success($this->t('message.change_email.changed', [
            ':jid' => $user . '@' . $host,
            ':email' => $email,
        ]));
    }
}
