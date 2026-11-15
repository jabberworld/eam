<?php
declare(strict_types=1);

namespace Eam\Mail;

use Eam\Config;
use RuntimeException;

final class MailerFactory
{
    public static function create(Config $config): MailerInterface
    {
        $fromEmail = (string) $config->get('mail.from_email');
        $fromName = (string) $config->get('mail.from_name', '');
        $transport = (string) $config->get('mail.transport', 'smtp');
        if ($transport === 'mail') {
            return new NativeMailer($fromEmail, $fromName);
        }
        $host = (string) $config->get('mail.smtp.host', '');
        if ($host === '') {
            throw new RuntimeException('mail.smtp.host is not configured.');
        }
        return new SmtpMailer(
            $host,
            (int) $config->get('mail.smtp.port', 25),
            (string) $config->get('mail.smtp.encryption', 'none'),
            (string) $config->get('mail.smtp.username', ''),
            (string) $config->get('mail.smtp.password', ''),
            (bool) $config->get('mail.smtp.verify_peer', true),
            (int) $config->get('mail.smtp.timeout', 10),
            $fromEmail,
            $fromName
        );
    }
}
