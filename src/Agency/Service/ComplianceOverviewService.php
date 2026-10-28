<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use OCI\Agency\Repository\IssueStateRepositoryInterface;
use OCI\Agency\Repository\SiteHealthRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The multi-client compliance view: every client, ranked, with what to fix.
 *
 * ## Why live queries and not a snapshot pipeline
 *
 * Production holds 21 sites. A cross-client query over 21 rows is free, and a
 * snapshot table would add a second staleness axis, a scheduler dependency and
 * a reconciliation problem to solve a scaling issue that does not exist. The
 * shape here is deliberately the one the backup inventory already uses: query
 * live, cache briefly, show when it was checked, and offer a refresh. When
 * client counts justify a roll-up table, the repository is the only thing that
 * has to change.
 *
 * ## The freshness contract is displayed, not assumed
 *
 * Every number on this screen carries a different age — configuration is
 * current, pre-consent evidence is a 7-day window ending yesterday, a scan is
 * as old as the last scan. Presenting them as one timestamp would be a lie of
 * exactly the sort this feature is supposed to avoid, so each is labelled where
 * it appears and `checked_at` describes only the query itself.
 *
 * The cache itself lives in {@see AgencyHealthCache}, so that everything which
 * changes an agency's book — linking, unlinking, approval, suspension, a
 * template applied or reverted — can clear it without depending on this class.
 */
final class ComplianceOverviewService
{
    public function __construct(
        private readonly SiteHealthRepositoryInterface $health,
        private readonly SiteHealthScorer $scorer,
        private readonly IssueDeriver $deriver,
        private readonly IssueStateRepositoryInterface $issueState,
        private readonly AgencyHealthCache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     clients: array<int, array<string, mixed>>,
     *     sites: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     issue_groups: array<int, array<string, mixed>>,
     *     checked_at: int,
     *     cached: bool,
     *     window: array{start: string, end: string}
     * }
     */
    public function overview(AgencyScope $scope, bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = $this->cache->read($scope->agencyId());

            if ($cached !== null) {
                $cached['cached'] = true;

                return $cached;
            }
        }

        $built = $this->build($scope);
        $this->cache->write($scope->agencyId(), $built);

        return $built;
    }

    public function forget(AgencyScope $scope): void
    {
        $this->cache->forget($scope->agencyId());
    }

    /** @return array<string, mixed> */
    private function build(AgencyScope $scope): array
    {
        $now = new \DateTimeImmutable();
        $signals = $this->health->signalsForAgency($scope);
        $decisions = $this->issueState->forSites(array_keys($signals));
        $stillOpen = [];
        $hidden = 0;

        $sites = [];
        $clients = [];
        $issueGroups = [];
        $totals = ['ok' => 0, 'attention' => 0, 'failing' => 0, 'unknown' => 0, 'suspended' => 0, 'inactive' => 0];
        $windowStart = '';
        $windowEnd = '';

        foreach ($signals as $siteId => $row) {
            $score = $this->scorer->score($row, $now);
            $derived = $this->deriver->derive($row, $score, $now);

            // Every derived issue counts as "still open" for resolution
            // purposes, whether or not it is being displayed. A snoozed issue
            // has not gone away; hiding it must not mark it resolved.
            foreach ($derived as $issue) {
                $stillOpen[] = $siteId . ':' . $issue['key'];
            }

            $issues = $this->applyDecisions($siteId, $derived, $decisions, $now);
            $hidden += \count($derived) - \count($issues);

            $windowStart = (string) ($row['preconsent_window_start'] ?? $windowStart);
            $windowEnd = (string) ($row['preconsent_window_end'] ?? $windowEnd);

            $site = $score + [
                'client_email' => (string) ($row['client_email'] ?? ''),
                'user_id' => (int) ($row['user_id'] ?? 0),
                'pageviews_30d' => (int) ($row['pageviews_30d'] ?? 0),
                'last_scan_at' => $row['last_scan_at'] ?? null,
                'issues' => $issues,
                'top_issue' => $issues[0] ?? null,
            ];

            $sites[$siteId] = $site;
            $totals[$score['status']] = ($totals[$score['status']] ?? 0) + 1;

            // Group by issue first: "fix this across everyone" is the agency's
            // real workflow, and it keeps the panel bounded at roughly twenty
            // rows however many clients there are.
            foreach ($issues as $issue) {
                $key = $issue['key'];
                $issueGroups[$key] ??= [
                    'key' => $key,
                    'severity' => $issue['severity'],
                    'title' => $issue['title'],
                    'detail' => $issue['detail'],
                    'sites' => 0,
                    'clients' => [],
                    'rank' => 0.0,
                    'worst' => null,
                ];
                ++$issueGroups[$key]['sites'];
                $issueGroups[$key]['clients'][(int) $row['user_id']] = true;
                $issueGroups[$key]['rank'] = max($issueGroups[$key]['rank'], $issue['rank']);

                if ($issueGroups[$key]['worst'] === null || $issue['magnitude'] > $issueGroups[$key]['worst']['magnitude']) {
                    $issueGroups[$key]['worst'] = [
                        'domain' => $site['domain'],
                        'magnitude' => $issue['magnitude'],
                        'action' => $issue['action'],
                    ];
                }
            }

            $userId = (int) $row['user_id'];
            $clients[$userId] ??= [
                'user_id' => $userId,
                'email' => (string) ($row['client_email'] ?? ''),
                'sites' => 0,
                'measured' => 0,
                'status' => 'ok',
                'worst_rank' => 0.0,
                'top_issue' => null,
                'pageviews_30d' => 0,
            ];
            ++$clients[$userId]['sites'];
            $clients[$userId]['measured'] += $score['coverage']['measured'] ? 1 : 0;
            $clients[$userId]['pageviews_30d'] += (int) ($row['pageviews_30d'] ?? 0);
            $clients[$userId]['status'] = $this->worse($clients[$userId]['status'], $score['status']);

            if ($site['top_issue'] !== null && $site['top_issue']['rank'] > $clients[$userId]['worst_rank']) {
                $clients[$userId]['worst_rank'] = $site['top_issue']['rank'];
                $clients[$userId]['top_issue'] = $site['top_issue'];
            }
        }

        foreach ($issueGroups as &$group) {
            $group['clients'] = \count($group['clients']);
        }
        unset($group);

        usort($issueGroups, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);
        usort($clients, static fn (array $a, array $b): int => $b['worst_rank'] <=> $a['worst_rank']);

        // Anything that stopped deriving is fixed. Doing this here rather than
        // in a scheduled sweep means a resolved issue disappears the next time
        // somebody looks, instead of lingering until a cron runs.
        try {
            $this->issueState->resolveDisappeared(array_keys($signals), $stillOpen);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not resolve disappeared issues: ' . $e->getMessage());
        }

        return [
            'clients' => array_values($clients),
            'sites' => $sites,
            'totals' => $totals,
            'issue_groups' => array_values($issueGroups),
            'hidden_count' => $hidden,
            'checked_at' => time(),
            'cached' => false,
            'window' => ['start' => $windowStart, 'end' => $windowEnd],
        ];
    }

    /**
     * Drop issues somebody has parked — unless the evidence has moved against
     * them, in which case the decision is torn up and the issue comes back.
     *
     * This is the anti-rot mechanism. Without it a snooze is permanent in
     * practice: the issue key never changes, so a problem that grows tenfold
     * stays hidden behind a decision taken when it was trivial.
     *
     * @param array<int, array<string, mixed>>   $issues
     * @param array<string, array<string, mixed>> $decisions
     *
     * @return array<int, array<string, mixed>>
     */
    private function applyDecisions(int $siteId, array $issues, array $decisions, \DateTimeImmutable $now): array
    {
        $visible = [];

        foreach ($issues as $issue) {
            $decision = $decisions[$siteId . ':' . $issue['key']] ?? null;

            if ($decision === null || \in_array($decision['state'], ['open', 'resolved'], true)) {
                $visible[] = $issue;
                continue;
            }

            // A snooze with a date is over when the date passes.
            $until = $decision['snoozed_until'] ?? null;

            if ($decision['state'] === 'snoozed' && \is_string($until) && $until !== '' && $until < $now->format('Y-m-d')) {
                $issue['reopened'] = 'The snooze expired.';
                $visible[] = $issue;
                continue;
            }

            if ($this->deriver->shouldReopen((int) ($decision['magnitude'] ?? 0), (int) $issue['magnitude'])) {
                $issue['reopened'] = sprintf(
                    'Reopened: this was %s when it was parked and is %s now.',
                    number_format((int) ($decision['magnitude'] ?? 0)),
                    number_format((int) $issue['magnitude']),
                );
                $visible[] = $issue;
                continue;
            }

            // Genuinely parked: hidden, but still counted as open so it is
            // never mistaken for resolved.
        }

        return $visible;
    }

    /**
     * Worst status wins when rolling sites up to a client. A client with one
     * failing site out of six is a failing client — averaging would hide the
     * one thing worth acting on.
     */
    private function worse(string $current, string $candidate): string
    {
        $order = ['ok' => 0, 'inactive' => 1, 'unknown' => 2, 'attention' => 3, 'suspended' => 4, 'failing' => 5];

        return ($order[$candidate] ?? 0) > ($order[$current] ?? 0) ? $candidate : $current;
    }

}
