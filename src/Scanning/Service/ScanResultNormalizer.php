<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

/**
 * One scanner result, as the scan service stores it.
 *
 * A scanner that was asked for two passes returns `phases.pre_consent` and
 * `phases.post_consent`; one that was not, or an older scanner that did not
 * understand the request, returns the flat shape it always has. This is the
 * one place that tells those apart, so the storage code never guesses.
 *
 * The rule that matters most lives downstream but is decided here: an
 * after-consent pass only counts when the scanner says it was `verified`.
 * An unverified pass (no banner shown to the scanner, a TCF site consented
 * without vendor consent, consent lost on reload) must never replace a
 * site's cookie inventory, because that inventory is its public declaration.
 */
final class ScanResultNormalizer
{
    public const PRE_CONSENT = 'pre_consent';
    public const POST_CONSENT = 'post_consent';

    public const STATUS_VERIFIED = 'verified';
    public const STATUS_UNVERIFIED = 'unverified';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNSUPPORTED = 'unsupported';

    /**
     * @param array<string, mixed> $result
     *
     * @return array{
     *     url: string,
     *     error: ?string,
     *     phased: bool,
     *     pre: array{cookies: list<array<string, mixed>>, beacons: list<array<string, mixed>>},
     *     post: ?array{cookies: list<array<string, mixed>>, beacons: list<array<string, mixed>>, method: string, verified: bool, reason: string, error: ?string, final_url: ?string, diagnostics: array<string, mixed>},
     *     post_status: ?string
     * }
     */
    public static function normalize(array $result, bool $twoPhaseRequested): array
    {
        $url = (string) ($result['url'] ?? '');
        $error = self::text($result['error'] ?? null);

        $flat = [
            'cookies' => self::cookies($result['cookies'] ?? []),
            'beacons' => self::beacons($result['beacons'] ?? []),
        ];

        // The first pass failed: nothing to store for this URL at all.
        if ($error !== null) {
            return ['url' => $url, 'error' => $error, 'phased' => false, 'pre' => ['cookies' => [], 'beacons' => []], 'post' => null, 'post_status' => null];
        }

        $phases = \is_array($result['phases'] ?? null) ? $result['phases'] : null;

        if (!$twoPhaseRequested || $phases === null) {
            return [
                'url' => $url,
                'error' => null,
                'phased' => false,
                'pre' => $flat,
                'post' => null,
                // Asked for, but the scanner answered in the old shape: it has
                // not been upgraded, and the admin should see that plainly.
                'post_status' => $twoPhaseRequested ? self::STATUS_UNSUPPORTED : null,
            ];
        }

        $preRaw = \is_array($phases[self::PRE_CONSENT] ?? null) ? $phases[self::PRE_CONSENT] : [];
        $pre = [
            'cookies' => self::cookies($preRaw['cookies'] ?? $result['cookies'] ?? []),
            'beacons' => self::beacons($preRaw['beacons'] ?? $result['beacons'] ?? []),
        ];

        $postRaw = \is_array($phases[self::POST_CONSENT] ?? null) ? $phases[self::POST_CONSENT] : [];
        $postError = self::text($postRaw['error'] ?? null);
        $verified = $postError === null && ($postRaw['verified'] ?? false) === true;

        $post = [
            'cookies' => $postError === null ? self::cookies($postRaw['cookies'] ?? []) : [],
            'beacons' => $postError === null ? self::beacons($postRaw['beacons'] ?? []) : [],
            'method' => mb_substr((string) ($postRaw['consent_method'] ?? ($postError !== null ? 'error' : 'unknown')), 0, 20),
            'verified' => $verified,
            'reason' => mb_substr((string) ($postRaw['reason'] ?? ''), 0, 500),
            'error' => $postError,
            'final_url' => isset($postRaw['final_url']) ? mb_substr((string) $postRaw['final_url'], 0, 2048) : null,
            'diagnostics' => \is_array($postRaw['diagnostics'] ?? null) ? $postRaw['diagnostics'] : [],
        ];

        return [
            'url' => $url,
            'error' => null,
            'phased' => true,
            'pre' => $pre,
            'post' => $post,
            'post_status' => $postError !== null
                ? self::STATUS_FAILED
                : ($verified ? self::STATUS_VERIFIED : self::STATUS_UNVERIFIED),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function cookies(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $cookie) {
            if (!\is_array($cookie)) {
                continue;
            }
            $name = trim((string) ($cookie['name'] ?? ''));
            if ($name === '') {
                continue; // a cookie with no name is not real
            }
            $cookie['name'] = $name;
            $out[] = $cookie;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function beacons(mixed $raw): array
    {
        return array_values(array_filter(\is_array($raw) ? $raw : [], 'is_array'));
    }

    private static function text(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr($value, 0, 500);
    }
}
