<?php
declare(strict_types=1);

namespace Eam\Captcha;

use Eam\Config;
use Eam\Http\Session;
use Eam\I18n\Translator;

final class BuiltinCaptcha implements CaptchaInterface
{
    private const CHARS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const SESSION_KEY = 'captcha_builtin';

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly Translator $translator
    ) {
    }

    public function render(): string
    {
        $length = max(3, min(8, (int) $this->config->get('captcha.builtin.length', 5)));
        $width = max(80, (int) $this->config->get('captcha.builtin.width', 150));
        $height = max(30, (int) $this->config->get('captcha.builtin.height', 40));
        $phrase = $this->phrase($length);
        $ttl = max(60, (int) $this->config->get('captcha.builtin.ttl', 600));
        $this->session->set(self::SESSION_KEY, [
            'hash' => hash('sha256', strtoupper($phrase)),
            'expires' => time() + $ttl,
        ]);
        $image = $this->image($phrase, $width, $height);
        $label = (string) $this->translator->get('captcha.label');
        $reload = (string) $this->translator->get('captcha.reload');
        return '<div class="captcha" id="captcha">'
            . '<label for="captcha-input">' . e($label) . '</label>'
            . '<div class="captcha-row">'
            . '<img src="' . e($image) . '" alt="' . e($label) . '" width="' . $width . '" height="' . $height . '">'
            . '<button type="button" class="button button-secondary" data-captcha-reload>' . e($reload) . '</button>'
            . '</div>'
            . '<input id="captcha-input" type="text" name="captcha" autocomplete="off" required>'
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
        $answer = strtoupper(trim((string) ($input['captcha'] ?? '')));
        if ($answer === '') {
            return false;
        }
        return hash_equals((string) $stored['hash'], hash('sha256', $answer));
    }

    public function reload(): array
    {
        return ['html' => $this->render()];
    }

    public function fieldName(): string
    {
        return 'captcha';
    }

    private function phrase(int $length): string
    {
        $max = strlen(self::CHARS) - 1;
        $phrase = '';
        for ($i = 0; $i < $length; $i++) {
            $phrase .= self::CHARS[random_int(0, $max)];
        }
        return $phrase;
    }

    private function image(string $phrase, int $width, int $height): string
    {
        if (!extension_loaded('gd')) {
            return '';
        }
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return '';
        }
        $bg = imagecolorallocate($image, 248, 249, 246);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg);
        for ($i = 0; $i < 6; $i++) {
            $color = imagecolorallocate($image, random_int(170, 220), random_int(180, 225), random_int(170, 215));
            imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $color);
        }
        $step = (int) max(20, floor(($width - 20) / max(1, strlen($phrase))));
        $x = 12;
        foreach (str_split($phrase) as $char) {
            $color = imagecolorallocate($image, random_int(20, 80), random_int(60, 120), random_int(20, 80));
            imagestring($image, 5, $x, random_int(8, max(9, $height - 22)), $char, $color);
            $x += $step;
        }
        for ($i = 0; $i < 120; $i++) {
            $color = imagecolorallocate($image, random_int(120, 210), random_int(120, 210), random_int(120, 210));
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $color);
        }
        ob_start();
        imagepng($image);
        $data = (string) ob_get_clean();
        imagedestroy($image);
        return 'data:image/png;base64,' . base64_encode($data);
    }
}
