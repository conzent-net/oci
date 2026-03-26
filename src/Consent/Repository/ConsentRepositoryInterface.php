<?php

declare(strict_types=1);

namespace OCI\Consent\Repository;

interface ConsentRepositoryInterface
{
    /**
     * Get consent summary grouped by status for a site within last N days.
     *
     * @return array<int, array{consent_status: string, total: int}>
     */
    public function getConsentSummaryByDays(int $siteId, int $days): array;

    /**
     * Get consent summary grouped by status for a site (all time).
     *
     * @return array<int, array{consent_status: string, total: int}>
     */
    public function getConsentSummary(int $siteId): array;

    /**
     * Get consent summary by custom date range.
     *
     * @return array<int, array{consent_status: string, total: int}>
     */
    public function getConsentSummaryByDateRange(int $siteId, string $startDate, string $endDate): array;

    /**
     * Get recent consent log entries.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLog(int $siteId, int $limit = 10): array;

    /**
     * Upsert a consent record. Updates if consent_session exists for site, inserts otherwise.
     *
     * @return int The consent ID (existing or newly created)
     */
    public function upsertConsent(string $consentSession, int $siteId, array $data): int;

    /**
     * Delete existing categories and insert new ones for a consent record.
     *
     * @param array<string, string> $categories [slug => 'accepted'|'rejected']
     */
    public function replaceConsentCategories(int $consentId, array $categories): void;

    /**
     * Increment daily consent stats counter.
     */
    public function incrementDailyStats(int $siteId, string $status, ?int $variantId): void;

    /**
     * Get paginated consent log with optional filters.
     *
     * @param array{status?: string, date_from?: string, date_to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getConsentLog(int $siteId, int $page, int $perPage, array $filters = []): array;

    /**
     * Count total consent records matching filters (for pagination).
     *
     * @param array{status?: string, date_from?: string, date_to?: string} $filters
     */
    public function getConsentLogCount(int $siteId, array $filters = []): int;

    /**
     * Get a single consent record with its per-category breakdown.
     *
     * @return array<string, mixed>|null
     */
    public function getConsentWithCategories(int $consentId): ?array;

    /**
     * Get daily consent trend data for charts.
     *
     * @return array<int, array{stat_date: string, accepted: int, rejected: int, partially_accepted: int, total_consents: int}>
     */
    public function getDailyTrend(int $siteId, int $days, ?int $variantId = null): array;

    /**
     * Get period comparison: current N days vs previous N days.
     *
     * @return array{current: array{accepted: int, rejected: int, partially_accepted: int, total: int}, previous: array{accepted: int, rejected: int, partially_accepted: int, total: int}}
     */
    public function getPeriodComparison(int $siteId, int $days): array;
}
