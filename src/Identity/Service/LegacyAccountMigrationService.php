<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Doctrine\DBAL\Connection;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Self-service migration of a single user's data from the legacy Conzent database.
 *
 * Migrates: company info, sites (domain + name only).
 * Each migrated site gets fresh default banner settings, language, and cookie categories
 * — no site-specific settings are copied from the legacy system.
 * Sites are created as suspended until the user subscribes.
 *
 * Legacy tables used:
 *   users         — user accounts (email lookup)
 *   users_company — company info (vat, company, address, etc.)
 *   user_sites    — websites (domain, name)
 */
final class LegacyAccountMigrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?Connection $legacyDb,
        private readonly UserRepositoryInterface $userRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly ?ScriptGenerationService $scriptService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Whether legacy migration is available (legacy DB configured).
     */
    public function isAvailable(): bool
    {
        return $this->legacyDb !== null;
    }

    /**
     * Look up a legacy account by email.
     *
     * @return array{user: array, company: array}|null
     */
    public function findLegacyAccount(string $email): ?array
    {
        if ($this->legacyDb === null) {
            return null;
        }

        $user = $this->legacyDb->fetchAssociative(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [$email],
        );

        if ($user === false) {
            return null;
        }

        $company = $this->legacyDb->fetchAssociative(
            'SELECT * FROM users_company WHERE user_id = ? LIMIT 1',
            [$user['id']],
        );

        return [
            'user' => $user,
            'company' => $company ?: [],
        ];
    }

    /**
     * Preview what would be migrated for this email.
     *
     * @return array{legacy_user: array, sites: list<array>, total_sites: int, migratable_sites: int, conflicting_sites: int}|null
     */
    public function previewMigration(string $email): ?array
    {
        $legacy = $this->findLegacyAccount($email);
        if ($legacy === null) {
            return null;
        }

        $legacyUserId = (int) $legacy['user']['id'];

        $legacySites = $this->legacyDb->fetchAllAssociative(
            'SELECT id, domain, site_name, status FROM user_sites WHERE user_id = ? AND status != 0',
            [$legacyUserId],
        );

        $sites = [];
        $migratable = 0;
        $conflicting = 0;

        foreach ($legacySites as $ls) {
            $domain = trim((string) ($ls['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $domain = $this->normaliseDomain($domain);
            $conflict = $this->siteRepo->domainExists($domain);

            $sites[] = [
                'domain' => $domain,
                'site_name' => (string) ($ls['site_name'] ?? $domain),
                'status' => $conflict ? 'conflict' : 'will_migrate',
            ];

            if ($conflict) {
                $conflicting++;
            } else {
                $migratable++;
            }
        }

        return [
            'legacy_user' => [
                'email' => $legacy['user']['email'] ?? $email,
                'name' => trim(($legacy['user']['firstname'] ?? '') . ' ' . ($legacy['user']['lastname'] ?? '')),
                'company' => $legacy['company']['company'] ?? '',
            ],
            'sites' => $sites,
            'total_sites' => \count($sites),
            'migratable_sites' => $migratable,
            'conflicting_sites' => $conflicting,
        ];
    }

    /**
     * Perform the migration for a single user.
     *
     * @return array{migrated_sites: int, skipped_sites: int, errors: list<string>}
     */
    public function migrateAccount(int $ociUserId, string $email): array
    {
        if ($this->legacyDb === null) {
            throw new \RuntimeException('Legacy database connection is not configured.');
        }

        $legacy = $this->findLegacyAccount($email);
        if ($legacy === null) {
            throw new \RuntimeException('No legacy account found for this email.');
        }

        $legacyUserId = (int) $legacy['user']['id'];
        $migratedSites = 0;
        $skippedSites = 0;
        $errors = [];

        // ── Step 1: Migrate company info ──────────────────
        $this->migrateCompany($ociUserId, $legacy['company']);

        // ── Step 2: Migrate sites with default settings ───
        $legacySites = $this->legacyDb->fetchAllAssociative(
            'SELECT * FROM user_sites WHERE user_id = ? AND status != 0',
            [$legacyUserId],
        );

        foreach ($legacySites as $ls) {
            $domain = trim((string) ($ls['domain'] ?? ''));
            if ($domain === '') {
                continue;
            }

            $domain = $this->normaliseDomain($domain);

            // Skip if domain already exists
            if ($this->siteRepo->domainExists($domain)) {
                $skippedSites++;
                $this->logger->info('Legacy migration: skipped site (domain conflict)', ['domain' => $domain]);
                continue;
            }

            try {
                $this->db->beginTransaction();

                $siteId = $this->createSiteRecord($ociUserId, $ls, $domain);
                $this->setupSiteDefaults($siteId);

                $this->db->commit();

                $migratedSites++;
                $this->logger->info('Legacy migration: site migrated', [
                    'domain' => $domain,
                    'site_id' => $siteId,
                ]);
            } catch (\Throwable $e) {
                if ($this->db->isTransactionActive()) {
                    $this->db->rollBack();
                }
                $errors[] = "Failed to migrate {$domain}: {$e->getMessage()}";
                $this->logger->error('Legacy migration: site failed', [
                    'domain' => $domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Legacy migration completed', [
            'user_id' => $ociUserId,
            'email' => $email,
            'migrated' => $migratedSites,
            'skipped' => $skippedSites,
            'errors' => \count($errors),
        ]);

        return [
            'migrated_sites' => $migratedSites,
            'skipped_sites' => $skippedSites,
            'errors' => $errors,
        ];
    }

    /**
     * Migrate company info from legacy users_company to oci_user_companies.
     *
     * @param array<string, mixed> $legacyCompany
     */
    private function migrateCompany(int $ociUserId, array $legacyCompany): void
    {
        if (empty($legacyCompany)) {
            return;
        }

        $this->userRepo->upsertUserCompany($ociUserId, [
            'company_name' => (string) ($legacyCompany['company'] ?? ''),
            'vat_number' => (string) ($legacyCompany['vat'] ?? ''),
            'address' => (string) ($legacyCompany['address'] ?? ''),
            'city' => (string) ($legacyCompany['city'] ?? ''),
            'zip' => (string) ($legacyCompany['zip'] ?? ''),
            'state' => (string) ($legacyCompany['state'] ?? ''),
            'country_code' => (string) ($legacyCompany['country'] ?? ''),
            'phone' => (string) ($legacyCompany['phone'] ?? ''),
        ]);
    }

    /**
     * Create an OCI site record from a legacy user_sites row.
     *
     * @param array<string, mixed> $legacySite
     *
     * @return int The new OCI site ID
     */
    private function createSiteRecord(int $ociUserId, array $legacySite, string $domain): int
    {
        $legacySiteId = (int) $legacySite['id'];
        $websiteKey = $this->siteRepo->generateWebsiteKey();

        return $this->siteRepo->create([
            'user_id' => $ociUserId,
            'legacy_id' => $legacySiteId,
            'domain' => $domain,
            'site_name' => (string) ($legacySite['site_name'] ?? $domain),
            'website_key' => $websiteKey,
            'status' => 'suspended',
            'suspended_reason' => 'no_subscription',
            'display_banner_type' => (string) ($legacySite['display_banner'] ?? 'gdpr'),
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 1,
            'cross_domain_enabled' => 0,
            'block_iframe' => 0,
            'debug_mode' => 0,
            'consent_log_enabled' => 1,
            'consent_sharing_enabled' => 1,
            'banner_delay_ms' => 100,
            'include_all_languages' => 1,
            'privacy_policy_url' => (string) ($legacySite['privacy_policy'] ?? ''),
            'created_by' => $ociUserId,
            'created_at' => $this->legacyTimestamp($legacySite['created_date'] ?? null),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Set up default language, cookie categories, and banner settings for a migrated site.
     * Mirrors SiteCreationService steps 6-8.
     */
    private function setupSiteDefaults(int $siteId): void
    {
        // Resolve default language
        $defaultLang = $this->languageRepo->getSystemDefaultLanguage();
        $languageId = $defaultLang !== null ? (int) $defaultLang['id'] : 1;

        // Add default language to site
        $this->languageRepo->addSiteLanguage($siteId, $languageId, true);

        // Copy default cookie categories
        $categories = $this->categoryRepo->getDefaultCategories($languageId);
        foreach ($categories as $category) {
            $this->categoryRepo->copyCategoryToSite(
                siteId: $siteId,
                categoryId: (int) $category['id'],
                languageId: $languageId,
                name: (string) ($category['name'] ?? ''),
                description: (string) ($category['description'] ?? ''),
            );
        }

        // Create default banner settings
        $template = $this->bannerRepo->getDefaultBannerTemplate();
        if ($template === null) {
            return;
        }

        $templateId = (int) $template['id'];

        $siteBannerId = $this->bannerRepo->createSiteBanner([
            'site_id' => $siteId,
            'banner_template_id' => $templateId,
            'general_setting' => json_encode([
                'geo_target' => 'all',
                'google_additional_consent' => 1,
            ], \JSON_THROW_ON_ERROR),
            'layout_setting' => null,
            'content_setting' => json_encode([
                'cookie_notice' => ['accept_all_button' => 1],
                'revisit_consent_button' => ['floating_button' => 1],
            ], \JSON_THROW_ON_ERROR),
            'color_setting' => json_encode(['light' => []], \JSON_THROW_ON_ERROR),
        ]);

        // Copy default banner field translations
        $this->bannerRepo->copyDefaultBannerTranslations($siteBannerId, $templateId, $languageId);
    }

    /**
     * Convert a legacy timestamp (unix or datetime string) to MySQL datetime.
     */
    private function legacyTimestamp(mixed $value): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return date('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return (string) $value;
    }

    /**
     * Strip protocol and trailing slash from a domain.
     */
    private function normaliseDomain(string $domain): string
    {
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        $domain = strtolower($domain);

        return $domain;
    }
}
