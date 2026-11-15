<?php
declare(strict_types=1);

namespace Eam\Mail;

use RuntimeException;

final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $verifyPeer,
        private readonly int $timeout,
        private readonly string $fromEmail,
        private readonly string $fromName
    ) {
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        $to = $this->cleanAddress($to);
        $subject = $this->cleanHeader($subject);
        $from = $this->cleanAddress($this->fromEmail);
        if ($to === '' || $from === '') {
            throw new RuntimeException('Invalid email address.');
        }
        if ($text === '') {
            $text = $this->htmlToText($html);
        }
        $message = $this->buildMessage($from, $to, $subject, $html, $text);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => $this->verifyPeer,
                'verify_peer_name' => $this->verifyPeer,
                'allow_self_signed' => !$this->verifyPeer,
            ],
        ]);
        $transport = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client(
            $transport . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($fp === false) {
            throw new RuntimeException('SMTP connection failed: ' . $errstr);
        }
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect($fp, [220]);
            $this->command($fp, 'EHLO ' . $this->ehloName(), [250]);
            if ($this->encryption === 'tls') {
                $this->command($fp, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP STARTTLS failed.');
                }
                $this->command($fp, 'EHLO ' . $this->ehloName(), [250]);
            }
            if ($this->username !== '') {
                $this->command($fp, 'AUTH LOGIN', [334]);
                $this->command($fp, base64_encode($this->username), [334]);
                $this->command($fp, base64_encode($this->password), [235]);
            }
            $this->command($fp, 'MAIL FROM:<' . $from . '>', [250]);
            $this->command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($fp, 'DATA', [354]);
            $this->write($fp, $message . "\r\n.\r\n");
            $this->expect($fp, [250]);
            $this->command($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }

    private function buildMessage(string $from, string $to, string $subject, string $html, string $text): string
    {
        $boundary = 'eam_' . bin2hex(random_bytes(12));
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1) ?: 'localhost';
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . $this->formatAddress($from, $this->fromName),
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text), 76, "\r\n")
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html), 76, "\r\n")
            . '--' . $boundary . "--\r\n";
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $message) ?? $message;
    }

    private function formatAddress(string $email, string $name): string
    {
        $name = $this->cleanHeader($name);
        return $name === '' ? '<' . $email . '>' : $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $value) !== 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $text = preg_replace('/<\/p>/i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanAddress(string $email): string
    {
        $email = trim(str_replace(["\r", "\n", '<', '>'], '', $email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    private function cleanHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    private function ehloName(): string
    {
        $host = gethostname();
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    /** @param resource $fp */
    private function command($fp, string $line, array $expected): void
    {
        $this->write($fp, $line . "\r\n");
        $this->expect($fp, $expected);
    }

    /** @param resource $fp */
    private function write($fp, string $data): void
    {
        if (@fwrite($fp, $data) === false) {
            throw new RuntimeException('SMTP write failed.');
        }
    }

    /** @param resource $fp */
    private function expect($fp, array $expected): string
    {
        $response = '';
        while (true) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                throw new RuntimeException('SMTP read failed.');
            }
            $response .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP unexpected response: ' . trim($response));
        }
        return $response;
    }
}
