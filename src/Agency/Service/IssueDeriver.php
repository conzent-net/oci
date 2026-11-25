<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

/**
 * Turn one site's signals into a ranked list of things worth doing.
 *
 * ## Vocabulary is reused, not invented
 *
 * The configuration keys come verbatim from
 * `ComplianceCheckService::checkFrameworkCompliance()` — `no_banner_configured`,
 * `gcm_required`, `iab_tcf_recommended` and the rest. A second vocabulary
 * describing the same conditions in different words would guarantee that the
 * agency view and the client's own dashboard eventually disagree about what is
 * wrong, which is worse than either being wrong on its own.
 *
 * The same discipline applies between this class and {@see SiteHealthScorer}:
 * which configuration checks exist and how severe they are is the scorer's
 * table, read from the score passed in. This class only supplies the words.
 *
 * ## Ranking
 *
 * `severity × blast radius + age`. Blast radius is logarithmic in pageviews, so
 * a failing site with a million monthly views outranks a failing brochure site
 * without swamping the list with one client. That is the answer to "who do I
 * look at first", which is the only question this screen exists to answer.
 *
 * ## What is deliberately not claimed
 *
 * `gcm_enabled` and `iab_support` are **configuration**, and the labels say so.
 * A toggle being on does not prove Consent Mode fires correctly — the
 * diagnostics module has a separate check for exactly that contradiction — and
 * `iab_support: true` does not make a site TCF-valid. Calling either
 * "compliant" is the single most likely way this product embarrasses itself in
 * front of a client's legal team.
 *
 * Pure: signals in, issues out.
 */
final class IssueDeriver
{
    public const SEVERITY_WEIGHT = ['fail' => 100, 'warn' => 40, 'info' => 10];

    /**
     * Title, detail and destination for each configuration check the scorer
     * can fail. Keyed by the issue key in
     * {@see SiteHealthScorer::CONFIGURATION_CHECKS}; severity is deliberately
     * NOT repeated here, so there is one place it can be wrong.
     */
    private const CONFIGURATION_COPY = [
        'no_banner_configured' => [
            'title' => 'No banner configured',
            'detail' => 'This site has no consent banner at all, so nothing is being asked of visitors.',
            'action' => '/banners',
        ],
        'no_cookie_policy' => [
            'title' => 'No cookie policy',
            'detail' => 'There is no cookie policy generated in Conzent to link from the banner. If the client hosts '
                . 'one elsewhere this is not a gap, only something Conzent cannot see.',
            'action' => '/policies',
        ],
        'no_privacy_policy' => [
            'title' => 'No privacy policy',
            'detail' => 'There is no privacy policy generated in Conzent for this site. If the client hosts one '
                . 'elsewhere this is not a gap, only something Conzent cannot see.',
            'action' => '/policies',
        ],
        'gcm_required' => [
            'title' => 'Google Consent Mode not configured',
            'detail' => 'Consent Mode is switched off in the site settings. Note this reflects configuration only — '
                . 'it does not confirm how tags actually behave on the page.',
            'action' => '/banners',
        ],
    ];

    /**
     * How much an issue has to worsen before a snooze granted against it is
     * torn up. A snooze on "3 cookies before consent" must not stay quiet at
     * 400, and a snooze that reopens on every trivial fluctuation is one
     * nobody will use twice.
     */
    public const REOPEN_THRESHOLD = 1.25;

    /**
     * A stable fingerprint of what an issue was when a decision was taken.
     *
     * Magnitude is bucketed logarithmically rather than used raw: pre-consent
     * counts move a little every day, and a hash over the exact number would
     * reopen every snooze overnight.
     */
    public function evidenceHash(string $key, int $magnitude): string
    {
        $bucket = $magnitude <= 0 ? 0 : (int) floor(log10($magnitude) * 4);

        return md5($key . '|' . $bucket);
    }

    /**
     * Should a decision be torn up because the evidence moved?
     *
     * Only ever reopens on things getting WORSE. An issue that improved is
     * still an issue somebody chose to park, and reopening it would punish the
     * agency for progress.
     */
    public function shouldReopen(int $decidedMagnitude, int $currentMagnitude): bool
    {
        if ($decidedMagnitude <= 0) {
            // Parked when there was nothing to measure; any real evidence now
            // is new information.
            return $currentMagnitude > 0;
        }

        return $currentMagnitude > $decidedMagnitude * self::REOPEN_THRESHOLD;
    }

    /**
     * @param array<string, mixed> $row   raw signals
     * @param array<string, mixed> $score the scorer's verdict for the same row
     *
     * @return array<int, array{key: string, severity: string, title: string, detail: string, magnitude: int, rank: float, action: ?string}>
     */
    public function derive(array $row, array $score, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $issues = [];
        $pageviews = (int) ($row['pageviews_30d'] ?? 0);

        // ── Suspension: the client's banner is off. Nothing else matters. ──
        if (($row['suspended_reason'] ?? null) !== null && $row['suspended_reason'] !== '') {
            $issues[] = $this->issue(
                'site_suspended',
                'fail',
                'Site suspended',
                'The banner is not being served. With client-direct billing this is usually a lapsed subscription, '
                . 'which the agency cannot fix from here — but the client needs telling.',
                0,
                $pageviews,
                null,
            );

            // Everything below describes a banner nobody is seeing.
            return $this->rank($issues);
        }

        // ── Observed evidence: the only legally actionable signal here. ──
        $preConsent = (int) ($row['preconsent_hits_7d'] ?? 0);

        if ($preConsent > 0) {
            $cookies = (int) ($row['preconsent_cookies_7d'] ?? 0);
            $issues[] = $this->issue(
                'preconsent_cookies',
                'fail',
                'Cookies set before consent',
                sprintf(
                    '%s cookie%s observed setting before consent over the 7 days to %s, %s time%s in total.',
                    number_format($cookies),
                    $cookies === 1 ? '' : 's',
                    (string) ($row['preconsent_window_end'] ?? 'recently'),
                    number_format($preConsent),
                    $preConsent === 1 ? '' : 's',
                ),
                $preConsent,
                $pageviews,
                '/cookies?site_id=' . (int) $row['site_id'],
            );
        }

        // ── Configuration. Cheap to check, cheap to fix, weak as evidence. ──
        //
        // Which checks exist and how bad each one is comes from the scorer's
        // table, via the score it already produced for this row. One list, so
        // the status and the issues cannot disagree about what is wrong.
        $siteId = (int) $row['site_id'];

        foreach ($score['configured']['failed'] ?? [] as $failedCheck) {
            $copy = self::CONFIGURATION_COPY[$failedCheck['issue']] ?? null;

            if ($copy === null) {
                continue;
            }

            $issues[] = $this->issue(
                $failedCheck['issue'],
                $failedCheck['severity'],
                $copy['title'],
                $copy['detail'],
                0,
                $pageviews,
                $copy['action'] . '?site_id=' . $siteId,
            );
        }

        // ── Coverage. Not failures — reasons the rest cannot be trusted. ──
        $lastScan = $this->date($row['last_scan_at'] ?? null);

        if ($lastScan === null) {
            $issues[] = $this->issue(
                'scan_never_run',
                'warn',
                'Never scanned',
                'Nothing on this site has been checked, so a clean result here means nothing yet.',
                0,
                $pageviews,
                '/scans?site_id=' . (int) $row['site_id'],
            );
        } elseif ($lastScan < $now->modify('-' . SiteHealthScorer::SCAN_STALE_DAYS . ' days')) {
            $issues[] = $this->issue(
                'scan_stale_30d',
                'warn',
                'Scan is out of date',
                'Last scanned ' . $lastScan->format('j M Y') . '. Anything added since then is unverified.',
                (int) $now->diff($lastScan)->days,
                $pageviews,
                '/scans?site_id=' . (int) $row['site_id'],
            );
        }

        if ((int) ($row['failed_scans'] ?? 0) > 0 && (string) ($row['last_scan_status'] ?? '') === 'failed') {
            $issues[] = $this->issue(
                'scan_failed',
                'warn',
                'Last scan failed',
                'The most recent scan did not complete. There is no error detail stored against a scan, '
                . 'only its status, so this needs opening to find out why.',
                (int) $row['failed_scans'],
                $pageviews,
                '/scans?site_id=' . (int) $row['site_id'],
            );
        }

        if ($pageviews === 0) {
            $issues[] = $this->issue(
                'no_traffic',
                'info',
                'No traffic in 30 days',
                'Nothing has been observed because nobody visited. A clean result on this site is not evidence '
                . 'of anything.',
                0,
                $pageviews,
                null,
            );
        }

        // ── Recommendations, lowest priority. ──
        if (!$this->truthy($row['iab_support'] ?? null) && $pageviews > 0) {
            $issues[] = $this->issue(
                'iab_tcf_recommended',
                'info',
                'IAB TCF not enabled',
                'Worth considering if this site sells programmatic advertising in the EU. Configuration only — '
                . 'enabling the toggle does not by itself make a site TCF-valid.',
                0,
                $pageviews,
                '/banners?site_id=' . (int) $row['site_id'],
            );
        }

        return $this->rank($issues);
    }

    /**
     * @return array{key: string, severity: string, title: string, detail: string, magnitude: int, rank: float, action: ?string}
     */
    private function issue(
        string $key,
        string $severity,
        string $title,
        string $detail,
        int $magnitude,
        int $pageviews,
        ?string $action,
    ): array {
        // Logarithmic so one very large client cannot bury everything else,
        // while still putting a failing high-traffic site above a failing
        // brochure site — which is the actual triage question.
        $blastRadius = 1 + log10(1 + max(0, $pageviews));

        return [
            'key' => $key,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'magnitude' => $magnitude,
            'rank' => round(self::SEVERITY_WEIGHT[$severity] * $blastRadius, 2),
            'action' => $action,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     *
     * @return array<int, array<string, mixed>>
     */
    private function rank(array $issues): array
    {
        usort($issues, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return $issues;
    }

    private function truthy(mixed $value): bool
    {
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
