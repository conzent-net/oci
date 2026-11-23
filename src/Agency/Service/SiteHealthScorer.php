<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

/**
 * Turn raw signals into a status, without ever claiming to know more than we do.
 *
 * ## The honesty rule
 *
 * **Unmeasured is not failing, and it is not passing either.** It is its own
 * state, and it is rendered as its own state. Collapsing it into either of the
 * others is the single easiest way to make this whole feature untrustworthy:
 * tell an agency a client is broken when we simply have not looked, and they
 * stop believing the green ones too.
 *
 * The specific trap this exists to avoid: a site with **zero pre-consent
 * observations and zero traffic**. Naively that reads as "clean". It is not —
 * `oci_cookie_observations` is fed by visitors, so no rows means no visitors at
 * least as often as it means no violations. Every evidence signal is therefore
 * gated on `pageviews_30d > 0`, and a site nobody visited is reported as
 * unmeasured rather than certified compliant.
 *
 * ## Three axes, never one number
 *
 * A single score forces unmeasured onto the same scale as failing, which is the
 * lie above with arithmetic on top. So:
 *
 * - **Configured** — what the settings say. Cheap, complete, and weak evidence:
 *   a toggle being on does not prove the tag fires correctly.
 * - **Observed** — what actually happened in front of real visitors. Strong
 *   evidence, frequently absent.
 * - **Coverage** — whether we are in a position to say anything at all. This is
 *   the axis that keeps the other two honest.
 *
 * ## One table of configuration checks
 *
 * {@see self::CONFIGURATION_CHECKS} is the only place that says which settings
 * are checked and how bad each one is. The status here and the issue list in
 * {@see IssueDeriver} both read it, so a site can never be marked failing for
 * something no issue explains — which is exactly what happened when the two
 * kept separate lists: a missing privacy policy was a scorer "fail" and not a
 * deriver issue at all, and a switched-off Consent Mode was a fail here and a
 * warning there.
 *
 * Severities, and why: a missing banner is a `fail` — nothing is being asked
 * of visitors. The other three are `warn`, and honestly so: `oci_cookie_policies`
 * and `oci_privacy_policies` only know about policies generated IN Conzent,
 * and a client hosting their own is not failing anything; and Consent Mode
 * being off is a configuration observation whose weight depends on frameworks
 * this query does not see. A warn-level gap makes a site `attention`, never
 * `failing`.
 *
 * Pure: no database, no clock beyond what it is handed. Every output is a
 * function of the row passed in.
 */
final class SiteHealthScorer
{
    /** A scan older than this is stale enough that its findings may not hold. */
    public const SCAN_STALE_DAYS = 30;

    /**
     * column   — the boolean-ish column on the SiteHealthRepository row
     * issue    — the IssueDeriver key emitted when the check fails; reuses the
     *            ComplianceCheckService vocabulary where one exists
     * severity — fail or warn; fail makes the site failing, warn makes it attention
     */
    public const CONFIGURATION_CHECKS = [
        'banner' => ['column' => 'has_banner', 'issue' => 'no_banner_configured', 'severity' => 'fail'],
        'cookie_policy' => ['column' => 'has_cookie_policy', 'issue' => 'no_cookie_policy', 'severity' => 'warn'],
        'privacy_policy' => ['column' => 'has_privacy_policy', 'issue' => 'no_privacy_policy', 'severity' => 'warn'],
        'gcm' => ['column' => 'gcm_enabled', 'issue' => 'gcm_required', 'severity' => 'warn'],
    ];

    /**
     * @param array<string, mixed> $row a row from SiteHealthRepository
     *
     * @return array{
     *     site_id: int,
     *     domain: string,
     *     status: string,
     *     configured: array{pass: int, fail: int, warn: int, unknown: int, pct: ?int, failed: array<int, array{issue: string, severity: string}>},
     *     observed: array{state: string, magnitude: int, cookies: int},
     *     coverage: array{measured: bool, scanned: bool, traffic: bool, reason: ?string}
     * }
     */
    public function score(array $row, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();

        $configured = $this->configured($row);
        $coverage = $this->coverage($row, $now);
        $observed = $this->observed($row, $coverage['traffic']);

        return [
            'site_id' => (int) $row['site_id'],
            'domain' => (string) ($row['domain'] ?? ''),
            'status' => $this->status($row, $configured, $observed, $coverage),
            'configured' => $configured,
            'observed' => $observed,
            'coverage' => $coverage,
        ];
    }

    /**
     * What the settings say. `unknown` is excluded from the percentage rather
     * than counted as a failure, so a site we cannot fully inspect does not
     * score worse than one we can.
     *
     * `failed` lists every check that did not pass, with the severity the
     * table assigns it — this is what the deriver turns into issues.
     *
     * @param array<string, mixed> $row
     *
     * @return array{pass: int, fail: int, warn: int, unknown: int, pct: ?int, failed: array<int, array{issue: string, severity: string}>}
     */
    private function configured(array $row): array
    {
        $pass = 0;
        $fail = 0;
        $warn = 0;
        $unknown = 0;
        $failed = [];

        foreach (self::CONFIGURATION_CHECKS as $check) {
            $value = $this->truthy($row[$check['column']] ?? null);

            if ($value === null) {
                ++$unknown;
                continue;
            }

            if ($value) {
                ++$pass;
                continue;
            }

            $failed[] = ['issue' => $check['issue'], 'severity' => $check['severity']];

            if ($check['severity'] === 'fail') {
                ++$fail;
            } else {
                ++$warn;
            }
        }

        $denominator = $pass + $fail + $warn;

        return [
            'pass' => $pass,
            'fail' => $fail,
            'warn' => $warn,
            'unknown' => $unknown,
            'pct' => $denominator > 0 ? (int) round($pass / $denominator * 100) : null,
            'failed' => $failed,
        ];
    }

    /**
     * What actually happened in front of visitors.
     *
     * @param array<string, mixed> $row
     *
     * @return array{state: string, magnitude: int, cookies: int}
     */
    private function observed(array $row, bool $hasTraffic): array
    {
        $hits = (int) ($row['preconsent_hits_7d'] ?? 0);
        $cookies = (int) ($row['preconsent_cookies_7d'] ?? 0);

        if ($hits > 0) {
            // Evidence of a violation stands on its own. It does not need
            // corroborating pageviews — something was observed, so somebody
            // was there.
            return ['state' => 'violations', 'magnitude' => $hits, 'cookies' => $cookies];
        }

        // No violations observed. Whether that is good news depends entirely on
        // whether anybody was looking.
        return [
            'state' => $hasTraffic ? 'clean' : 'not_measured',
            'magnitude' => 0,
            'cookies' => 0,
        ];
    }

    /**
     * Are we in a position to say anything?
     *
     * @param array<string, mixed> $row
     *
     * @return array{measured: bool, scanned: bool, traffic: bool, reason: ?string}
     */
    private function coverage(array $row, \DateTimeImmutable $now): array
    {
        $traffic = (int) ($row['pageviews_30d'] ?? 0) > 0;
        $lastScan = $this->date($row['last_scan_at'] ?? null);
        $scanned = $lastScan !== null && $lastScan >= $now->modify('-' . self::SCAN_STALE_DAYS . ' days');

        $reason = null;

        if (!$traffic && !$scanned) {
            $reason = 'No traffic and no recent scan, so nothing here has been verified.';
        } elseif (!$traffic) {
            $reason = 'No pageviews in 30 days, so there is nothing to observe.';
        } elseif (!$scanned) {
            $reason = $lastScan === null
                ? 'Never scanned.'
                : 'Last scanned ' . $lastScan->format('j M Y') . ', which is over ' . self::SCAN_STALE_DAYS . ' days ago.';
        }

        return [
            'measured' => $traffic || $scanned,
            'scanned' => $scanned,
            'traffic' => $traffic,
            'reason' => $reason,
        ];
    }

    /**
     * The one word a row gets, and the order it is decided in.
     *
     * Suspension first: a suspended site is not serving a banner at all, so
     * everything below it is describing a page nobody sees.
     *
     * @param array<string, mixed>                                                   $row
     * @param array{pass: int, fail: int, warn: int, unknown: int, pct: ?int, failed: array<int, array<string, string>>} $configured
     * @param array{state: string, magnitude: int, cookies: int}                      $observed
     * @param array{measured: bool, scanned: bool, traffic: bool, reason: ?string}    $coverage
     */
    private function status(array $row, array $configured, array $observed, array $coverage): string
    {
        if (($row['suspended_reason'] ?? '') !== '' && $row['suspended_reason'] !== null) {
            return 'suspended';
        }

        if ((string) ($row['status'] ?? '') !== 'active') {
            return 'inactive';
        }

        if ($observed['state'] === 'violations') {
            return 'failing';
        }

        if ($configured['fail'] > 0) {
            return 'failing';
        }

        // Configured without a hard failure, nothing observed against it — but
        // if we have not measured anything, "ok" would be a claim we cannot
        // support.
        if (!$coverage['measured']) {
            return 'unknown';
        }

        if ($configured['warn'] > 0 || !$coverage['scanned'] || $observed['state'] === 'not_measured') {
            return 'attention';
        }

        return 'ok';
    }

    /**
     * MariaDB hands booleans back as "1"/"0" strings through PDO, and a genuinely
     * absent value as null. Treating "0" as truthy — which a naive cast does not,
     * but a naive `!empty()` on "0" would — is exactly the kind of bug that
     * silently marks failing sites as passing.
     */
    private function truthy(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value === 1 || $value === true || $value === 'true';
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
