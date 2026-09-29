<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Psr\Log\LoggerInterface;

/**
 * Cloudflare Turnstile verification for the signup form.
 *
 * Turnstile rather than reCAPTCHA on purpose: reCAPTCHA sets Google cookies,
 * which is not a defensible thing for a consent platform to put on its own
 * signup page. Turnstile is cookieless and invisible to most visitors.
 *
 * Optional by design. With no keys configured the widget is never rendered and
 * verification is skipped, so a self-hosted install behaves exactly as before.
 */
final class TurnstileVerifier
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private const TIMEOUT_SECONDS = 5;

    /** Cloudflare's documented maximum token length. */
    private const MAX_TOKEN_LENGTH = 2048;

    /** @var callable(string, array<string, string>): ?string */
    private $poster;

    /**
     * @param callable|null $poster fn(string $url, array $fields): ?string — override for
     *                              tests; defaults to a form-encoded cURL POST returning the
     *                              body on 2xx and null otherwise.
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        ?callable $poster = null,
    ) {
        $this->poster = $poster ?? static function (string $url, array $fields): ?string {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($fields),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            ]);

            $body = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return \is_string($body) && $status >= 200 && $status < 300 ? $body : null;
        };
    }

    public function isConfigured(): bool
    {
        return $this->siteKey() !== '' && $this->secretKey() !== '';
    }

    /** Public key, safe to render into the page. */
    public function siteKey(): string
    {
        return trim((string) ($_ENV['TURNSTILE_SITE_KEY'] ?? ''));
    }

    /**
     * Verify one widget token.
     *
     * Fails CLOSED: a token that cannot be confirmed is rejected, including when
     * Cloudflare is unreachable. Every request that gets past this point sends a
     * real SES email, and the sending reputation is the asset being defended —
     * losing a few signups during a Cloudflare wobble is the cheaper failure.
     *
     * Tokens are single-use and valid for 300 seconds, so the form must reset
     * the widget after any rejected submission or the retry fails as a duplicate.
     */
    public function verify(string $token, string $remoteIp = ''): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        if ($token === '' || \strlen($token) > self::MAX_TOKEN_LENGTH) {
            return false;
        }

        $fields = ['secret' => $this->secretKey(), 'response' => $token];
        if ($remoteIp !== '') {
            $fields['remoteip'] = $remoteIp;
        }

        $raw = ($this->poster)(self::SITEVERIFY_URL, $fields);
        if ($raw === null) {
            $this->logger->error('Turnstile siteverify unreachable — refusing the signup');
            return false;
        }

        $body = json_decode($raw, true);
        if (!\is_array($body)) {
            $this->logger->error('Turnstile siteverify returned an unparseable body');
            return false;
        }

        if (($body['success'] ?? false) === true) {
            return true;
        }

        $this->logger->info('Turnstile rejected a signup', [
            'error_codes' => $body['error-codes'] ?? [],
        ]);

        return false;
    }

    private function secretKey(): string
    {
        return trim((string) ($_ENV['TURNSTILE_SECRET_KEY'] ?? ''));
    }
}
