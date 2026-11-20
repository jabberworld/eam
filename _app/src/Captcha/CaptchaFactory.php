<?php
declare(strict_types=1);

namespace Eam\Captcha;

use Eam\Config;
use Eam\Http\Session;
use Eam\I18n\Translator;
use Eam\Support\Logger;

final class CaptchaFactory
{
    public static function create(Config $config, Session $session, Translator $translator, Logger $logger): CaptchaInterface
    {
        $provider = (string) $config->get('captcha.provider', 'builtin');
        if ($provider === 'recaptcha') {
            return new Recaptcha($config, $logger);
        }
        if ($provider === 'none') {
            return new NoneCaptcha();
        }
        if (extension_loaded('gd')) {
            return new BuiltinCaptcha($config, $session, $translator);
        }
        return new MathCaptcha($session, $translator);
    }
}
