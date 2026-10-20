<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Syncs the Open Cookie Database into oci_cookies_global.
 *
 * Source: https://github.com/jkwakman/Open-Cookie-Database
 * Matches on cookie_id (UUID) — updates existing rows, inserts new ones.
 */
final class OpenCookieDatabaseSync
{
    private const SOURCE_URL = 'https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.json';

    private const CATEGORY_MAP = [
        'functional' => 'functional',
        'analytics'  => 'analytics',
        'marketing'  => 'marketing',
        'security'   => 'necessary',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array{inserted: int, updated: int, skipped: int, errors: int}
     */
    public function sync(bool $dryRun = false): array
    {
        $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $json = @file_get_contents(self::SOURCE_URL);
        if ($json === false) {
            $this->logger->error('Failed to fetch Open Cookie Database from ' . self::SOURCE_URL);
            throw new \RuntimeException('Failed to fetch Open Cookie Database');
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException('Invalid JSON structure: expected object with vendor keys');
        }

        $categoryMap = $this->loadCategoryMap();

        $this->db->beginTransaction();

        try {
            foreach ($data as $platform => $cookies) {
                if (!\is_array($cookies)) {
                    continue;
                }

                foreach ($cookies as $entry) {
                    $cookieId = trim($entry['id'] ?? '');
                    $cookieName = trim($entry['cookie'] ?? '');

                    if ($cookieId === '' || $cookieName === '') {
                        $stats['skipped']++;
                        continue;
                    }

                    $categorySlug = self::CATEGORY_MAP[strtolower($entry['category'] ?? '')] ?? null;
                    $categoryId = $categorySlug !== null ? ($categoryMap[$categorySlug] ?? null) : null;

                    $row = [
                        'cookie_name' => $cookieName,
                        'cookie_id' => $cookieId,
                        'platform' => (string) ($entry['dataController'] ?? $platform),
                        'category_id' => $categoryId,
                        'domain' => $entry['domain'] ?? '',
                        'description' => $entry['description'] ?? '',
                        'expiry_duration' => $entry['retentionPeriod'] ?? '',
                        'data_controller' => $entry['dataController'] ?? '',
                        'privacy_url' => $entry['privacyLink'] ?? '',
                        'wildcard_match' => (int) ($entry['wildcardMatch'] ?? 0),
                    ];

                    try {
                        $existing = $this->db->fetchOne(
                            'SELECT id FROM oci_cookies_global WHERE cookie_id = :cookieId',
                            ['cookieId' => $cookieId],
                        );

                        if ($existing !== false) {
                            if (!$dryRun) {
                                $this->db->update('oci_cookies_global', $row, ['cookie_id' => $cookieId]);
                            }
                            $stats['updated']++;
                        } else {
                            if (!$dryRun) {
                                $this->db->insert('oci_cookies_global', $row);
                            }
                            $stats['inserted']++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning("Failed to sync cookie '{$cookieName}' ({$cookieId}): {$e->getMessage()}");
                        $stats['errors']++;
                    }
                }
            }

            if ($dryRun) {
                $this->db->rollBack();
            } else {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $this->logger->info('Open Cookie Database sync complete', $stats);

        return $stats;
    }

    /**
     * @return array<string, int> slug → id
     */
    private function loadCategoryMap(): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, slug FROM oci_cookie_categories WHERE is_active = 1',
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['slug']] = (int) $row['id'];
        }

        return $map;
    }
}
