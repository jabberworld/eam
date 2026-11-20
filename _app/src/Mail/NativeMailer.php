<?php
declare(strict_types=1);

namespace Eam\Mail;

use RuntimeException;

final class NativeMailer implements MailerInterface
{
    public function __construct(
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        $to = trim(str_replace(["\r", "\n"], '', $to));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Invalid recipient email.');
        }
        $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
        $fromEmail = trim(str_replace(["\r", "\n"], '', $this->fromEmail));
        $fromName = trim(str_replace(["\r", "\n"], ' ', $this->fromName));
        $encodedSubject = preg_match('/[^\x20-\x7E]/', $subject) === 1
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;
        $from = $fromName !== ''
            ? '=?UTF-8?B?' . base64_encode($fromName) . '?= <' . $fromEmail . '>'
            : $fromEmail;
        $headers = [
            'From: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = chunk_split(base64_encode($html), 76, "\r\n");
        if (!mail($to, $encodedSubject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('mail() failed.');
        }
    }
}
