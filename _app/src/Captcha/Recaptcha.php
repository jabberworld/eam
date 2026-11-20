<?php
declare(strict_types=1);

namespace Eam\Captcha;

use Eam\Config;
use Eam\Support\Logger;

final class Recaptcha implements CaptchaInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function render(): string
    {
        $siteKey = (string) $this->config->get('captcha.recaptcha.site_key', '');
        if ($siteKey === '') {
            return '<p class="alert alert-error">reCAPTCHA site key is not configured.</p>';
        }
        $version = (string) $this->config->get('captcha.recaptcha.version', 'v2');
        if ($version === 'v3') {
            $key = json_encode($siteKey, JSON_UNESCAPED_SLASHES) ?: "''";
            $action = json_encode((string) $this->config->get('captcha.recaptcha.v3_action', 'eam'), JSON_UNESCAPED_SLASHES) ?: "'eam'";
            return '<input type="hidden" name="g-recaptcha-response" value="">'
                . '<script src="https://www.google.com/recaptcha/api.js?render=' . e($siteKey) . '" async defer></script>'
                . '<script>document.addEventListener("submit",function(e){var f=e.target;if(!f.matches("form[data-eam-form]"))return;var i=f.querySelector("[name=g-recaptcha-response]");if(!i||i.value)return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute(' . $key . ',{action:' . $action . '}).then(function(t){i.value=t;f.submit();});});},true);</script>';
        }
        return '<div class="g-recaptcha" data-sitekey="' . e($siteKey) . '"></div>'
            . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
    }

    public function verify(array $input, string $ip): bool
    {
        $secret = (string) $this->config->get('captcha.recaptcha.secret_key', '');
        $response = trim((string) ($input['g-recaptcha-response'] ?? ''));
        if ($secret === '' || $response === '') {
            return false;
        }
        $post = http_build_query([
            'secret' => $secret,
            'response' => $response,
            'remoteip' => $ip,
        ]);
        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $post,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 8,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body) || $status !== 200) {
            $this->logger->warning('reCAPTCHA verification failed', ['status' => $status, 'error' => $error]);
            return false;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['success'])) {
            return false;
        }
        if ((string) $this->config->get('captcha.recaptcha.version', 'v2') === 'v3') {
            $score = (float) ($data['score'] ?? 0.0);
            if ($score < (float) $this->config->get('captcha.recaptcha.v3_threshold', 0.5)) {
                return false;
            }
            $expectedAction = (string) $this->config->get('captcha.recaptcha.v3_action', 'eam');
            if (isset($data['action']) && (string) $data['action'] !== $expectedAction) {
                return false;
            }
        }
        return true;
    }

    public function reload(): array
    {
        return ['html' => $this->render()];
    }

    public function fieldName(): string
    {
        return 'g-recaptcha-response';
    }
}
