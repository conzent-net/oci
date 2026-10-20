<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

/**
 * TOTP (RFC 6238) over HOTP (RFC 4226), plus the Base32 alphabet TOTP
 * authenticators expect (RFC 4648).
 *
 * Hand-rolled rather than pulled in as a dependency: the algorithm is about
 * forty lines, the RFC publishes official test vectors that pin it exactly
 * (see TotpServiceTest), and a second factor is a poor place to inherit
 * supply-chain risk for so little code.
 *
 * Defaults match what Google Authenticator, 1Password, Authy and Bitwarden all
 * assume: SHA-1, 6 digits, a 30-second step. Those are not modern choices in
 * the abstract, but they are what the ecosystem interoperates on, and an
 * authenticator that cannot read our QR code is worse than SHA-1.
 */
final class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public const DIGITS = 6;
    public const PERIOD = 30;
    public const ALGORITHM = 'sha1';

    /**
     * How many steps either side of "now" are accepted, to tolerate clock drift
     * between our server and the phone. One step means up to 30 seconds of skew
     * in each direction, which is the usual compromise: wider is friendlier to
     * bad clocks but linearly widens the window an attacker can guess into.
     */
    public const DRIFT_STEPS = 1;

    /** A 160-bit secret, matching the SHA-1 block the algorithm uses. */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The provisioning URI an authenticator app reads from the QR code.
     *
     * `issuer` appears both as a label prefix and as a parameter because older
     * apps read one and newer apps read the other; sending both is what makes
     * the account show up named correctly everywhere.
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($accountName);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper(self::ALGORITHM),
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', \PHP_QUERY_RFC3986);
    }

    /** The code for a given moment. Exposed mainly so tests can pin RFC vectors. */
    public function codeAt(string $secret, int $timestamp, int $digits = self::DIGITS, string $algorithm = self::ALGORITHM): string
    {
        return $this->hotp($this->base32Decode($secret), intdiv($timestamp, self::PERIOD), $digits, $algorithm);
    }

    /**
     * Verify a submitted code, returning the time step it matched so the caller
     * can persist it and refuse the same code twice.
     *
     * Returns null when nothing matches. Comparison is constant-time: a timing
     * side channel on a six-digit code is small but entirely avoidable.
     *
     * @param int|null $lastUsedStep the step already spent by this user, if any
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $now = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) {
            return null;
        }

        $binary = $this->base32Decode($secret);
        if ($binary === '') {
            return null;
        }

        $currentStep = intdiv($now ?? time(), self::PERIOD);

        for ($offset = -self::DRIFT_STEPS; $offset <= self::DRIFT_STEPS; $offset++) {
            $step = $currentStep + $offset;

            // Replay guard: a code stays valid for its whole window, so without
            // this an intercepted code could be used a second time inside the
            // same 30 seconds.
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }

            if (hash_equals($this->hotp($binary, $step, self::DIGITS, self::ALGORITHM), $code)) {
                return $step;
            }
        }

        return null;
    }

    /** HOTP, RFC 4226 §5.3: HMAC the counter, then dynamically truncate. */
    private function hotp(string $key, int $counter, int $digits, string $algorithm): string
    {
        $hash = hash_hmac($algorithm, pack('J', $counter), $key, true);
        $offset = \ord($hash[\strlen($hash) - 1]) & 0x0F;

        $truncated = ((\ord($hash[$offset]) & 0x7F) << 24)
            | ((\ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((\ord($hash[$offset + 2]) & 0xFF) << 8)
            | (\ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** $digits)), $digits, '0', \STR_PAD_LEFT);
    }

    public function base32Encode(string $binary): string
    {
        if ($binary === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(\ord($char)), 8, '0', \STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', \STR_PAD_RIGHT))];
        }

        return $out;
    }

    public function base32Decode(string $secret): string
    {
        // Authenticator apps and humans both add spaces and padding; accept the
        // secret in whatever shape it arrives.
        $secret = strtoupper(str_replace([' ', '-', '='], '', trim($secret)));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                return '';
            }
            $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (\strlen($chunk) === 8) {
                $out .= \chr(bindec($chunk));
            }
        }

        return $out;
    }
}
