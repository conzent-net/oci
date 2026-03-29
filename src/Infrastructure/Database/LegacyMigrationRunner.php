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
 * Domains: users, sites, languages, categories, cookies, banners, consents, plans, scans, policies, agencies
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
            'banners' => $this->migrateBanners(...),
            'consents' => $this->migrateConsents(...),
            'plans' => $this->migratePlans(...),
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

                if (\in_array($legacyId, $this->excludeUserIds, true)) {
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

                    $role = match ((int) ($row['userlevel'] ?? 1)) {
                        9 => 'admin',
                        5 => 'agency',
                        default => 'customer',
                    };

                    $createdAt = $this->unixToDatetime((int) ($row['regdate'] ?? 0));

                    $userData = [
                        'legacy_id' => $legacyId,
                        'username' => $row['username'] ?? "user_{$legacyId}",
                        'email' => $row['email'] ?? "unknown_{$legacyId}@legacy.local",
                        'first_name' => $row['firstname'] ?? '',
                        'last_name' => $row['lastname'] ?? '',
                        'password' => $row['password'] ?? '',
                        'role' => $role,
                        'is_active' => true,
                        'is_enterprise' => (int) ($row['enterprise_account'] ?? 0),
                        'account_id' => $row['account_id'] ?: null,
                        'price_model' => $row['price_model'] ?: null,
                        'last_login_ip' => $row['lastip'] ?: null,
                        'login_attempts' => (int) ($row['user_login_attempts'] ?? 0),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ];

                    if ($exists !== false) {
                        $this->db->update('oci_users', $userData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_users', $userData);
                    }

                    // Company data
                    if (!empty($row['company']) || !empty($row['vat'])) {
                        $ociUserId = (int) $this->db->fetchOne(
                            'SELECT id FROM oci_users WHERE legacy_id = ?',
                            [$legacyId],
                        );

                        $companyExists = $this->db->fetchOne(
                            'SELECT id FROM oci_user_companies WHERE user_id = ?',
                            [$ociUserId],
                        );

                        $companyData = [
                            'user_id' => $ociUserId,
                            'company_name' => $row['company'] ?: null,
                            'vat_number' => $row['vat'] ?: null,
                            'address' => $row['address'] ?: null,
                            'zip' => $row['zip'] ?: null,
                            'city' => $row['city'] ?: null,
                            'state' => $row['state'] ?: null,
                            'country_code' => $row['country'] ?: null,
                            'phone' => $row['phone'] ?: null,
                        ];

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
                    $this->db->insert('oci_api_keys', [
                        'user_id' => $ociUserId,
                        'api_key' => $row['apikey'],
                        'is_active' => (int) ($row['active'] ?? 1),
                        'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                    ]);
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

                    $siteData = [
                        'legacy_id' => $legacyId,
                        'user_id' => $ociUserId,
                        'site_name' => $row['site_name'] ?: null,
                        'domain' => $row['domain'],
                        'website_key' => $row['website_key'],
                        'status' => ((int) ($row['status'] ?? 1)) === 1 ? 'active' : 'inactive',
                        'setup_status' => (int) ($row['setup_status'] ?? 0),
                        'consent_log_enabled' => (int) ($row['consent_log'] ?? 1),
                        'consent_sharing_enabled' => (int) ($row['consent_sharing'] ?? 1),
                        'gcm_enabled' => (int) ($row['support_gcm'] ?? 1),
                        'tag_fire_enabled' => (int) ($row['allow_tag_fire'] ?? 1),
                        'cross_domain_enabled' => (int) ($row['allow_cross_domain'] ?? 0),
                        'block_iframe' => (int) ($row['block_iframe'] ?? 0),
                        'debug_mode' => (int) ($row['debug_mode'] ?? 0),
                        'display_banner_type' => $row['display_banner'] ?? 'gdpr',
                        'banner_delay_ms' => (int) ($row['banner_delay'] ?? 2000),
                        'include_all_languages' => (int) ($row['include_all_lang'] ?? 1),
                        'privacy_policy_url' => $row['privacy_policy'] ?: null,
                        'other_domains' => $row['other_domains'] ?: null,
                        'disable_on_pages' => $row['disable_on_pages'] ?: null,
                        'compliant_status' => $row['compliant_status'] ?: null,
                        'gcm_config_status' => $row['gcm_config_status'] ?: null,
                        'template_applied' => $row['template_applied'] ?: null,
                        'site_logo' => $row['site_logo'] ?: null,
                        'icon_logo' => $row['icon_logo'] ?: null,
                        'renew_user_consent_at' => $row['renew_user_consent'] ?: null,
                        'last_banner_load_at' => $row['last_banner_load_on'] ?: null,
                        'site_updated' => (int) ($row['site_updated'] ?? 0),
                        'created_by' => $ociUserId,
                        'created_at' => $row['created_date'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $row['modified_date'] ?? $row['created_date'] ?? date('Y-m-d H:i:s'),
                    ];

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
                    $this->db->insert('oci_associated_sites', [
                        'site_id' => $ociSiteId,
                        'domain' => $row['domain'],
                        'privacy_policy_url' => $row['privacy_policy'] ?: null,
                        'created_at' => $row['created_date'] ?? date('Y-m-d H:i:s'),
                    ]);
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
                    $this->db->insert('oci_site_languages', [
                        'site_id' => $ociSiteId,
                        'language_id' => $ociLangId,
                        'is_default' => (int) ($row['is_default'] ?? 0),
                    ]);
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

                    $cookieData = [
                        'site_id' => $ociSiteId,
                        'category_id' => $ociCatId,
                        'cookie_name' => $row['cookie_name'] ?? 'unknown',
                        'cookie_domain' => $row['cookie_domain'] ?: null,
                        'default_duration' => $row['default_duration'] ?: null,
                        'script_url_pattern' => $row['script_url_pattern'] ?: null,
                        'from_scan' => (int) ($row['created_from_scan'] ?? 1),
                        'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                    ];

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
    //  BANNERS
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migrateBanners(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        // Banner templates
        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_cookie_consent_banners ORDER BY id');
        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_banner_templates WHERE legacy_id = ?',
                    [$legacyId],
                );

                $data = [
                    'legacy_id' => $legacyId,
                    'banner_name' => $row['banner_name'] ?? "Banner {$legacyId}",
                    'banner_slug' => $row['banner_slug'] ?? "banner-{$legacyId}",
                    'custom_css' => $row['custom_css'] ?: null,
                    'is_default' => (int) ($row['is_default'] ?? 0),
                    'is_active' => (int) ($row['status'] ?? 1),
                    'cookie_laws' => $row['cookie_laws'] ?: null,
                    'created_at' => $row['created_date'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['modified_date'] ?? $row['created_date'] ?? date('Y-m-d H:i:s'),
                ];

                if ($exists !== false) {
                    $this->db->update('oci_banner_templates', $data, ['legacy_id' => $legacyId]);
                } else {
                    $this->db->insert('oci_banner_templates', $data);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR banner_template #{$legacyId}: {$e->getMessage()}");
            }
        }

        // Site banners
        $siteBanners = $this->db->fetchAllAssociative('SELECT * FROM lgc_site_cookie_banners ORDER BY id');
        foreach ($siteBanners as $row) {
            if ($this->dryRun) {
                continue;
            }

            try {
                $ociSiteId = $this->getOciSiteId((int) ($row['site_id'] ?? 0));
                if ($ociSiteId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_site_banners WHERE site_id = ?',
                    [$ociSiteId],
                );

                $data = [
                    'site_id' => $ociSiteId,
                    'banner_template_id' => $this->getOciBannerTemplateId((int) ($row['template_id'] ?? 0)),
                    'custom_css' => $row['custom_css'] ?: null,
                    'consent_template' => $row['consent_template'] ?: null,
                    'general_setting' => $row['general_setting'] ?: null,
                    'layout_setting' => $row['layout_setting'] ?: null,
                    'content_setting' => $row['content_setting'] ?: null,
                    'color_setting' => $row['color_setting'] ?: null,
                    'created_at' => $row['created_date'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['modified_date'] ?? date('Y-m-d H:i:s'),
                ];

                if ($exists !== false) {
                    $this->db->update('oci_site_banners', $data, ['site_id' => $ociSiteId]);
                } else {
                    $this->db->insert('oci_site_banners', $data);
                }
            } catch (\Throwable $e) {
                $this->log("  ERROR site_banner #{$row['id']}: {$e->getMessage()}");
            }
        }

        return $result;
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
    //  PLANS
    // ════════════════════════════════════════════════════════

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    private function migratePlans(): array
    {
        $result = ['migrated' => 0, 'skipped' => 0, 'errors' => 0];

        $rows = $this->db->fetchAllAssociative('SELECT * FROM lgc_site_plans ORDER BY id');

        foreach ($rows as $row) {
            $legacyId = (int) $row['id'];

            if ($this->dryRun) {
                $result['migrated']++;
                continue;
            }

            try {
                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_plans WHERE legacy_id = ?',
                    [$legacyId],
                );

                $planData = [
                    'legacy_id' => $legacyId,
                    'plan_name' => $row['plan_name'] ?? "Plan {$legacyId}",
                    'plan_slug' => $row['plan_slug'] ?? "plan-{$legacyId}",
                    'plan_description' => $row['plan_description'] ?: null,
                    'duration_months' => (int) ($row['plan_duration'] ?? 1),
                    'duration_type' => $row['duration_type'] ?? 'monthly',
                    'monthly_price' => (string) ($row['price'] ?? '0.00'),
                    'yearly_price' => (string) ($row['yearly_price'] ?? '0.00'),
                    'stripe_monthly_price_id' => $row['monthly_price_id'] ?: null,
                    'stripe_yearly_price_id' => $row['yearly_price_id'] ?: null,
                    'is_trial' => (int) ($row['is_trial'] ?? 0),
                    'trial_period_days' => (int) ($row['trial_period'] ?? 0),
                    'is_default' => (int) ($row['is_default'] ?? 0),
                    'is_recurring' => (int) ($row['recurring'] ?? 1),
                    'is_custom' => (int) ($row['custom'] ?? 0),
                    'is_lifetime' => (int) ($row['lifetime_plan'] ?? 0),
                    'is_active' => (int) ($row['plan_status'] ?? 1),
                    'pageview_limit' => (int) ($row['pageview'] ?? 0),
                    'pages_per_scan' => (int) ($row['pages_per_scan'] ?? 0),
                    'max_languages' => (int) ($row['max_lang'] ?? 1),
                    'max_domains' => (int) ($row['total_domains'] ?? 1),
                    'max_users' => (int) ($row['total_users'] ?? 0),
                    'max_layouts' => (int) ($row['total_layout'] ?? 1),
                    'free_months' => (int) ($row['free_months'] ?? 0),
                    'sort_order' => (int) ($row['display_order'] ?? 0),
                    'created_by' => null,
                    'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['modified_at'] ?? date('Y-m-d H:i:s'),
                ];

                if ($exists !== false) {
                    $this->db->update('oci_plans', $planData, ['legacy_id' => $legacyId]);
                } else {
                    $this->db->insert('oci_plans', $planData);
                }

                $result['migrated']++;
            } catch (\Throwable $e) {
                $result['errors']++;
                $this->log("  ERROR plan #{$legacyId}: {$e->getMessage()}");
            }
        }

        // Subscriptions
        $this->migrateSubscriptions($result);

        return $result;
    }

    /**
     * @param array{migrated: int, skipped: int, errors: int} $result
     */
    private function migrateSubscriptions(array &$result): void
    {
        $offset = 0;
        do {
            $rows = $this->db->fetchAllAssociative(
                'SELECT * FROM lgc_site_subscriptions ORDER BY id LIMIT ? OFFSET ?',
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
                    continue;
                }

                try {
                    $ociUserId = $this->getOciUserId($legacyUserId);
                    $ociSiteId = $this->getOciSiteId((int) $row['site_id']);
                    $ociPlanId = $this->getOciPlanId((int) $row['plan_id']);

                    if ($ociUserId === null || $ociSiteId === null || $ociPlanId === null) {
                        $result['skipped']++;
                        continue;
                    }

                    $exists = $this->db->fetchOne(
                        'SELECT id FROM oci_subscriptions WHERE legacy_id = ?',
                        [$legacyId],
                    );

                    $subData = [
                        'legacy_id' => $legacyId,
                        'user_id' => $ociUserId,
                        'site_id' => $ociSiteId,
                        'plan_id' => $ociPlanId,
                        'item_name' => $row['item_name'] ?: null,
                        'order_id' => $row['order_id'] ?? "legacy-{$legacyId}",
                        'external_subscription_id' => $row['subscription_id'] ?: null,
                        'payment_method' => $row['payment_method'] ?? 'unknown',
                        'currency' => $row['currency'] ?? 'EUR',
                        'amount' => (string) ($row['amount'] ?? '0.00'),
                        'vat_amount' => (string) ($row['vat_amount'] ?? '0.00'),
                        'subtotal' => (string) ($row['subtotal'] ?? '0.00'),
                        'total' => (string) ($row['total'] ?? '0.00'),
                        'billing_cycle' => $row['billing_cycle'] ?? 'monthly',
                        'status' => $row['status'] ?? 'pending',
                        'plan_status' => $row['plan_status'] ?? 'new',
                        'invoice_id' => $row['invoice_id'] ?? null,
                        'customer_email' => $row['customer_email'] ?? null,
                        'expires_at' => $row['expiry_date'] ?? date('Y-m-d H:i:s'),
                        'cancelled_at' => $row['canceled_at'] ?: null,
                        'cancel_requested_at' => $row['cancel_at'] ?: null,
                        'created_at' => $row['date_created'] ?? date('Y-m-d H:i:s'),
                        'updated_at' => $row['date_modified'] ?? date('Y-m-d H:i:s'),
                    ];

                    if ($exists !== false) {
                        $this->db->update('oci_subscriptions', $subData, ['legacy_id' => $legacyId]);
                    } else {
                        $this->db->insert('oci_subscriptions', $subData);
                    }
                } catch (\Throwable $e) {
                    $result['errors']++;
                    $this->log("  ERROR subscription #{$legacyId}: {$e->getMessage()}");
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

                $data = [
                    'site_id' => $ociSiteId,
                    'language_id' => $ociLangId,
                    'heading' => $row['heading'] ?: null,
                    'type_heading' => $row['type_heading'] ?: null,
                    'url_key' => $row['url_key'] ?: null,
                    'preference_heading' => $row['preference_heading'] ?: null,
                    'preference_description' => $row['preference_description'] ?: null,
                    'revisit_consent_widget' => $row['revisit_consent_widget'] ?: null,
                    'policy_content' => $row['policy_content'] ?: null,
                    'show_audit_table' => (int) ($row['show_audit_table'] ?? 0),
                    'effective_date' => $row['effective_date'] ?: null,
                    'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                ];

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

                $data = [
                    'site_id' => $ociSiteId,
                    'language_id' => $ociLangId,
                    'heading' => $row['heading'] ?: null,
                    'url_key' => $row['url_key'] ?: null,
                    'step_data' => $row['step_data'] ?: null,
                    'policy_content' => $row['policy_content'] ?: null,
                    'effective_date' => $row['effective_date'] ?: null,
                    'created_at' => $row['created_at'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['updated_at'] ?? date('Y-m-d H:i:s'),
                ];

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

                $data = [
                    'legacy_id' => $legacyId,
                    'user_id' => $ociUserId,
                    'name' => $row['name'] ?? "Agency {$legacyId}",
                    'address' => $row['address'] ?: null,
                    'zip' => $row['zip'] ?: null,
                    'city' => $row['city'] ?: null,
                    'state' => $row['state'] ?: null,
                    'country_code' => $row['country_code'] ?: null,
                    'vat_number' => $row['vat_number'] ?: null,
                    'contact_name' => $row['contact'] ?: null,
                    'contact_email' => $row['contact_email'] ?: null,
                    'invoice_email' => $row['invoice_email'] ?: null,
                    'iban' => $row['iban'] ?: null,
                    'swift' => $row['swift'] ?: null,
                    'account_reg' => $row['account_reg'] ?: null,
                    'account_number' => $row['account_number'] ?: null,
                    'commission_pct' => (int) ($row['commission'] ?? 0),
                    'agency_type' => ((int) ($row['agency_type'] ?? 0)) === 1 ? 'reseller' : 'agency',
                    'is_active' => (int) ($row['enabled'] ?? 1),
                    'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['updated'] ?? date('Y-m-d H:i:s'),
                ];

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
                $ociAgencyId = $this->db->fetchOne(
                    'SELECT id FROM oci_agencies WHERE legacy_id = ?',
                    [(int) $row['agency_id']],
                );
                $ociCustomerId = $this->getOciUserId((int) $row['customer_id']);

                if ($ociAgencyId === false || $ociCustomerId === null) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    'SELECT id FROM oci_agency_customers WHERE agency_id = ? AND customer_user_id = ?',
                    [$ociAgencyId, $ociCustomerId],
                );

                if ($exists === false) {
                    $this->db->insert('oci_agency_customers', [
                        'agency_id' => $ociAgencyId,
                        'customer_user_id' => $ociCustomerId,
                        'date_from' => $row['date_from'] ?: null,
                        'date_to' => $row['date_to'] ?: null,
                    ]);
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

    private function getOciPlanId(int $legacyPlanId): ?int
    {
        if ($legacyPlanId === 0) {
            return null;
        }

        $id = $this->db->fetchOne(
            'SELECT id FROM oci_plans WHERE legacy_id = ?',
            [$legacyPlanId],
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

    private function getOciBannerTemplateId(int $legacyBannerId): ?int
    {
        if ($legacyBannerId === 0) {
            return null;
        }

        $id = $this->db->fetchOne(
            'SELECT id FROM oci_banner_templates WHERE legacy_id = ?',
            [$legacyBannerId],
        );

        return $id !== false ? (int) $id : null;
    }

    // ════════════════════════════════════════════════════════
    //  UTILITY
    // ════════════════════════════════════════════════════════

    private function unixToDatetime(int $timestamp): string
    {
        if ($timestamp === 0) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

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
