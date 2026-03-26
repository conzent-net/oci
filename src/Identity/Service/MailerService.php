<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around PHPMailer configured via env vars.
 */
final class MailerService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private string $fromAddress;
    private string $fromName;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->host = $_ENV['MAIL_HOST'] ?? 'mailpit';
        $this->port = (int) ($_ENV['MAIL_PORT'] ?? 1025);
        $this->username = $_ENV['MAIL_USERNAME'] ?? '';
        $this->password = $_ENV['MAIL_PASSWORD'] ?? '';
        $this->encryption = $_ENV['MAIL_ENCRYPTION'] ?? '';
        $this->fromAddress = $_ENV['MAIL_FROM_ADDRESS'] ?? 'support@conzent.net';
        $this->fromName = $_ENV['MAIL_FROM_NAME'] ?? 'Conzent.net';
    }

    /**
     * Send an HTML email.
     *
     * @param string $to        Recipient email
     * @param string $subject   Email subject
     * @param string $htmlBody  Full HTML body
     * @param string $textBody  Plain-text fallback (optional)
     */
    public function send(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->Port = $this->port;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            // Auth only if credentials are provided (dev Mailpit needs none)
            if ($this->username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->username;
                $mail->Password = $this->password;
            } else {
                $mail->SMTPAuth = false;
            }

            // Encryption
            if ($this->encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($this->encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($this->fromAddress, $this->fromName);
            $mail->addAddress($to);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody !== '' ? $textBody : strip_tags($htmlBody);

            $mail->send();

            $this->logger->info('Email sent', ['to' => $to, 'subject' => $subject]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Email failed', [
                'to' => $to,
                'subject' => $subject,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
