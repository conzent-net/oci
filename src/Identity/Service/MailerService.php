<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around PHPMailer.
 *
 * SMTP credentials come from one of two sources, resolved lazily on first send:
 *  - Endpointr vault entry (when ENDPOINTR_API_KEY is set) — name configurable
 *    via ENDPOINTR_SMTP_VAULT_NAME, default `amazon_ses`.
 *  - MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_ENCRYPTION
 *    env vars (used in dev with Mailpit; also the fallback if a vault fetch fails).
 *
 * MAIL_FROM_ADDRESS / MAIL_FROM_NAME always come from env (the vault stores
 * delivery credentials, not branding).
 */
final class MailerService
{
    /** @var array{host: string, port: int, username: string, password: string, encryption: string, source: string}|null */
    private ?array $config = null;

    private string $fromAddress;
    private string $fromName;
    private bool $debug = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly EndpointrClient $endpointr,
    ) {
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'support@conzent.net';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Conzent.net';
    }

    public function setDebug(bool $debug): void
    {
        $this->debug = $debug;
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, encryption: string, source: string}
     */
    public function getConfig(): array
    {
        return $this->resolveConfig();
    }

    /**
     * Send an HTML email.
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $cfg = $this->resolveConfig();

        // No SMTP configured — say so plainly instead of failing deep inside
        // PHPMailer with a connection error. Self-hosted installs ship without
        // a mail server, and password resets are the first thing this breaks.
        if (trim($cfg['host']) === '') {
            $this->logger->error('Email not sent: no SMTP server configured. Set MAIL_HOST in .env.', [
                'to' => $to,
                'subject' => $subject,
            ]);

            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $cfg['host'];
            $mail->Port = $cfg['port'];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            if ($this->debug) {
                $mail->SMTPDebug = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = static function (string $str, int $level): void {
                    echo "[smtp{$level}] {$str}\n";
                };
            }

            if ($cfg['username'] !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $cfg['username'];
                $mail->Password = $cfg['password'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($cfg['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($cfg['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addReplyTo($this->fromAddress, $this->fromName);
            $mail->addAddress($to);

            // Align HELO + Message-ID with the From domain so receivers can correlate
            // SMTP envelope info with the visible sender — improves DMARC scoring.
            $fromDomain = substr((string) strrchr($this->fromAddress, '@'), 1);
            if ($fromDomain !== '') {
                $mail->Hostname = $fromDomain;
                $mail->MessageID = '<' . bin2hex(random_bytes(16)) . '@' . $fromDomain . '>';
            }
            // Suppress the default "X-Mailer: PHPMailer x.y" header — a known
            // spam-score nudge for transactional senders.
            $mail->XMailer = ' ';

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : $this->htmlToText($htmlBody);

            $mail->send();

            $this->logger->info('Email sent', ['to' => $to, 'subject' => $subject, 'source' => $cfg['source']]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Email failed', [
                'to' => $to,
                'subject' => $subject,
                'source' => $cfg['source'],
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Build a clean plain-text version of an HTML body for the AltBody header.
     * Drops <style>/<script> contents (so CSS doesn't leak into plaintext),
     * preserves link URLs, and collapses whitespace.
     */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text) ?? $text;
        // Surface link URLs: <a href="X">Y</a> → Y (X)
        $text = preg_replace('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $text) ?? $text;
        $text = preg_replace('/<(br|\/p|\/div|\/h[1-6])\b[^>]*>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /**
     * @return array{host: string, port: int, username: string, password: string, encryption: string, source: string}
     */
    private function resolveConfig(): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $apiKey = (string) ($_ENV['ENDPOINTR_API_KEY'] ?? '');
        $vaultName = (string) ($_ENV['ENDPOINTR_SMTP_VAULT_NAME'] ?? 'amazon_ses');

        if ($apiKey !== '') {
            try {
                $secrets = $this->endpointr->getVaultEntry($vaultName);
                $this->config = [
                    'host' => (string) ($secrets['smtp_host'] ?? ''),
                    'port' => (int) ($secrets['smtp_port'] ?? 587),
                    'username' => (string) ($secrets['smtp_user'] ?? ''),
                    'password' => (string) ($secrets['smtp_pass'] ?? ''),
                    'encryption' => strtolower((string) ($secrets['smtp_encryption'] ?? 'tls')),
                    'source' => "vault:{$vaultName}",
                ];
                $this->logger->info('SMTP config loaded from Endpointr vault', [
                    'vault' => $vaultName,
                    'host' => $this->config['host'],
                ]);
                return $this->config;
            } catch (\Throwable $e) {
                $this->logger->warning('Endpointr vault fetch failed, falling back to MAIL_* env vars', [
                    'vault' => $vaultName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->config = [
            'host' => (string) ($_ENV['MAIL_HOST'] ?? ''),
            'port' => (int) ($_ENV['MAIL_PORT'] ?? 587),
            'username' => (string) ($_ENV['MAIL_USERNAME'] ?? ''),
            'password' => (string) ($_ENV['MAIL_PASSWORD'] ?? ''),
            'encryption' => strtolower((string) ($_ENV['MAIL_ENCRYPTION'] ?? '')),
            'source' => 'env',
        ];
        return $this->config;
    }
}
