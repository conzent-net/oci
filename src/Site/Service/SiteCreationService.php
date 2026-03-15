<?php

declare(strict_types=1);

namespace OCI\Site\Service;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Monetization\Repository\PlanRepositoryInterface;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\DTO\CreateSiteInput;
use OCI\Site\DTO\CreateSiteResult;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * Orchestrates the full site creation flow.
 *
 * Ports legacy add_site (action.php lines 37-220):
 * validate → check uniqueness → check plan limits → insert site →
 * copy language → copy categories → copy banner defaults → initiate scan.
 */
final class SiteCreationService
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    /**
     * Create a new site with all defaults initialised.
     *
     * @param array<string, mixed> $user The authenticated user (from session middleware)
     *
     * @throws \InvalidArgumentException On validation failure
     * @throws \RuntimeException On domain conflict or plan limit exceeded
     */
    public function createSite(array $user, CreateSiteInput $input): CreateSiteResult
    {
        $userId = (int) $user['id'];

        // ── Step 1: Validate domain ──────────────────────
        $domain = $this->normaliseDomain($input->domain);

        if (!$this->isValidDomain($domain)) {
            throw new \InvalidArgumentException('Invalid domain name');
        }

        // ── Step 2: Check uniqueness ─────────────────────
        if ($this->siteRepo->domainExists($domain)) {
            throw new \RuntimeException('Domain already exists in system');
        }

        // ── Step 3: Check plan limits ────────────────────
        $this->checkPlanLimits($userId);

        // ── Step 4: Resolve defaults ─────────────────────
        $defaultLang = $this->resolveDefaultLanguage();
        $languageId = (int) $defaultLang['id'];
        $websiteKey = $this->siteRepo->generateWebsiteKey();
        $siteName = $input->siteName !== '' ? $input->siteName : $domain;
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // ── Step 5: Insert site ──────────────────────────
        $siteId = $this->siteRepo->create([
            'user_id' => $userId,
            'site_name' => $siteName,
            'domain' => $domain,
            'website_key' => $websiteKey,
            'status' => 'active',
            'setup_status' => 0,
            'consent_log_enabled' => 1,
            'consent_sharing_enabled' => 1,
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 1,
            'display_banner_type' => 'gdpr',
            'banner_delay_ms' => 2000,
            'include_all_languages' => 1,
            'privacy_policy_url' => $input->privacyPolicyUrl,
            'created_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // ── Step 6: Copy default language ────────────────
        $this->languageRepo->addSiteLanguage($siteId, $languageId, true);

        // ── Step 7: Copy default cookie categories ───────
        $this->copyDefaultCategories($siteId, $languageId);

        // ── Step 8: Create default banner settings ───────
        $this->createDefaultBannerSettings($siteId, $languageId);

        // ── Step 9: Initiate first scan ──────────────────
        $this->initiateFirstScan($siteId, $domain, $userId);

        // ── Step 10: Fetch and return the created site ───
        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            throw new \RuntimeException('Sorry unable to create website');
        }

        return new CreateSiteResult(
            siteId: $siteId,
            domain: $domain,
            websiteKey: $websiteKey,
            site: $site,
        );
    }

    /**
     * Validate input before creation (returns error messages or empty array).
     *
     * @param array<string, mixed> $user
     *
     * @return array<string, string> Keyed by field name → error message
     */
    public function validateInput(array $user, CreateSiteInput $input): array
    {
        $errors = [];

        $domain = $this->normaliseDomain($input->domain);

        if ($domain === '') {
            $errors['domain'] = 'Domain is required';
        } elseif (!$this->isValidDomain($domain)) {
            $errors['domain'] = 'Invalid domain name';
        } elseif ($this->siteRepo->domainExists($domain)) {
            $errors['domain'] = 'Domain already exists in system';
        }

        $userId = (int) $user['id'];
        $limitError = $this->getPlanLimitError($userId);
        if ($limitError !== null) {
            $errors['plan'] = $limitError;
        }

        return $errors;
    }

    /**
     * Get the count of sites for a user (used to determine first-time vs returning).
     */
    public function getSiteCount(int $userId): int
    {
        return $this->siteRepo->countByUser($userId);
    }

    /**
     * Get all available languages for language selection.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAvailableLanguages(): array
    {
        return $this->languageRepo->getAllLanguages();
    }

    // ── Domain normalisation (ports legacy getDomainOnly + normalize_domain) ──

    /**
     * Normalise a domain string: strip protocol, www prefix, trailing slashes,
     * and handle punycode/IDN conversion.
     */
    public function normaliseDomain(string $domain): string
    {
        $domain = trim($domain);

        // Strip protocol first (before IDN conversion)
        $domain = (string) preg_replace('#^https?://#i', '', $domain);

        // Strip path, query, fragment — keep only the host
        $domain = explode('/', $domain)[0];
        $domain = explode('?', $domain)[0];
        $domain = explode('#', $domain)[0];

        // Strip trailing dots
        $domain = rtrim($domain, '.');

        // Handle IDN → punycode conversion (mirrors legacy normalize_domain)
        if (!$this->isPunycode($domain)) {
            $converted = idn_to_ascii($domain, \IDNA_NONTRANSITIONAL_TO_ASCII, \INTL_IDNA_VARIANT_UTS46);
            if ($converted !== false) {
                $domain = $converted;
            }
        }

        return strtolower($domain);
    }

    /**
     * Validate a domain name (ports legacy is_valid_domain).
     */
    public function isValidDomain(string $domain): bool
    {
        $domainName = $this->normaliseDomain($domain);
        $length = strlen($domainName);

        if ($length < 3 || $length > 253) {
            return false;
        }

        // Strip www. prefix for validation
        if (str_starts_with(strtolower($domainName), 'www.')) {
            $domainName = substr($domainName, 4);
        }

        // Must contain at least one dot, not at start or end
        if (!str_contains($domainName, '.') || $domainName[0] === '.' || $domainName[strlen($domainName) - 1] === '.') {
            return false;
        }

        return filter_var('http://' . $domainName, \FILTER_VALIDATE_URL) !== false;
    }

    // ── Private helpers ──────────────────────────────────

    private function isPunycode(string $domain): bool
    {
        $labels = explode('.', $domain);

        foreach ($labels as $label) {
            if (str_starts_with($label, 'xn--')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws \RuntimeException If plan limits exceeded
     */
    private function checkPlanLimits(int $userId): void
    {
        $error = $this->getPlanLimitError($userId);
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
    }

    private function getPlanLimitError(int $userId): ?string
    {
        $isEnterprise = $this->planRepo->isEnterprise($userId);

        // Enterprise accounts have unlimited domains
        if ($isEnterprise) {
            return null;
        }

        // Check active subscription via SubscriptionService (new billing system)
        if ($this->subscriptionService !== null) {
            if (!$this->subscriptionService->hasActiveAccess($userId)) {
                return 'Please subscribe to add a site';
            }

            $maxDomains = $this->subscriptionService->getAllowedDomainCount($userId);

            // max_domains = 0 means unlimited
            if ($maxDomains <= 0) {
                return null;
            }

            $currentCount = $this->siteRepo->countByUser($userId);

            if ($currentCount >= $maxDomains) {
                return 'You have reached the maximum number of sites for your plan. Please upgrade your subscription.';
            }

            return null;
        }

        // Fallback: legacy plan check
        $userPlan = $this->planRepo->getUserPlan($userId);

        if ($userPlan === null) {
            return 'Please upgrade your plan to add a site';
        }

        return null;
    }

    /**
     * Resolve the default language, falling back to English if none is set.
     *
     * @return array<string, mixed>
     */
    private function resolveDefaultLanguage(): array
    {
        $defaultLang = $this->languageRepo->getSystemDefaultLanguage();

        if ($defaultLang !== null) {
            return $defaultLang;
        }

        // Fallback: English defaults (mirrors legacy)
        return [
            'id' => 1,
            'lang_code' => 'en',
            'lang_name' => 'English',
            'is_default' => 1,
        ];
    }

    private function copyDefaultCategories(int $siteId, int $languageId): void
    {
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
    }

    private function createDefaultBannerSettings(int $siteId, int $languageId): void
    {
        $template = $this->bannerRepo->getDefaultBannerTemplate();

        if ($template === null) {
            // No templates available — skip banner setup
            return;
        }

        $templateId = (int) $template['id'];

        // Create default general settings matching legacy defaults
        $generalSetting = [
            'geo_target' => 'all',
            'google_additional_consent' => 1,
        ];

        $contentSetting = [
            'cookie_notice' => [
                'accept_all_button' => 1,
            ],
            'revisit_consent_button' => [
                'floating_button' => 1,
            ],
        ];

        $colorSetting = [
            'light' => [],
        ];

        $siteBannerId = $this->bannerRepo->createSiteBanner([
            'site_id' => $siteId,
            'banner_template_id' => $templateId,
            'general_setting' => json_encode($generalSetting, \JSON_THROW_ON_ERROR),
            'layout_setting' => null,
            'content_setting' => json_encode($contentSetting, \JSON_THROW_ON_ERROR),
            'color_setting' => json_encode($colorSetting, \JSON_THROW_ON_ERROR),
        ]);

        // Copy default banner field translations
        $this->bannerRepo->copyDefaultBannerTranslations($siteBannerId, $templateId, $languageId);
    }

    private function initiateFirstScan(int $siteId, string $domain, int $userId): void
    {
        $scanServer = $this->scanRepo->getActiveScanServer();
        $scanLocation = $scanServer !== null ? (int) $scanServer['id'] : 1;

        // Get scan page limit from user plan
        $scanLimit = $this->resolveScanLimit($userId);

        // Create the initial "first scan" record
        $this->scanRepo->createScan([
            'site_id' => $siteId,
            'scan_type' => 'full',
            'scan_status' => 'initiated',
            'is_first_scan' => 1,
            'is_scheduled' => 0,
            'scan_location' => $scanLocation,
            'total_pages' => $scanLimit,
        ]);

        // Check if monthly scheduled scan feature is available
        $planFeatures = $this->resolvePlanFeatures($userId);
        if ($this->hasFeature('monthly_scheduled_scan', $planFeatures)) {
            $nextMonth = (new \DateTimeImmutable())->modify('+1 month');

            $this->scanRepo->createScan([
                'site_id' => $siteId,
                'scan_type' => 'full',
                'scan_status' => 'scheduled',
                'is_first_scan' => 0,
                'is_monthly_scan' => 1,
                'is_scheduled' => 1,
                'frequency' => 'monthly',
                'schedule_date' => $nextMonth->format('Y-m-d'),
                'schedule_time' => $nextMonth->format('H:i:s'),
                'scan_location' => $scanLocation,
                'total_pages' => $scanLimit,
            ]);
        }
    }

    private function resolveScanLimit(int $userId): int
    {
        $isEnterprise = $this->planRepo->isEnterprise($userId);
        if ($isEnterprise) {
            // Enterprise = unlimited (0 means no limit)
            return 0;
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        if ($userPlan === null) {
            return 100; // Default scan limit
        }

        $plan = $this->planRepo->findPlanById((int) $userPlan['plan_id']);
        if ($plan === null) {
            return 100;
        }

        return (int) ($plan['pages_per_scan'] ?? 100);
    }

    /**
     * @return array<string, int|string>
     */
    private function resolvePlanFeatures(int $userId): array
    {
        $isEnterprise = $this->planRepo->isEnterprise($userId);
        if ($isEnterprise) {
            return $this->planRepo->getAllFeatures();
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        if ($userPlan === null) {
            return [];
        }

        return $this->planRepo->getPlanFeatures((int) $userPlan['plan_id']);
    }

    /**
     * @param array<string, int|string> $features
     */
    private function hasFeature(string $featureKey, array $features): bool
    {
        if (!isset($features[$featureKey])) {
            return false;
        }

        return (int) $features[$featureKey] === 1 || $features[$featureKey] === '1';
    }
}
