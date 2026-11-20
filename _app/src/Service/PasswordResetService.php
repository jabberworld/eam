<?php
declare(strict_types=1);

namespace Eam\Service;

use Eam\Ejabberd\ApiException;
use Eam\Validation\Validator;

final class PasswordResetService extends AbstractService
{
    public function showCode(string $code): Result
    {
        $payload = $this->storage->peekToken('password_reset', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        return Result::success(null, [
            'token' => $code,
            'user' => (string) ($payload['user'] ?? ''),
            'host' => (string) ($payload['host'] ?? ''),
        ]);
    }

    public function submitRequest(array $input, string $ip): Result
    {
        if (!(bool) $this->config->get('password_reset.enabled', true)) {
            return Result::blocked($this->t('error.disabled.reset_password'));
        }
        $limit = $this->rateLimit('reset:ip:' . $ip, 'password_reset');
        if ($limit['limited']) {
            return Result::blocked($this->t('error.rate_limited'));
        }

        $login = strtolower(trim((string) ($input['login'] ?? '')));
        $jid = Validator::parseJid($login);
        $codes = [];
        if ($jid === null) {
            $codes['login'] = 'user_not_found';
        }
        if (!$this->captchaOk($input, $ip)) {
            $codes['captcha'] = 'captcha';
        }
        if ($codes !== []) {
            return Result::blocked($this->t('error.fix_fields'), $this->mapErrors($codes));
        }

        $user = (string) $jid['user'];
        $host = (string) $jid['host'];
        $jidString = $user . '@' . $host;
        try {
            $vhosts = $this->client->registeredVhosts();
            if (!in_array($host, $vhosts, true)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'login' => $this->t('error.user_not_found'),
                ]);
            }
            if (!$this->client->checkAccount($user, $host)) {
                return Result::blocked($this->t('error.fix_fields'), [
                    'login' => $this->t('error.user_not_found'),
                ]);
            }
            $reason = $this->client->getBanReason($user, $host);
            if ($reason !== null && $reason !== 'Activation required') {
                return Result::blocked($this->t('error.account_blocked'));
            }
            $email = $this->client->privateGetEmail($user, $host);
            if ($email === null) {
                return Result::error($this->t('error.no_email'));
            }
        } catch (ApiException $e) {
            $this->logger->error('Password reset preparation failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }

        $jidLimitKey = 'reset:jid:' . $jidString;
        $jidLimitCount = max(1, (int) $this->config->get('password_reset.limit_count', 4));
        $jidLimitWindow = max(1, (int) $this->config->get('password_reset.limit_window', 43200));
        if ($this->storage->isRateLimited($jidLimitKey, $jidLimitCount)) {
            return Result::blocked($this->t('error.reset_limit'));
        }

        $ttl = max(60, (int) $this->config->get('password_reset.token_ttl', 900));
        $token = $this->storage->createToken('password_reset', [
            'user' => $user,
            'host' => $host,
            'email' => $email,
            'locale' => $this->translator->locale(),
            'created_at' => time(),
        ], $ttl, $ip);
        $link = url('/reset-password', ['code' => $token]);
        $data = [
            'name' => $this->displayName($user, $host),
            'user' => $user,
            'host' => $host,
            'jid' => $jidString,
            'email' => $email,
            'link' => $link,
            'ttl_minutes' => (int) ceil($ttl / 60),
        ];
        try {
            $this->sendEmail('reset_password', $email, $data, [':host' => $host]);
        } catch (\Throwable $e) {
            $this->logger->error('Password reset email failed', ['user' => $user, 'host' => $host, 'error' => $e->getMessage()]);
            return Result::error($this->t('error.email_failed'));
        }

        $this->hitRate($jidLimitKey, $jidLimitWindow);
        $this->hitRate($limit['key'], $limit['window']);
        if ((bool) $this->config->get('password_reset.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf(
                    '[%s] User %s requested from IP address %s to reset the password, an email was sent to %s',
                    date('Y-m-d H:i:s'),
                    $jidString,
                    $ip,
                    mask_email($email)
                )
            );
        }
        return Result::success($this->t('message.reset_password.email_sent', [':email' => mask_email($email)]));
    }

    public function confirm(string $code, string $newPassword, string $ip): Result
    {
        $payload = $this->storage->peekToken('password_reset', $code);
        if ($payload === null) {
            return Result::blocked($this->t('error.token_invalid'));
        }
        $user = (string) ($payload['user'] ?? '');
        $host = (string) ($payload['host'] ?? '');
        if ($user === '' || $host === '') {
            return Result::blocked($this->t('error.token_invalid'));
        }
        $passwordError = Validator::validatePassword($newPassword, $this->config);
        if ($passwordError !== null) {
            return Result::blocked($this->t('error.fix_fields'), [
                'password' => $this->t('error.' . $passwordError),
            ]);
        }

        if ((bool) $this->config->get('password_reset.unban_ip', true)) {
            try {
                $this->client->unbanIp($ip);
            } catch (\Throwable $e) {
                $this->logger->warning('unban_ip failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            }
        }

        try {
            $reason = $this->client->getBanReason($user, $host);
            if ($reason !== null && $reason !== 'Activation required') {
                return Result::blocked($this->t('error.account_blocked'));
            }
            if ($reason === 'Activation required') {
                $this->client->unbanAccount($user, $host);
            }
            $this->client->changePassword($user, $host, $newPassword);
            try {
                $this->client->kickUser($user, $host);
            } catch (\Throwable $e) {
                $this->logger->warning('kick_user failed after password change', [
                    'user' => $user,
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (ApiException $e) {
            $this->logger->error('Password reset failed', [
                'user' => $user,
                'host' => $host,
                'status' => $e->status,
            ]);
            return Result::error($this->t('error.server'));
        }

        $this->storage->consumeToken('password_reset', $code);
        $this->storage->clearRate('reset:jid:' . $user . '@' . $host);
        if ((bool) $this->config->get('password_reset.watch', false)) {
            $this->notifyWatchers(
                $host,
                (array) $this->config->get('registration.watchers', []),
                sprintf('[%s] User %s@%s has changed the password from IP address %s', date('Y-m-d H:i:s'), $user, $host, $ip)
            );
        }
        return Result::success($this->t('message.reset_password.changed'));
    }
}
