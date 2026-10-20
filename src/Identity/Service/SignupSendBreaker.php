<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Psr\Log\LoggerInterface;

/**
 * Circuit breaker on outbound signup verification mail.
 *
 * The honeypot, the per-IP rate limit, Reoon and Turnstile all work the same
 * way: by correctly identifying a bot. Every one of them loses to somebody who
 * cares enough — real mailboxes beat Reoon, rotating IPs beat the rate limit,
 * and solving services beat a challenge for about a dollar per thousand.
 *
 * This does not try to identify anything. It refuses to let the SES sending
 * reputation take more damage than a number we chose, whoever is on the other
 * end and however they got past the door. That is why it is the layer that
 * cannot be beaten, and why the caps are deliberately boring.
 *
 * It sits at the single send chokepoint, so it covers signup AND resend.
 */
final class SignupSendBreaker
{
    /** Sends allowed to one address per window. Humans need one, maybe two. */
    private const CAP_PER_ADDRESS = 3;

    /** Sends allowed across the whole install per window. */
    private const CAP_GLOBAL = 200;

    private const WINDOW_SECONDS = 3600;

    /** @var callable(string, int, int): bool */
    private $allow;

    /** @var callable(string, string, string): bool */
    private $notify;

    /**
     * @param callable|null $allow  fn(string $bucket, int $limit, int $window): bool
     * @param callable|null $notify fn(string $to, string $subject, string $html): bool
     */
    public function __construct(
        RateLimiter $rateLimiter,
        MailerService $mailer,
        private readonly LoggerInterface $logger,
        ?callable $allow = null,
        ?callable $notify = null,
    ) {
        $this->allow = $allow ?? static fn (string $bucket, int $limit, int $window): bool
            => $rateLimiter->allow($bucket, $limit, $window);

        $this->notify = $notify ?? static fn (string $to, string $subject, string $html): bool
            => $mailer->send($to, $subject, $html);
    }

    /**
     * May we send a verification code to this address right now?
     *
     * The per-address check runs FIRST on purpose: a single hammered address
     * must not eat the global budget that protects everybody else's signup.
     */
    public function allowSend(string $email): bool
    {
        $perAddress = $this->cap('SIGNUP_SEND_CAP_PER_ADDRESS_PER_HOUR', self::CAP_PER_ADDRESS);
        $global = $this->cap('SIGNUP_SEND_CAP_PER_HOUR', self::CAP_GLOBAL);

        // Keyed on a hash, never the address itself: the rate-limit table would
        // otherwise quietly become a second copy of every address anyone ever
        // typed into the signup form, including the ones we rejected.
        $fingerprint = hash('sha256', strtolower(trim($email)));

        if (!($this->allow)('signup-send:' . $fingerprint, $perAddress, self::WINDOW_SECONDS)) {
            $this->logger->warning('Verification send suppressed — per-address cap reached', ['cap' => $perAddress]);

            return false;
        }

        if (!($this->allow)('signup-send:global', $global, self::WINDOW_SECONDS)) {
            $this->logger->error(
                'Verification send suppressed — GLOBAL hourly cap reached, signups are being refused',
                ['cap' => $global],
            );
            $this->alert($global);

            return false;
        }

        return true;
    }

    /**
     * Tripping the global cap is an incident, and a breaker nobody hears about
     * is not much of a breaker. Capped at one mail per window through the same
     * limiter — an alert storm during a flood would be this thing causing the
     * exact problem it exists to prevent.
     */
    private function alert(int $cap): void
    {
        if (!($this->allow)('signup-send:global-alert', 1, self::WINDOW_SECONDS)) {
            return;
        }

        $to = trim((string) ($_ENV['SIGNUP_ALERT_EMAIL'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? 'support@getconzent.com'));
        }
        if ($to === '') {
            return;
        }

        ($this->notify)(
            $to,
            'Conzent: signup verification sends have been capped',
            '<p>The hourly cap of ' . $cap . ' signup verification emails has been reached, so'
            . ' new signups are now being refused until the window rolls.</p>'
            . '<p>This is the circuit breaker working. Check the signup log for a flood before'
            . ' raising <code>SIGNUP_SEND_CAP_PER_HOUR</code> — the cap exists to protect the'
            . ' SES sending reputation, and raising it during an attack spends exactly what it'
            . ' is defending.</p>',
        );
    }

    private function cap(string $envKey, int $default): int
    {
        $value = (int) ($_ENV[$envKey] ?? 0);

        return $value > 0 ? $value : $default;
    }
}
