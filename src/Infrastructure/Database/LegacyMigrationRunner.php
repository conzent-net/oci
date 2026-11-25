<?php

/**
 * OCI Legacy Data Migration Runner.
 *
 * Idempotent migration from legacy Conzent tables to oci_* tables.
 * Can be run multiple times safely — uses UPSERT logic based on legacy_id.
 *
 * Usage:
 *   php bin/oci legacy:migrate <domain> [--dry-run] [--batch-size=1000]
 *   php bin/oci legacy:migrate all
 *
 * Domains: users, sites, languages, categories, cookies, consents, scans, policies, agencies
 *
 * The `plans` and `banners` domains were removed on 2026-09-10: `plans` wrote
 * to `oci_plans` and to `oci_subscriptions` columns that
 * Version20260315_001_RewriteBillingTables dropped, and `banners` copied v1's
 * `site_cookie_banners`, a table nothing in v1 reads (the real per-site banner
 * config is `user_banner_settings`, which the cloud edition's LegacyMigration
 * module maps). Column mapping lives in {@see LegacyRowMapper}, shared with
 * that module.
 *
 * Environment:
 *   LEGACY_EXCLUDE_USER_IDS=1,5,99   Comma-separated user IDs to skip during migration
 *   DATABASE_URL                       Database connection (same DB, new tables alongside old)
 */

declare(strict_types=1);

namespace OCI\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class LegacyMigrationRunner
{
    /** @var list<int> */
    private array $excludeUserIds;

    private int $batchSize;

    private bool $dryRun;

    /** @var array<string, callable> */
    private array $migrators;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {
        $excludeRaw = $_ENV['LEGACY_EXCLUDE_USER_IDS'] ?? '';
        $this->excludeUserIds = $excludeRaw !== ''
            ? array_map('intval', array_filter(explode(',', $excludeRaw), fn (string $v): bool => $v !== ''))
            : [];

        $this->batchSize = 1000;
        $this->dryRun = false;

        $this->migrators = [
            'users' => $this->migrateUsers(...),
            'sites' => $this->migrateSites(...),
            'languages' => $this->migrateLanguages(...),
            'categories' => $this->migrateCategories(...),
            'cookies' => $this->migrateCookies(...),
            'consents' => $this->migrateConsents(...),
            'scans' => $this->migrateScans(...),
            'policies' => $this->migratePolicies(...),
            'agencies' => $this->migrateAgencies(...),
        ];
    }

    public function setBatchSize(int $size): void
    {
        $this->batchSize = $size;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    /**
     * @return list<string> Available domain names
     */
    public function getAvailableDomains(): array
    {
        return array_keys($this->migrators);
    }

    /**
     * Run migration for a specific domain or all domains.
     *
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public function run(string $domain): array
    {
        if ($domain === 'all') {
            $totals = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];
            foreach ($this->migrators as $name => $migrator) {
                $this->log("=== Migrating: {$name} ===");
                $result = $this->runSingle($name, $migrator);
                $totals['migrated'] += $result['migrated'];
                $totals['skipped'] += $result['skipped'];
                $totals['errors'] += $result['errors'];
            }
            return $totals;
        }

        if (!isset($this->migrators[$domain])) {
            throw new \InvalidArgumentException("Unknown domain: {$domain}. Available: " . implode(', ', $this->getAvailableDomains()));
        }

        return $this->runSingle($domain, $this->migrators[$domain]);
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function runSingle(string $name, callable $migrator): array
    {
        $startedAt = new \DateTimeImmutable();
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];
        $errors = [];

        try {
            $result = $migrator();
        } catch (\Throwable $e) {
            $result['errors']++;
            $errors[] = $e->getMessage();
            $this->log("FATAL ERROR in {$name}: {$e->getMessage()}");
        }

        // Log the migration run
        if (!$this->dryRun) {
            $this->logMigrationRun($name, $result, $errors, $startedAt);
        }

        $this->log("  Result: migrated={$result['migrated']}, skipped={$result['skipped']}, errors={$result['errors']}");
        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  USERS
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateUsers(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT u.*, uc.company, uc.vat, uc.address, uc.zip, uc.city, uc.state, uc.phone, uc.country
                 FROM lgc_users u
                 LEFT JOIN lgc_users_company uc ON uc.user_id = u.id
                 ORDER BY u.id
                 LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                $legacyId = (int) $row['id'];

                if (\in_array($legacyId, $this->excludeUserIds, true)
                    || LegacyRowMapper::isStaffLevel((int) ($row['userlevel'] ?? 0))) {
                    // Excluded, or v1 staff: staff never become v2 users of any role.
                    $result['skipped']++;
                    continue;
                }

                if ($this->dryRun) {
                    $result['migrated']++;
                    continue;
                }

                try {
                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_users WHERE legacy_id = ?',
                        [$legacyId],
                    );

                    $userData = LegacyRowMapper::user($row, $legacyId);

                    if ($exists !== false) {
                        $this->db->update('oci_users', $userData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_users', $userData);
                    }

                    // Company data
                    if (LegacyRowMapper::companyHasContent($row)) {
                        $ociUserId = (int) $this->db->fetchOne(
                            'SELECT id FROM oci_users WHERE legacy_id = ?',
                            [$legacyId],
                        );

                        $companyExists = $this->db->fetchOne(
                            'SELECT id FROM oci_user_companies WHERE user_id = ?',
                            [$ociUserId],
                        );

                        $companyData = LegacyRowMapper::company($row) + ['user_id' => $ociUserId];

                        if ($companyExists !== false) {
                            $this->db->update('oci_user_companies', $companyData, ['user_id' => $ociUserId]);
                        } else {
                            $this->db->insert('oci_user_companies', $companyData);
                        }
                    }

                    $result['migrated']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $this->log("  ERROR user #{$legacyId}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
            $this->log("  Users processed: {$offset}");
        } while (\count($rows) === $this->batchSize);

        // Migrate API keys
        $this->migrateApiKeys($result);

        return $result;
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     */
    private function migrateApiKeys(array &$result): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_api ORDER BY id');

        foreach ($rows as $row) {
            $legacyUserId = (int) $row['user_id'];

            if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                continue;
            }

            if ($this->dryRun) {
                continue;
            }

            try {
                $ociUserId = $this->getOciUserId($legacyUserId);
                if ($ociUserId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_api_keys WHERE api_key = ?',
                    [$row['apikey']],
                );

                if ($exists === false) {
                    $this->db->insert('oci_api_keys', LegacyRowMapper::apiKey($row, $ociUserId));
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR api_key for user #{$legacyUserId}: {$e->getMessage()}");
            }
        }
    }

    // ════════════════════════════════════════════════════════
    //  SITES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateSites(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_user_sites ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                $legacyId = (int) $row['id'];
                $legacyUserId = (int) $row['user_id'];

                if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                    $result['skipped']++;
                    continue;
                }

                if ($this->dryRun) {
                    $result['migrated']++;
                    continue;
                }

                try {
                    $ociUserId = $this->getOciUserId($legacyUserId);
                    if ($ociUserId === null) {
                        $result['skipped']++;
                        continue;
                    }

                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_sites WHERE legacy_id = ?',
                        [$legacyId],
                    );

                    $siteData = LegacyRowMapper::site($row, $legacyId, $ociUserId);

                    if ($exists !== false) {
                        $this->db->update('oci_sites', $siteData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_sites', $siteData);
                    }

                    $result['migrated']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $this->log("  ERROR site #{$legacyId}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
            $this->log("  Sites processed: {$offset}");
        } while (\count($rows) === $this->batchSize);

        // Associated sites
        $this->migrateAssociatedSites($result);

        return $result;
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     */
    private function migrateAssociatedSites(array &$result): void
    {
        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_associated_sites ORDER BY id');

        foreach ($rows as $row) {
            if ($this->dryRun) {
                continue;
            }

            try {
                $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                if ($ociSiteId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_associated_sites WHERE site_id = ? AND domain = ?',
                    [$ociSiteId, $row['domain']],
                );

                if ($exists === false) {
                    $this->db->insert('oci_associated_sites', LegacyRowMapper::associatedSite($row, $ociSiteId));
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR associated_site #{$row['id']}: {$e->getMessage()}");
            }
        }
    }

    // ════════════════════════════════════════════════════════
    //  LANGUAGES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateLanguages(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_languages ORDER BY id');

        foreach ($rows as $row) {
            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_languages WHERE lang_code = ?',
                    [$row['lang_code']],
                );

                $langData = [
                    'lang_code' => $row['lang_code'],
                    'lang_name' => $row['lang_name'] ?? $row['lang_code'],
                    'is_default' => (int) ($row['is_default'] ?? 0),
                ];

                if ($exists !== false) {
                    $this->db->update('oci_languages', $langData, ['lang_code' => $row['lang_code']]);
                } else {
                    $this->db->insert('oci_languages', $langData);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR language #{$row['id']}: {$e->getMessage()}");
            }
        }

        // Site languages
        $siteLangs = $this->db->fetchAllAssociative('SELECT * FROM lgc_user_languages ORDER BY id');
        foreach ($siteLangs as $row) {
            if ($this->dryRun) {
                continue;
            }

            try {
                $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                $ociLangId = $this->getOciLanguageId($row['lang_code'] ?? '');
                if ($ociSiteId === null || $ociLangId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_site_languages WHERE site_id = ? AND language_id = ?',
                    [$ociSiteId, $ociLangId],
                );

                if ($exists === false) {
                    $this->db->insert('oci_site_languages', LegacyRowMapper::siteLanguage($row, $ociSiteId, $ociLangId));
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR site_language #{$row['id']}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  CATEGORIES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateCategories(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_cookie_category ORDER BY id');

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_categories WHERE legacy_id = ?',
                    [$legacyId],
                );

                $categoryData = [
                    'legacy_id' => $legacyId,
                    'slug' => $row['slug'] ?? "category_{$legacyId}",
                    'type' => (int) ($row['type'] ?? 2),
                    'sort_order' => (int) ($row['order'] ?? 0),
                    'is_active' => (int) ($row['active'] ?? 0),
                    'default_consent' => $row['default_consent'] ?: null,
                    'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                ];

                if ($exists !== false) {
                    $this->db->update('oci_cookie_categories', $categoryData, ['legacy_id' => $legacyId]);
                } else {
                    $this->db->insert('oci_cookie_categories', $categoryData);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR category #{$legacyId}: {$e->getMessage()}");
            }
        }

        // Category translations
        $translations = $this->db->fetchAllAssociative('SELECT * FROM lgc_cookie_category_lang ORDER BY id');
        foreach ($translations as $row) {
            if ($this->dryRun) {
                continue;
            }

            try {
                $ociCatId = $this->getOciCategoryId((int) $row['category_id']);
                $ociLangId = $this->getOciLanguageIdByLegacyId((int) $row['lang_id']);
                if ($ociCatId === null || $ociLangId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_category_translations WHERE category_id = ? AND language_id = ?',
                    [$ociCatId, $ociLangId],
                );

                $transData = [
                    'category_id' => $ociCatId,
                    'language_id' => $ociLangId,
                    'name' => $row['name'],
                    'description' => $row['description'] ?: null,
                ];

                if ($exists !== false) {
                    $this->db->update('oci_cookie_category_translations', $transData, ['category_id' => $ociCatId, 'language_id' => $ociLangId]);
                } else {
                    $this->db->insert('oci_cookie_category_translations', $transData);
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR cat_translation #{$row['id']}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  COOKIES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateCookies(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        // Global cookie database
        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_cookies_list ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                $legacyId = (int) $row['id'];

                if ($this->dryRun) {
                    $result['migrated']++;
                    continue;
                }

                try {
                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_cookies_global WHERE legacy_id = ?',
                        [$legacyId],
                    );

                    $ociCatId = $this->getOciCategoryId((int) ($row['category_id'] ?? 0));

                    $cookieData = [
                        'legacy_id' => $legacyId,
                        'platform' => $row['platform'] ?: null,
                        'category_id' => $ociCatId,
                        'cookie_name' => $row['cookie_name'] ?? 'unknown',
                        'cookie_id' => $row['cookie_id'] ?: null,
                        'domain' => $row['domain'] ?: null,
                        'description' => $row['description'] ?: null,
                        'expiry_duration' => $row['expiry_date'] ?: null,
                        'data_controller' => $row['data_controller'] ?: null,
                        'privacy_url' => $row['privacy_url'] ?: null,
                        'wildcard_match' => (int) ($row['wildcard_match'] ?? 0),
                    ];

                    if ($exists !== false) {
                        $this->db->update('oci_cookies_global', $cookieData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_cookies_global', $cookieData);
                    }

                    $result['migrated']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $this->log("  ERROR cookie #{$legacyId}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
            $this->log("  Global cookies processed: {$offset}");
        } while (\count($rows) === $this->batchSize);

        // Per-site cookies
        $this->migrateSiteCookies($result);

        return $result;
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     */
    private function migrateSiteCookies(array &$result): void
    {
        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_user_cookies ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                $legacyUserId = (int) ($row['user_id'] ?? 0);

                if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                    continue;
                }

                if ($this->dryRun) {
                    continue;
                }

                try {
                    $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                    if ($ociSiteId === null) {
                        continue;
                    }

                    $ociCatId = $this->getOciCategoryId((int) ($row['category_id'] ?? 0));

                    // Check if already exists (by site + cookie_name + domain)
                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_site_cookies WHERE site_id = ? AND cookie_name = ? AND COALESCE(cookie_domain, \'\') = ?',
                        [$ociSiteId, $row['cookie_name'], $row['cookie_domain'] ?? ''],
                    );

                    $cookieData = LegacyRowMapper::siteCookie($row, $ociSiteId, $ociCatId);

                    if ($exists !== false) {
                        $this->db->update('oci_site_cookies', $cookieData, ['id' => $exists]);
                    } else {
                        $this->db->insert('oci_site_cookies', $cookieData);
                    }
                } catch (\Throwable $e) {
                    $this->log("  ERROR site_cookie #{$row['id']}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
        } while (\count($rows) === $this->batchSize);
    }

    // ════════════════════════════════════════════════════════
    //  CONSENTS (high volume — batched carefully)
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateConsents(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_user_consents ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            $this->db->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $legacyId = (int) $row['id'];
                    $legacyUserId = (int) ($row['user_id'] ?? 0);

                    if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                        $result['skipped']++;
                        continue;
                    }

                    if ($this->dryRun) {
                        $result['migrated']++;
                        continue;
                    }

                    try {
                        $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                        if ($ociSiteId === null) {
                            $result['skipped']++;
                            continue;
                        }

                        $exists = $this->db->fetchOne(
                            'SELECT id FROM oci_consents WHERE legacy_id = ?',
                            [$legacyId],
                        );

                        $consentData = [
                            'legacy_id' => $legacyId,
                            'site_id' => $ociSiteId,
                            'consent_session' => $row['consent_session'] ?? '',
                            'consented_domain' => $row['consented_domain'] ?? '',
                            'ip_address' => $row['ip_address'] ?? '0.0.0.0',
                            'country' => $row['country'] ?: null,
                            'consent_status' => $row['consent_status'] ?? 'unknown',
                            'language' => $row['language'] ?: null,
                            'tcf_data' => $row['tcf_data'] ?: null,
                            'gacm_data' => $row['gacm_data'] ?: null,
                            'consent_date' => $row['consent_date'] ?? date('Y-m-d H:i:s'),
                            'last_renewed_at' => $row['last_renewed_date'] ?: null,
                            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                        ];

                        if ($exists !== false) {
                            $this->db->update('oci_consents', $consentData, ['legacy_id' => $legacyId]);
                        } else {
                            $this->db->insert('oci_consents', $consentData);
                        }

                        $result['migrated']++;
                    } catch (\Throwable $e) {
                        $result['errors']++;
                        $this->log("  ERROR consent #{$legacyId}: {$e->getMessage()}");
                    }
                }

                if (!$this->dryRun) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if (!$this->dryRun) {
                    $this->db->rollBack();
                }
                $result['errors']++;
                $this->log("  BATCH ERROR at offset {$offset}: {$e->getMessage()}");
            }

            $offset += $this->batchSize;
            if ($offset % 10000 === 0) {
                $this->log("  Consents processed: {$offset}");
            }
        } while (\count($rows) === $this->batchSize);

        // Consent categories
        $this->migrateConsentCategories($result);

        return $result;
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     */
    private function migrateConsentCategories(array &$result): void
    {
        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_user_consent_categories ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                if ($this->dryRun) {
                    continue;
                }

                try {
                    $ociConsentId = $this->db->fetchOne(
                        'SELECT id FROM oci_consents WHERE legacy_id = ?',
                        [(int) $row['consent_id']],
                    );

                    if ($ociConsentId === false) {
                        continue;
                    }

                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_consent_categories WHERE consent_id = ? AND category_slug = ?',
                        [$ociConsentId, (string) ($row['category_id'] ?? '')],
                    );

                    if ($exists === false) {
                        $this->db->insert('oci_consent_categories', [
                            'consent_id' => $ociConsentId,
                            'category_slug' => (string) ($row['category_id'] ?? ''),
                            'consent_status' => $row['consent_status'] ?? 'unknown',
                            'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                        ]);
                    }
                } catch (\Throwable $e) {
                    $this->log("  ERROR consent_category #{$row['id']}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
        } while (\count($rows) === $this->batchSize);
    }

    // ════════════════════════════════════════════════════════
    //  SCANS
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateScans(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_cookies_scans ORDER BY id LIMIT ? OFFSET ?',
                [$this->batchSize, $offset],
                ['integer', 'integer'],
            );

            foreach ($rows as $row) {
                $legacyId = (int) $row['id'];

                if ($this->dryRun) {
                    $result['migrated']++;
                    continue;
                }

                try {
                    $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                    if ($ociSiteId === null) {
                        $result['skipped']++;
                        continue;
                    }

                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_scans WHERE legacy_id = ?',
                        [$legacyId],
                    );

                    $scanData = [
                        'legacy_id' => $legacyId,
                        'site_id' => $ociSiteId,
                        'scan_type' => $row['scan_type'] ?: null,
                        'scan_status' => $row['scan_status'] ?? 'pending',
                        'setup_status' => $row['setup_status'] ?: null,
                        'is_scheduled' => (int) ($row['is_scheduled'] ?? 0),
                        'frequency' => $row['frequency'] ?: null,
                        'schedule_date' => $row['schedule_date'] ?: null,
                        'schedule_time' => $row['schedule_time'] ?: null,
                        'request_path' => $row['request_path'] ?: null,
                        'result_path' => $row['result_path'] ?: null,
                        'firstparty_url' => $row['firstparty_url'] ?: null,
                        'include_urls' => $row['include_urls'] ?: null,
                        'exclude_urls' => $row['exclude_urls'] ?: null,
                        'total_categories' => (int) ($row['total_categories'] ?? 0),
                        'total_cookies' => (int) ($row['total_cookies'] ?? 0),
                        'total_pages' => (int) ($row['total_pages'] ?? 0),
                        'total_scripts' => (int) ($row['total_scripts'] ?? 0),
                        'scan_location' => (int) ($row['scan_location'] ?? 1),
                        'is_first_scan' => (int) ($row['first_scan'] ?? 0),
                        'is_monthly_scan' => (int) ($row['monthly_scan'] ?? 0),
                        'report_sent' => (int) ($row['report_sent'] ?? 0),
                        'scan_attempts' => (int) ($row['scan_attempt'] ?? 0),
                        'started_at' => $row['scan_started_at'] ?: null,
                        'completed_at' => $row['scan_ended_at'] ?: null,
                        'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                    ];

                    if ($exists !== false) {
                        $this->db->update('oci_scans', $scanData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_scans', $scanData);
                    }

                    $result['migrated']++;
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $this->log("  ERROR scan #{$legacyId}: {$e->getMessage()}");
                }
            }

            $offset += $this->batchSize;
        } while (\count($rows) === $this->batchSize);

        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  POLICIES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migratePolicies(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        // Cookie policies
        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_user_cookie_policies ORDER BY id');
        foreach ($rows as $row) {
            $legacyUserId = (int) ($row['user_id'] ?? 0);

            if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                $result['skipped']++;
                continue;
            }

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                $ociLangId = $this->getOciLanguageIdByLegacyId((int) $row['lang_id']);
                if ($ociSiteId === null || $ociLangId === null) {
                    $result['skipped']++;
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_cookie_policies WHERE site_id = ? AND language_id = ?',
                    [$ociSiteId, $ociLangId],
                );

                $data = LegacyRowMapper::cookiePolicy($row, $ociSiteId, $ociLangId);

                if ($exists !== false) {
                    $this->db->update('oci_cookie_policies', $data, ['site_id' => $ociSiteId, 'language_id' => $ociLangId]);
                } else {
                    $this->db->insert('oci_cookie_policies', $data);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR cookie_policy #{$row['id']}: {$e->getMessage()}");
            }
        }

        // Privacy policies
        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_user_privacy_policies ORDER BY id');
        foreach ($rows as $row) {
            $legacyUserId = (int) ($row['user_id'] ?? 0);

            if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                $result['skipped']++;
                continue;
            }

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                $ociLangId = $this->getOciLanguageIdByLegacyId((int) $row['lang_id']);
                if ($ociSiteId === null || $ociLangId === null) {
                    $result['skipped']++;
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_privacy_policies WHERE site_id = ? AND language_id = ?',
                    [$ociSiteId, $ociLangId],
                );

                $data = LegacyRowMapper::privacyPolicy($row, $ociSiteId, $ociLangId);

                if ($exists !== false) {
                    $this->db->update('oci_privacy_policies', $data, ['site_id' => $ociSiteId, 'language_id' => $ociLangId]);
                } else {
                    $this->db->insert('oci_privacy_policies', $data);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR privacy_policy #{$row['id']}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  AGENCIES
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateAgencies(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_agencies ORDER BY id');

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];
            $legacyUserId = (int) $row['user_id'];

            if (\in_array($legacyUserId, $this->excludeUserIds, true)) {
                $result['skipped']++;
                continue;
            }

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $ociUserId = $this->getOciUserId($legacyUserId);
                if ($ociUserId === null) {
                    $result['skipped']++;
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_agencies WHERE legacy_id = ?',
                    [$legacyId],
                );

                $data = LegacyRowMapper::agency($row, $legacyId, $ociUserId);

                if ($exists !== false) {
                    $this->db->update('oci_agencies', $data, ['legacy_id' => $legacyId]);
                } else {
                    $this->db->insert('oci_agencies', $data);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR agency #{$legacyId}: {$e->getMessage()}");
            }
        }

        // Agency customers
        $customers = $this->db->fetchAllAssociative('SELECT * FROM lgc_agencies_customers ORDER BY id');
        foreach ($customers as $row) {
            if ($this->dryRun) {
                continue;
            }

            try {
                // v1 stores the agency OWNER's user id in agencies_customers.agency_id
                // (legacy/app/action.php writes $_POST['user_id'] there); one v1 class
                // joins it against agencies.id instead, so that is the fallback.
                $ociAgencyId = $this->db->fetchOne(
                    'SELECT a.id FROM oci_agencies a INNER JOIN oci_users u ON u.id = a.user_id WHERE u.legacy_id = ?',
                    [(int) $row['agency_id']],
                );
                if ($ociAgencyId === false) {
                    $ociAgencyId = $this->db->fetchOne(
                        'SELECT id FROM oci_agencies WHERE legacy_id = ?',
                        [(int) $row['agency_id']],
                    );
                }
                $ociCustomerId = $this->getOciUserId((int) $row['customer_id']);

                if ($ociAgencyId === false || $ociCustomerId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_agency_customers WHERE agency_id = ? AND customer_user_id = ?',
                    [$ociAgencyId, $ociCustomerId],
                );

                if ($exists === false) {
                    $this->db->insert('oci_agency_customers', LegacyRowMapper::agencyCustomer($row, (int) $ociAgencyId, $ociCustomerId));
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR agency_customer #{$row['id']}: {$e->getMessage()}");
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════════
    //  LOOKUP HELPERS
    // ════════════════════════════════════════════════════════

    private function getOciUserId(int $legacyUserId): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT id FROM oci_users WHERE legacy_id = ?',
            [$legacyUserId],
        );

        return $id !== false ? (int) $id : null;
    }

    private function getOciSiteId(int $legacySiteId): ?int
    {
        $id = $this->db->fetchOne(
            'SELECT id FROM oci_sites WHERE legacy_id = ?',
            [$legacySiteId],
        );

        return $id !== false ? (int) $id : null;
    }

    private function getOciCategoryId(int $legacyCategoryId): ?int
    {
        if ($legacyCategoryId === 0) {
            return null;
        }

        $id = $this->db->fetchOne(
            'SELECT id FROM oci_cookie_categories WHERE legacy_id = ?',
            [$legacyCategoryId],
        );

        return $id !== false ? (int) $id : null;
    }

    private function getOciLanguageId(string $langCode): ?int
    {
        if ($langCode === '') {
            return null;
        }

        $id = $this->db->fetchOne(
            'SELECT id FROM oci_languages WHERE lang_code = ?',
            [$langCode],
        );

        return $id !== false ? (int) $id : null;
    }

    private function getOciLanguageIdByLegacyId(int $legacyLangId): ?int
    {
        if ($legacyLangId === 0) {
            return null;
        }

        // Legacy languages table has sequential IDs, map via position
        $langCode = $this->db->fetchOne(
            'SELECT lang_code FROM lgc_languages WHERE id = ?',
            [$legacyLangId],
        );

        if ($langCode === false) {
            return null;
        }

        return $this->getOciLanguageId((string) $langCode);
    }

    // ════════════════════════════════════════════════════════
    //  UTILITY
    // ════════════════════════════════════════════════════════

    private function log(string $message): void
    {
        $this->logger->info($message);
        echo $message . "\n";
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     * @param list<string> $errors
     */
    private function logMigrationRun(string $name, array $result, array $errors, \DateTimeImmutable $startedAt): void
    {
        try {
            $nextBatch = (int) $this->db->fetchOne(
                'SELECT COALESCE(MAX(batch), 0) + 1 FROM oci_legacy_migration_log WHERE migration_name = ?',
                [$name],
            );

            $this->db->insert('oci_legacy_migration_log', [
                'migration_name' => $name,
                'batch' => $nextBatch,
                'legacy_count' => $result['migrated'] + $result['skipped'] + $result['errors'],
                'oci_count' => $result['migrated'],
                'skipped_count' => $result['skipped'],
                'error_count' => $result['errors'],
                'errors' => \count($errors) > 0 ? json_encode($errors, JSON_THROW_ON_ERROR) : null,
                'status' => $result['errors'] > 0 ? 'completed_with_errors' : 'completed',
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'completed_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->log("  WARNING: Could not log migration run: {$e->getMessage()}");
        }
    }
}
