<?php
declare(strict_types=1);

namespace Eam\Captcha;

final class NoneCaptcha implements CaptchaInterface
{
    public function render(): string
    {
        return '';
    }

    public function verify(array $input, string $ip): bool
    {
        return true;
    }

    public function reload(): array
    {
        return ['html' => ''];
    }

    public function fieldName(): string
    {
        return '';
    }
}
