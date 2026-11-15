<?php
declare(strict_types=1);

namespace Eam\Captcha;

use Eam\Http\Session;
use Eam\I18n\Translator;

final class MathCaptcha implements CaptchaInterface
{
    private const SESSION_KEY = 'captcha_math';

    public function __construct(
        private readonly Session $session,
        private readonly Translator $translator
    ) {
    }

    public function render(): string
    {
        $a = random_int(2, 9);
        $b = random_int(2, 9);
        $this->session->set(self::SESSION_KEY, [
            'hash' => hash('sha256', (string) ($a + $b)),
            'expires' => time() + 600,
        ]);
        $question = (string) $this->translator->get('captcha.math', [':a' => $a, ':b' => $b]);
        return '<div class="captcha" id="captcha">'
            . '<label for="captcha-input">' . e($question) . '</label>'
            . '<input id="captcha-input" type="text" name="captcha" inputmode="numeric" autocomplete="off" required>'
            . '</div>';
    }

    public function verify(array $input, string $ip): bool
    {
        $stored = $this->session->get(self::SESSION_KEY);
        $this->session->remove(self::SESSION_KEY);
        if (!is_array($stored) || !isset($stored['hash'], $stored['expires'])) {
            return false;
        }
        if ((int) $stored['expires'] < time()) {
            return false;
        }
        $answer = trim((string) ($input['captcha'] ?? ''));
        return $answer !== '' && hash_equals((string) $stored['hash'], hash('sha256', $answer));
    }

    public function reload(): array
    {
        return ['html' => $this->render()];
    }

    public function fieldName(): string
    {
        return 'captcha';
    }
}
