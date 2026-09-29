<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

/**
 * Pre-send email validation for the signup flow.
 *
 * Every address that reaches EmailVerificationService::sendCodeEmail costs a
 * real SES send, and a bounce there is charged against the domain's sending
 * reputation. This validator rejects the addresses we can prove are undeliverable
 * WITHOUT any metered third-party API:
 *
 *   1. Syntax — the same pragmatic RFC-5322-style pattern the signup form runs
 *      client-side (templates/pages/auth/register.html.twig — keep in sync).
 *   2. Disposable domains — a vendored copy of the open-source
 *      disposable-email-domains blocklist (config/disposable-email-domains.txt).
 *      Matched per label suffix, so foo.mailinator.com is caught by mailinator.com.
 *   3. DNS — the domain must have an MX record, or (RFC 5321 fallback) an A/AAAA
 *      record. Plain resolver query, free.
 *
 * What this deliberately cannot catch: a syntactically valid mailbox at a big
 * provider (random-string@gmail.com) — those accept-then-bounce, which is why
 * the signup endpoint also carries a honeypot and per-IP rate limit.
 *
 * All three failures return the SAME message: it is accurate in every case and
 * gives an enumeration bot nothing to learn from.
 */
class EmailValidator
{
    public const ERROR_MESSAGE = "This doesn't look like a valid email address. Please check and try again.";

    /**
     * Pragmatic RFC-5322-style pattern (WHATWG email pattern, tightened to
     * require at least one dot-separated domain label, so user@localhost is
     * rejected — a public signup form never legitimately sees a bare TLD).
     * The client-side check in register.html.twig uses this exact pattern.
     */
    private const SYNTAX_PATTERN = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)+$/';

    /** @var array<string, true>|null Lazy-loaded blocklist as a hash set. */
    private ?array $disposableDomains = null;

    /** @var callable(string): bool */
    private $dnsCheck;

    /**
     * @param string|null   $blocklistPath Override for tests; defaults to the vendored list.
     * @param callable|null $dnsCheck      fn(string $domain): bool — override for tests;
     *                                     defaults to a real MX-then-A/AAAA resolver query.
     */
    public function __construct(
        private readonly ?string $blocklistPath = null,
        ?callable $dnsCheck = null,
    ) {
        $this->dnsCheck = $dnsCheck ?? static function (string $domain): bool {
            // Trailing dot = fully qualified, so the resolver never appends
            // a local search domain and "matches" something unintended.
            $fqdn = $domain . '.';
            return checkdnsrr($fqdn, 'MX')
                || checkdnsrr($fqdn, 'A')
                || checkdnsrr($fqdn, 'AAAA');
        };
    }

    /**
     * Returns null when the address is acceptable, or a user-facing error
     * message when it must be rejected before any email is sent.
     */
    public function validate(string $email): ?string
    {
        $email = trim($email);

        // RFC 5321 limits: 64 octets local part, 254 total. Anything longer
        // is undeliverable regardless of syntax.
        $at = strrpos($email, '@');
        if (
            $email === ''
            || \strlen($email) > 254
            || $at === false
            || $at > 64
            || !preg_match(self::SYNTAX_PATTERN, $email)
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return self::ERROR_MESSAGE;
        }

        $domain = strtolower(substr($email, $at + 1));

        if ($this->isDisposableDomain($domain)) {
            return self::ERROR_MESSAGE;
        }

        if (!($this->dnsCheck)($domain)) {
            return self::ERROR_MESSAGE;
        }

        return null;
    }

    /**
     * Exact domain or any parent suffix match against the vendored blocklist,
     * so subdomain addresses of a listed service are caught too.
     */
    private function isDisposableDomain(string $domain): bool
    {
        $list = $this->loadDisposableDomains();
        if ($list === []) {
            return false;
        }

        $labels = explode('.', $domain);
        for ($i = 0, $n = \count($labels) - 1; $i < $n; $i++) {
            if (isset($list[implode('.', \array_slice($labels, $i))])) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, true> */
    private function loadDisposableDomains(): array
    {
        if ($this->disposableDomains !== null) {
            return $this->disposableDomains;
        }

        $path = $this->blocklistPath ?? \dirname(__DIR__, 3) . '/config/disposable-email-domains.txt';

        $this->disposableDomains = [];
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = strtolower(trim($line));
                if ($line !== '' && $line[0] !== '#') {
                    $this->disposableDomains[$line] = true;
                }
            }
        }

        return $this->disposableDomains;
    }
}
