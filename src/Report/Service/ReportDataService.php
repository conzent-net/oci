<?php

declare(strict_types=1);

namespace OCI\Report\Service;

use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Modules\ABTest\Repository\ABTestRepositoryInterface;
use OCI\Modules\ABTest\Repository\RevenueImpactRepositoryInterface;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Gathers data from cross-domain repositories for report generation.
 */
final class ReportDataService
{
    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LoggerInterface $logger,
        private readonly ?ABTestRepositoryInterface $abTestRepo = null,
        private readonly ?RevenueImpactRepositoryInterface $revenueRepo = null,
    ) {}

    /**
     * Gather consent section data: summary, trend, period comparison.
     *
     * @return array{summary: array, trend: array, comparison: array}
     */
    public function getConsentSection(int $siteId, string $start, string $end): array
    {
        $summary = $this->consentRepo->getConsentSummaryByDateRange($siteId, $start, $end);

        // Calculate days between start and end for trend + comparison
        $startDate = new \DateTimeImmutable($start);
        $endDate = new \DateTimeImmutable($end);
        $days = max(1, (int) $startDate->diff($endDate)->days);

        $trend = $this->consentRepo->getDailyTrend($siteId, $days);
        $comparison = $this->consentRepo->getPeriodComparison($siteId, $days);

        // Normalize summary into keyed format
        $totals = ['accepted' => 0, 'rejected' => 0, 'partially_accepted' => 0, 'total' => 0];
        foreach ($summary as $row) {
            $status = $row['consent_status'] ?? '';
            $count = (int) ($row['total'] ?? 0);

            if (\in_array($status, ['accepted', '1'], true)) {
                $totals['accepted'] += $count;
            } elseif (\in_array($status, ['rejected', '0'], true)) {
                $totals['rejected'] += $count;
            } elseif (\in_array($status, ['partially_accepted', '2'], true)) {
                $totals['partially_accepted'] += $count;
            }

            $totals['total'] += $count;
        }

        // Calculate rates
        $total = $totals['total'];
        $rates = [
            'acceptance_rate' => $total > 0 ? round($totals['accepted'] / $total * 100, 1) : 0,
            'rejection_rate' => $total > 0 ? round($totals['rejected'] / $total * 100, 1) : 0,
            'partial_rate' => $total > 0 ? round($totals['partially_accepted'] / $total * 100, 1) : 0,
        ];

        return [
            'summary' => array_merge($totals, $rates),
            'trend' => $trend,
            'comparison' => $comparison,
        ];
    }

    /**
     * Gather scan/cookie compliance section data.
     *
     * @return array{violations: array, violation_count: int, total_observed: int, last_scan: ?array, grouped: array}
     */
    public function getScanSection(int $siteId, string $start, string $end): array
    {
        $violations = $this->scanRepo->getPreConsentObservations($siteId, $start, $end);
        $lastScan = $this->scanRepo->getLastCompletedScan($siteId);

        // Group violations by category
        $grouped = [];
        $totalPreConsent = 0;
        $totalObserved = 0;

        foreach ($violations as $v) {
            $cat = $v['category_slug'] ?? 'uncategorized';
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = ['category' => $cat, 'cookies' => [], 'subtotal' => 0];
            }
            $grouped[$cat]['cookies'][] = $v;
            $grouped[$cat]['subtotal'] += (int) $v['total_pre_consent'];
            $totalPreConsent += (int) $v['total_pre_consent'];
            $totalObserved += (int) $v['total_pre_consent'] + (int) $v['total_post_consent'];
        }

        return [
            'violations' => $violations,
            'violation_count' => $totalPreConsent,
            'total_observed' => $totalObserved,
            'last_scan' => $lastScan,
            'grouped' => array_values($grouped),
        ];
    }

    /**
     * Gather A/B test section data (optional — returns null if no data).
     *
     * @return array{experiments: array, impact: array}|null
     */
    public function getABTestSection(int $siteId): ?array
    {
        if ($this->abTestRepo === null || $this->revenueRepo === null) {
            return null;
        }

        try {
            $experiments = $this->abTestRepo->getExperimentsBySite($siteId);
            if (empty($experiments)) {
                return null;
            }

            $results = [];
            foreach ($experiments as $exp) {
                $expId = (int) $exp['id'];
                $variants = $this->abTestRepo->getVariantStats($expId);
                $marketing = $this->revenueRepo->getMarketingConsentByVariant($expId);
                $snapshots = $this->revenueRepo->getLatestSnapshots($expId);

                $results[] = [
                    'experiment' => $exp,
                    'variants' => $variants,
                    'marketing' => $marketing,
                    'impact' => $snapshots,
                ];
            }

            return [
                'experiments' => $results,
                'impact' => $results,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('A/B test data unavailable for report', [
                'siteId' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get site info for report header.
     *
     * @return array<string, mixed>
     */
    public function getSiteInfo(int $siteId): array
    {
        return $this->siteRepo->findById($siteId) ?? [];
    }
}
