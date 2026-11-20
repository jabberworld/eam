<?php
declare(strict_types=1);

namespace Eam\Captcha;

interface CaptchaInterface
{
    public function render(): string;

    public function verify(array $input, string $ip): bool;

    /** @return array{html: string} */
    public function reload(): array;

    public function fieldName(): string;
}
