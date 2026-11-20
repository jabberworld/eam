<?php
declare(strict_types=1);

namespace Eam\Service;

use Eam\Ejabberd\ApiException;
use Eam\Validation\Validator;

final class AccountDeletionService extends AbstractService
{
    public function showCode(string $code): Result
    {
        $payload = $this->storage->peekToken('account_delete', $code);
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
        if (!(bool) $this->config->get('delete_account.enabled', true)) {
            return Result::blocked($this->t('error.disabled.delete_account'));
        }
        $limit = $this->rateLimit('delete:ip:' . $ip, 'delete_account');
        if ($limit['limited']) {
            return Result::blocked($this->t('error.rate_limited'));
        }

        $login = strtolower(trim((string) ($input['login'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $jid = Validator::parseJid($login);
        $codes = [];
        if ($jid === null) {
            $codes['login'] = 'credentials_invalid';
            $codes['password'] = 'credentials_invalid';
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
            if (!in_array($host, $vhosts, true) || !$this->client->checkPassword($user, $host, $password)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'login' => $this->t('error.credentials_invalid'),
                    'password' => $this->t('error.credentials_invalid'),
                ]);
            }
            $email = $this->client->privateGetEmail($user, $host);
            if ($email === null) {
                return Result::error($this->t('error.no_email'));
            }
        } catch (ApiException $e) {
            $this->logger->error('Account deletion preparation failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }

        $ttl = max(60, (int) $this->config->get('delete_account.token_ttl', 900));
        $token = $this->storage->createToken('account_delete', [
            'user' => $user,
            'host' => $host,
            'email' => $email,
            'locale' => $this->translator->locale(),
            'created_at' => time(),
        ], $ttl, $ip);
        $link = url('/delete-account', ['code' => $token]);
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
            $this->sendEmail('delete_account', $email, $data, [':host' => $host]);
        } catch (\Throwable $e) {
            $this->logger->error('Account deletion email failed', ['user' => $user, 'host' => $host, 'error' => $e->getMessage()]);
            return Result::error($this->t('error.email_failed'));
        }

        $this->hitRate($limit['key'], $limit['window']);
        if ((bool) $this->config->get('delete_account.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf(
                    '[%s] User %s requested from IP address %s to delete the account',
                    date('Y-m-d H:i:s'),
                    $user . '@' . $host,
                    $ip
                )
            );
        }
        return Result::success($this->t('message.delete_account.email_sent', [':email' => mask_email($email)]));
    }

    public function confirm(string $code, string $password): Result
    {
        $payload = $this->storage->peekToken('account_delete', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        $user = (string) ($payload['user'] ?? '');
        $host = (string) ($payload['host'] ?? '');
        if ($user === '' || $host === '') {
            return Result::blocked($this->t('error.token_invalid'));
        }
        try {
            if (!$this->client->checkPassword($user, $host, $password)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'password' => $this->t('error.password_incorrect'),
                ]);
            }
            $this->client->unregister($user, $host);
        } catch (ApiException $e) {
            $this->logger->error('Account deletion failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }
        $this->storage->consumeToken('account_delete', $code);
        if ((bool) $this->config->get('delete_account.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf('[%s] User %s@%s has deleted the account', date('Y-m-d H:i:s'), $user, $host)
            );
        }
        return Result::success($this->t('message.delete_account.deleted'));
    }
}
