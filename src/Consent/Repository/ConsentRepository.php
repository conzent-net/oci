<?php

declare(strict_types=1);

namespace OCI\Consent\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class ConsentRepository implements ConsentRepositoryInterface
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    public function getConsentSummaryByDays(int $siteId, int $days): array
    {
        $sql = <<<'SQL'
            SELECT consent_status, COUNT(*) AS total
            FROM oci_consents
            WHERE site_id = :siteId
              AND consent_date > DATE_SUB(NOW(), INTERVAL :days DAY)
            GROUP BY consent_status
        SQL;

        /** @var array<int, array{consent_status: string, total: int}> */
        return $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'days' => $days,
        ], [
            'siteId' => ParameterType::INTEGER,
            'days' => ParameterType::INTEGER,
        ]);
    }

    public function getConsentSummary(int $siteId): array
    {
        $sql = <<<'SQL'
            SELECT consent_status, COUNT(*) AS total
            FROM oci_consents
            WHERE site_id = :siteId
            GROUP BY consent_status
        SQL;

        /** @var array<int, array{consent_status: string, total: int}> */
        return $this->db->fetchAllAssociative($sql, ['siteId' => $siteId]);
    }

    public function getConsentSummaryByDateRange(int $siteId, string $startDate, string $endDate): array
    {
        $sql = <<<'SQL'
            SELECT consent_status, COUNT(*) AS total
            FROM oci_consents
            WHERE site_id = :siteId
              AND DATE(consent_date) BETWEEN :startDate AND :endDate
            GROUP BY consent_status
        SQL;

        /** @var array<int, array{consent_status: string, total: int}> */
        return $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    public function getRecentLog(int $siteId, int $limit = 10): array
    {
        $sql = <<<'SQL'
            SELECT id, consent_session, consent_status, consent_date, ip_address, country
            FROM oci_consents
            WHERE site_id = :siteId
            ORDER BY id DESC
            LIMIT :lim
        SQL;

        /** @var array<int, array<string, mixed>> */
        return $this->db->fetchAllAssociative($sql, [
            'siteId' => $siteId,
            'lim' => $limit,
        ], [
            'siteId' => ParameterType::INTEGER,
            'lim' => ParameterType::INTEGER,
        ]);
    }

    public function upsertConsent(string $consentSession, int $siteId, array $data): int
    {
        // Always insert a new row — every consent action is an immutable audit record
        $this->db->insert('oci_consents', $data);

        return (int) $this->db->lastInsertId();
    }

    public function replaceConsentCategories(int $consentId, array $categories): void
    {
        // Delete existing categories
        $this->db->executeStatement(
            'DELETE FROM oci_consent_categories WHERE consent_id = :consentId',
            ['consentId' => $consentId],
        );

        // Insert new categories
        foreach ($categories as $slug => $status) {
            $this->db->insert('oci_consent_categories', [
                'consent_id' => $consentId,
                'category_slug' => $slug,
                'consent_status' => $status,
            ]);
        }
    }

    public function incrementDailyStats(int $siteId, string $status, ?int $variantId): void
    {
        $accepted = $status === 'accepted' ? 1 : 0;
        $rejected = $status === 'rejected' ? 1 : 0;
        $partial = $status === 'partially_accepted' ? 1 : 0;

        $this->db->executeStatement(
            'INSERT INTO oci_consent_daily_stats
                (site_id, stat_date, variant_id, total_consents, accepted, rejected, partially_accepted)
             VALUES (:siteId, CURDATE(), :variantId, 1, :acc, :rej, :part)
             ON DUPLICATE KEY UPDATE
                total_consents = total_consents + 1,
                accepted = accepted + VALUES(accepted),
                rejected = rejected + VALUES(rejected),
                partially_accepted = partially_accepted + VALUES(partially_accepted)',
            [
                'siteId' => $siteId,
                'variantId' => $variantId,
                'acc' => $accepted,
                'rej' => $rejected,
                'part' => $partial,
            ],
            [
                'siteId' => ParameterType::INTEGER,
                'variantId' => $variantId !== null ? ParameterType::INTEGER : ParameterType::NULL,
                'acc' => ParameterType::INTEGER,
                'rej' => ParameterType::INTEGER,
                'part' => ParameterType::INTEGER,
            ],
        );
    }

    public function getConsentLog(int $siteId, int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('id', 'consent_session', 'consent_status', 'consent_date', 'ip_address', 'country', 'consented_domain', 'language')
            ->from('oci_consents')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId, ParameterType::INTEGER)
            ->orderBy('id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->applyFilters($qb, $filters);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function getConsentLogCount(int $siteId, array $filters = []): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('oci_consents')
            ->where('site_id = :siteId')
            ->setParameter('siteId', $siteId, ParameterType::INTEGER);

        $this->applyFilters($qb, $filters);

        return (int) $qb->executeQuery()->fetchOne();
    }

    public function getConsentWithCategories(int $consentId): ?array
    {
        $consent = $this->db->fetchAssociative(
            'SELECT * FROM oci_consents WHERE id = :id',
            ['id' => $consentId],
        );

        if ($consent === false) {
            return null;
        }

        $categories = $this->db->fetchAllAssociative(
            'SELECT category_slug, consent_status FROM oci_consent_categories WHERE consent_id = :id',
            ['id' => $consentId],
        );

        $consent['categories'] = $categories;

        return $consent;
    }

    public function getDailyTrend(int $siteId, int $days, ?int $variantId = null): array
    {
        try {
            $params = ['siteId' => $siteId, 'days' => $days];
            $types = ['siteId' => ParameterType::INTEGER, 'days' => ParameterType::INTEGER];

            $variantClause = '';
            if ($variantId !== null) {
                $variantClause = 'AND variant_id = :variantId';
                $params['variantId'] = $variantId;
                $types['variantId'] = ParameterType::INTEGER;
            } else {
                $variantClause = 'AND variant_id IS NULL';
            }

            $sql = <<<SQL
                SELECT stat_date, accepted, rejected, partially_accepted, total_consents
                FROM oci_consent_daily_stats
                WHERE site_id = :siteId
                  AND stat_date > DATE_SUB(CURDATE(), INTERVAL :days DAY)
                  {$variantClause}
                ORDER BY stat_date ASC
            SQL;

            return $this->db->fetchAllAssociative($sql, $params, $types);
        } catch (\Throwable) {
            // Stats table may not exist yet — fall back to raw consents
            return $this->getDailyTrendFromRaw($siteId, $days);
        }
    }

    /**
     * Fallback: compute daily trend from oci_consents when stats table is unavailable.
     */
    private function getDailyTrendFromRaw(int $siteId, int $days): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT DATE(consent_date) AS stat_date,
                    SUM(consent_status IN ("accepted","1")) AS accepted,
                    SUM(consent_status IN ("rejected","0")) AS rejected,
                    SUM(consent_status IN ("partially_accepted","2")) AS partially_accepted,
                    COUNT(*) AS total_consents
             FROM oci_consents
             WHERE site_id = :siteId
               AND consent_date > DATE_SUB(NOW(), INTERVAL :days DAY)
             GROUP BY DATE(consent_date)
             ORDER BY stat_date ASC',
            ['siteId' => $siteId, 'days' => $days],
            ['siteId' => ParameterType::INTEGER, 'days' => ParameterType::INTEGER],
        );
    }

    public function getPeriodComparison(int $siteId, int $days): array
    {
        $emptyPeriod = ['accepted' => 0, 'rejected' => 0, 'partially_accepted' => 0, 'total' => 0];

        try {
            $doubleDays = $days * 2;

            $current = $this->db->fetchAssociative(
                'SELECT COALESCE(SUM(accepted),0) AS accepted, COALESCE(SUM(rejected),0) AS rejected,
                        COALESCE(SUM(partially_accepted),0) AS partially_accepted, COALESCE(SUM(total_consents),0) AS total
                 FROM oci_consent_daily_stats
                 WHERE site_id = :siteId AND variant_id IS NULL
                   AND stat_date > DATE_SUB(CURDATE(), INTERVAL :days DAY)',
                ['siteId' => $siteId, 'days' => $days],
                ['siteId' => ParameterType::INTEGER, 'days' => ParameterType::INTEGER],
            );

            $previous = $this->db->fetchAssociative(
                'SELECT COALESCE(SUM(accepted),0) AS accepted, COALESCE(SUM(rejected),0) AS rejected,
                        COALESCE(SUM(partially_accepted),0) AS partially_accepted, COALESCE(SUM(total_consents),0) AS total
                 FROM oci_consent_daily_stats
                 WHERE site_id = :siteId AND variant_id IS NULL
                   AND stat_date <= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                   AND stat_date > DATE_SUB(CURDATE(), INTERVAL :dblDays DAY)',
                ['siteId' => $siteId, 'days' => $days, 'dblDays' => $doubleDays],
                ['siteId' => ParameterType::INTEGER, 'days' => ParameterType::INTEGER, 'dblDays' => ParameterType::INTEGER],
            );

            return [
                'current' => [
                    'accepted' => (int) ($current['accepted'] ?? 0),
                    'rejected' => (int) ($current['rejected'] ?? 0),
                    'partially_accepted' => (int) ($current['partially_accepted'] ?? 0),
                    'total' => (int) ($current['total'] ?? 0),
                ],
                'previous' => [
                    'accepted' => (int) ($previous['accepted'] ?? 0),
                    'rejected' => (int) ($previous['rejected'] ?? 0),
                    'partially_accepted' => (int) ($previous['partially_accepted'] ?? 0),
                    'total' => (int) ($previous['total'] ?? 0),
                ],
            ];
        } catch (\Throwable) {
            // Stats table may not exist yet
            return ['current' => $emptyPeriod, 'previous' => $emptyPeriod];
        }
    }

    /**
     * @param \Doctrine\DBAL\Query\QueryBuilder $qb
     * @param array{status?: string, date_from?: string, date_to?: string} $filters
     */
    private function applyFilters(\Doctrine\DBAL\Query\QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['status'])) {
            $qb->andWhere('consent_status = :status')
                ->setParameter('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $qb->andWhere('DATE(consent_date) >= :dateFrom')
                ->setParameter('dateFrom', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $qb->andWhere('DATE(consent_date) <= :dateTo')
                ->setParameter('dateTo', $filters['date_to']);
        }
    }
}
