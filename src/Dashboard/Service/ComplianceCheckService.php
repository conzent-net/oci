<?php

declare(strict_types=1);

namespace OCI\Dashboard\Service;

use OCI\Agency\Repository\AgencyRepositoryInterface;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * Compliance check service — ports legacy UserCookie::checkCompliantStatus().
 *
 * This performs 8 compliance checks on a site's banner settings and auto-fixes
 * certain settings to ensure compliance. The status determination mirrors the
 * legacy logic exactly, including the lenient "partial → full" when a template
 * is explicitly set.
 */
final class ComplianceCheckService
{
    private const GOOGLE_PRIVACY_URL = 'https://business.safety.google/privacy';

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly AgencyRepositoryInterface $agencyRepo,
        private readonly PrivacyFrameworkService $frameworkService,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    /**
     * Run the full compliance check on a site.
     *
     * @param int    $siteId          The site to check
     * @param string $templateName    Template to apply (empty = use current)
     * @param bool   $isTemplateUpdate Whether this is an explicit template change
     * @param bool   $verifyOnly      If true, don't persist any changes (read-only check)
     * @return array{status: string, total_passed: int, total_failed: int, total_checked: int, errors: list<string>}
     */
    public function check(int $siteId, string $templateName = '', bool $isTemplateUpdate = false, bool $verifyOnly = false): array
    {
        $siteData = $this->siteRepo->findById($siteId);
        if ($siteData === null) {
            return [
                'status' => 'failed',
                'total_passed' => 0,
                'total_failed' => 0,
                'total_checked' => 0,
                'errors' => ['Site not found'],
            ];
        }

        $siteUserId = (int) $siteData['user_id'];
        $effectiveUserId = $siteUserId;
        $defaultPrivacyUrl = (string) ($siteData['privacy_policy_url'] ?? '');

        // Resolve effective user (agency plan model)
        $agencyInfo = $this->agencyRepo->findAgencyForCustomer($siteUserId);
        if ($agencyInfo !== null) {
            $priceModel = $this->planRepo->getPriceModel((int) $agencyInfo['id']);
            if ($priceModel === 'plan') {
                $effectiveUserId = (int) $agencyInfo['id'];
            }
        }

        // Load plan features
        $planFeatures = $this->loadPlanFeatures($effectiveUserId);

        // Determine template to use
        if (!$isTemplateUpdate) {
            $templateName = (string) ($siteData['template_applied'] ?? '');
        }

        // Map template → iab_status and fire_before_tag
        [$iabStatus, $fireBeforeTag] = $this->mapTemplate($templateName);

        // AUTO-FIX: Force GCM support
        if (!$verifyOnly && (int) ($siteData['gcm_enabled'] ?? 0) !== 1) {
            $this->siteRepo->updateSiteSettings($siteId, ['gcm_enabled' => 1]);
        }

        // AUTO-FIX: Update tag_fire_enabled when template is explicitly passed
        if (!$verifyOnly && $isTemplateUpdate) {
            $this->siteRepo->updateSiteSettings($siteId, ['tag_fire_enabled' => $fireBeforeTag]);
        }

        // Run the 8 compliance checks
        $totalChecked = 0;
        $totalPassed = 0;
        $totalFailed = 0;
        $errors = [];

        // Load banner settings
        $banners = $this->bannerRepo->getSiteBannerSettings($siteId, 'gdpr');
        if (!empty($banners)) {
            $banner = reset($banners);
            $bannerId = (int) $banner['id'];
            $generalSettings = $this->decodeJson((string) ($banner['general_setting'] ?? '{}'));
            $contentSettings = $this->decodeJson((string) ($banner['content_setting'] ?? '{}'));

            // Capture actual IAB state before compliance check may modify it
            $actualIabEnabled = (int) ($generalSettings['iab_support'] ?? 0);

            // Ensure nested paths exist
            $contentSettings = $this->ensurePath($contentSettings, ['gdpr', 'cookie_notice']);
            $contentSettings = $this->ensurePath($contentSettings, ['gdpr', 'revisit_consent_button']);
            $contentSettings = $this->ensurePath($contentSettings, ['gdpr', 'preference_center']);

            // CHECK 1: IAB TCF support
            $totalChecked++;
            if ($this->checkFeature('iab_tcf_banner', $planFeatures)) {
                // Use the effective IAB state: template setting if explicitly updating, else current
                $effectiveIab = $isTemplateUpdate ? $iabStatus : $actualIabEnabled;
                if ($effectiveIab === 1) {
                    $totalPassed++;
                } else {
                    $totalFailed++;
                }
                // Only change IAB when explicitly applying a template
                if ($isTemplateUpdate) {
                    $generalSettings['iab_support'] = $iabStatus;
                }
            } else {
                $totalFailed++;
                $errors[] = 'Upgrade to a paid subscription to use the CMP IAB/TCF 2.3 feature';
            }

            // CHECK 2: Accept All button — AUTO-FIX: force to 1
            $totalChecked++;
            $contentSettings['gdpr']['cookie_notice']['accept_all_button'] = 1;
            $totalPassed++;

            // CHECK 3: Reject All button — AUTO-FIX: force to 1
            $totalChecked++;
            $contentSettings['gdpr']['cookie_notice']['reject_all_button'] = 1;
            $totalPassed++;

            // CHECK 4: Customize button — AUTO-FIX: force to 1
            $totalChecked++;
            $contentSettings['gdpr']['cookie_notice']['customize_button'] = 1;
            $totalPassed++;

            // CHECK 5: Revisit consent floating button — AUTO-FIX: force to 1
            $totalChecked++;
            $contentSettings['gdpr']['revisit_consent_button']['floating_button'] = 1;
            $totalPassed++;

            // CHECK 6: Show Google privacy policy — AUTO-FIX: force to 1
            $totalChecked++;
            $contentSettings['show_google_privacy_policy'] = true;
            $contentSettings['gdpr']['preference_center']['show_google_privacy_policy'] = 1;
            $totalPassed++;

            // AUTO-FIX: Set Google privacy URL default if empty/missing
            $googleUrl = (string) ($contentSettings['google_privacy_url'] ?? $contentSettings['gdpr']['preference_center']['google_privacy_url'] ?? '');
            if ($googleUrl === '') {
                $googleUrl = self::GOOGLE_PRIVACY_URL;
            }
            $contentSettings['google_privacy_url'] = $googleUrl;
            $contentSettings['gdpr']['preference_center']['google_privacy_url'] = $googleUrl;

            // PERSIST all auto-fixed banner settings (skip in verify-only mode)
            if (!$verifyOnly) {
                $this->bannerRepo->updateBannerSetting($bannerId, [
                    'general_setting' => json_encode($generalSettings, \JSON_THROW_ON_ERROR),
                    'content_setting' => json_encode($contentSettings, \JSON_THROW_ON_ERROR),
                ]);
            }

            // CHECK 7: Cookie/privacy policy URL (OCI stores this on the site, not as a banner field)
            $totalChecked++;
            if ($defaultPrivacyUrl !== '') {
                $totalPassed++;
            } else {
                $totalFailed++;
                $errors[] = 'Add cookie / privacy policy url';
            }

            // CHECK 8: Alt text for blocked content (banner field translation)
            $totalChecked++;
            $altText = false;
            $defaultLang = $this->languageRepo->getDefaultLanguage($siteId);
            if ($defaultLang !== null) {
                $langId = (int) $defaultLang['lang_id'];
                $contents = $this->bannerRepo->getUserBannerContent($siteId, $langId, 'gdpr');

                foreach ($contents as $content) {
                    $fieldName = (string) ($content['field_name'] ?? '');
                    $fieldValue = (string) ($content['u_field_value'] ?? '');

                    if ($fieldName === 'alt_text_blocked_content' && $fieldValue !== '') {
                        $altText = true;
                        break;
                    }
                }
            }
            if ($altText) {
                $totalPassed++;
            } else {
                $totalFailed++;
                $errors[] = 'Add alternate text for blocked content';
            }
        }

        // Determine status
        $status = $this->determineStatus($totalChecked, $totalPassed, $totalFailed, $templateName);

        // OVERRIDE: If site is not active, force "failed"
        $siteStatus = (string) ($siteData['status'] ?? 'inactive');
        if ($siteStatus !== 'active') {
            $status = 'failed';
        }

        // Save the applied template name (skip in verify-only mode)
        if (!$verifyOnly) {
            $this->siteRepo->updateSiteSettings($siteId, ['template_applied' => $templateName]);
        }

        return [
            'status' => $status,
            'total_passed' => $totalPassed,
            'total_failed' => $totalFailed,
            'total_checked' => $totalChecked,
            'errors' => $errors,
            'gcm_enabled' => (int) ($siteData['gcm_enabled'] ?? 0),
            'iab_enabled' => $actualIabEnabled ?? 0,
            'tag_fire_enabled' => (int) ($siteData['tag_fire_enabled'] ?? 0),
            'template_applied' => (string) ($siteData['template_applied'] ?? ''),
            'script_installed' => $siteStatus === 'active' && !empty($banners),
        ];
    }

    /**
     * Toggle site active/inactive status and re-check compliance.
     *
     * @return array{status: string, site_status: string}
     */
    public function toggleSiteStatus(int $siteId, string $newStatus): array
    {
        $this->siteRepo->updateStatus($siteId, $newStatus);

        // Re-check compliance without template update
        $result = $this->check($siteId);

        // Save compliance status
        $this->siteRepo->updateCompliantStatus($siteId, $result['status']);

        return [
            'status' => $result['status'],
            'site_status' => $newStatus,
        ];
    }

    /**
     * Run compliance check with explicit template and save result.
     *
     * @return array{status: string, total_passed: int, total_failed: int, total_checked: int, errors: list<string>}
     */
    public function checkAndSave(int $siteId, string $templateName): array
    {
        $result = $this->check($siteId, $templateName, isTemplateUpdate: true);

        // Save compliance status
        $this->siteRepo->updateCompliantStatus($siteId, $result['status']);

        return $result;
    }

    /**
     * Check a site's banner settings against its selected privacy frameworks.
     *
     * Returns a list of framework-specific warnings/violations based on the
     * merged rules from all selected frameworks.
     *
     * @return array{
     *     frameworks: list<string>,
     *     warnings: list<array{framework: string, check: string, message: string, severity: string}>,
     *     merged_rules: array<string, mixed>,
     * }
     */
    public function checkFrameworkCompliance(int $siteId): array
    {
        $result = [
            'frameworks' => [],
            'warnings' => [],
            'merged_rules' => [],
        ];

        $frameworkIds = $this->frameworkRepo->getFrameworksForSite($siteId);
        $result['frameworks'] = $frameworkIds;

        if ($frameworkIds === []) {
            $result['warnings'][] = [
                'framework' => 'none',
                'check' => 'no_frameworks_selected',
                'message' => 'No privacy frameworks selected. Select the frameworks that apply to your website visitors.',
                'severity' => 'error',
            ];

            return $result;
        }

        $merged = $this->frameworkService->getMergedRules($frameworkIds);
        $result['merged_rules'] = $merged;

        // Load current banner settings
        $banners = $this->bannerRepo->getSiteBannerSettings($siteId, 'gdpr');
        if (empty($banners)) {
            $result['warnings'][] = [
                'framework' => 'all',
                'check' => 'no_banner_configured',
                'message' => 'No banner configured for this site.',
                'severity' => 'error',
            ];

            return $result;
        }

        $banner = reset($banners);
        $contentSettings = $this->decodeJson((string) ($banner['content_setting'] ?? '{}'));
        $generalSettings = $this->decodeJson((string) ($banner['general_setting'] ?? '{}'));

        // Flatten content settings (may be nested under gdpr.cookie_notice)
        $cn = $contentSettings['gdpr']['cookie_notice'] ?? $contentSettings['cookie_notice'] ?? $contentSettings;

        // Check required buttons against actual settings
        foreach ($merged['required_buttons'] as $button) {
            $settingKey = match ($button) {
                'accept_all' => 'accept_all_button',
                'reject_all' => 'reject_all_button',
                'manage_preferences' => 'customize_button',
                default => null,
            };

            if ($settingKey !== null) {
                $enabled = (int) ($cn[$settingKey] ?? 0);
                if ($enabled !== 1) {
                    // Find which framework requires this button
                    $fwName = $this->findFrameworkRequiringButton($frameworkIds, $button);
                    $result['warnings'][] = [
                        'framework' => $fwName,
                        'check' => 'missing_button_' . $button,
                        'message' => ucfirst(str_replace('_', ' ', $button)) . ' button is required by ' . $fwName . ' but is currently disabled.',
                        'severity' => 'error',
                    ];
                }
            }
        }

        // Check Do Not Sell requirement
        if ($merged['do_not_sell_required']) {
            $hasCcpaFramework = \in_array('ccpa_cpra', $frameworkIds, true);
            if (!$hasCcpaFramework) {
                $fwName = $this->findFrameworkRequiringDoNotSell($frameworkIds);
                $result['warnings'][] = [
                    'framework' => $fwName,
                    'check' => 'do_not_sell_missing',
                    'message' => '"Do Not Sell or Share" link is required by ' . $fwName . '. Enable CCPA/opt-out banner type.',
                    'severity' => 'error',
                ];
            }
        }

        // Check GPC signal requirement
        if ($merged['must_honor_gpc']) {
            // GPC is handled automatically by the framework rules in the script,
            // but we warn if the user hasn't acknowledged it
            $result['warnings'][] = [
                'framework' => $this->findFrameworkRequiringGpc($frameworkIds),
                'check' => 'gpc_required',
                'message' => 'Global Privacy Control (GPC) signal must be honored. The consent script handles this automatically.',
                'severity' => 'info',
            ];
        }

        // Check Google Consent Mode requirement
        if ($merged['gcm_required']) {
            $siteData = $siteData ?? $this->siteRepo->findById($siteId);
            $gcmEnabled = (int) ($siteData['gcm_enabled'] ?? 0);
            if ($gcmEnabled !== 1) {
                $result['warnings'][] = [
                    'framework' => 'gdpr',
                    'check' => 'gcm_required',
                    'message' => 'Google Consent Mode v2 is required but not enabled.',
                    'severity' => 'error',
                ];
            }
        }

        // Check IAB TCF support
        if ($merged['iab_tcf_supported']) {
            $iabEnabled = (int) ($generalSettings['iab_support'] ?? 0);
            if ($iabEnabled !== 1) {
                $result['warnings'][] = [
                    'framework' => 'gdpr',
                    'check' => 'iab_tcf_recommended',
                    'message' => 'IAB TCF support is recommended for your selected frameworks but not enabled.',
                    'severity' => 'warning',
                ];
            }
        }

        // Check framework/country mismatch based on site TLD
        $siteData = $siteData ?? $this->siteRepo->findById($siteId);
        $domain = (string) ($siteData['domain'] ?? '');
        $inferredCountry = $this->inferCountryFromDomain($domain);
        if ($inferredCountry !== null) {
            $applicableFrameworks = $this->frameworkService->getFrameworksForCountry($inferredCountry);
            $missingFrameworks = array_diff($applicableFrameworks, $frameworkIds);
            if ($missingFrameworks !== []) {
                // Build a human-readable list of missing framework names
                $missingNames = [];
                foreach ($missingFrameworks as $fwId) {
                    $fw = $this->frameworkService->getFramework($fwId);
                    $missingNames[] = $fw['name'] ?? $fwId;
                }
                $result['warnings'][] = [
                    'framework' => 'coverage',
                    'check' => 'country_framework_mismatch',
                    'message' => 'Your site domain (' . $domain . ') suggests visitors from ' . $inferredCountry
                        . ', which is covered by: ' . implode(', ', $missingNames)
                        . '. Consider enabling these frameworks for full compliance.',
                    'severity' => 'error',
                ];
            }
        }

        // Check EU/EEA coverage gap — warn if no selected framework covers EU countries
        $coveredCountries = [];
        foreach ($frameworkIds as $fwId) {
            $fw = $this->frameworkService->getFramework($fwId);
            if ($fw !== null) {
                $coveredCountries = array_merge($coveredCountries, $fw['countries'] ?? []);
            }
        }
        $coveredCountries = array_unique(array_map('strtoupper', $coveredCountries));

        // Get EU/EEA countries from the GDPR framework definition
        $gdprFw = $this->frameworkService->getFramework('gdpr');
        $euCountries = $gdprFw !== null
            ? array_map('strtoupper', $gdprFw['countries'] ?? [])
            : ['AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO'];

        if (array_intersect($euCountries, $coveredCountries) === []) {
            $result['warnings'][] = [
                'framework' => 'coverage',
                'check' => 'eu_coverage_gap',
                'message' => 'No EU/EEA countries are covered by your selected frameworks. '
                    . 'The consent banner will use fallback rules for EU visitors. '
                    . 'Consider enabling GDPR for full EU compliance.',
                'severity' => 'error',
            ];
        }

        return $result;
    }

    /**
     * Find which selected framework requires a specific button.
     */
    private function findFrameworkRequiringButton(array $frameworkIds, string $button): string
    {
        foreach ($frameworkIds as $fwId) {
            $buttons = $this->frameworkService->getRequiredButtons($fwId);
            if (in_array($button, $buttons, true)) {
                $fw = $this->frameworkService->getFramework($fwId);

                return $fw['name'] ?? $fwId;
            }
        }

        return 'selected frameworks';
    }

    /**
     * Find which selected framework requires Do Not Sell.
     */
    private function findFrameworkRequiringDoNotSell(array $frameworkIds): string
    {
        foreach ($frameworkIds as $fwId) {
            if ($this->frameworkService->isDoNotSellRequired($fwId)) {
                $fw = $this->frameworkService->getFramework($fwId);

                return $fw['name'] ?? $fwId;
            }
        }

        return 'selected frameworks';
    }

    /**
     * Find which selected framework requires GPC.
     */
    private function findFrameworkRequiringGpc(array $frameworkIds): string
    {
        foreach ($frameworkIds as $fwId) {
            if ($this->frameworkService->mustHonorGpc($fwId)) {
                $fw = $this->frameworkService->getFramework($fwId);

                return $fw['name'] ?? $fwId;
            }
        }

        return 'selected frameworks';
    }

    // ─── Private helpers ────────────────────────────────────

    /**
     * Map template name to iab_status and fire_before_tag values.
     *
     * @return array{int, int}
     */
    private function mapTemplate(string $template): array
    {
        return match ($template) {
            'basic' => [0, 0],
            'advanced' => [0, 1],
            'basic_tcf' => [1, 0],
            'advanced_tcf' => [1, 1],
            default => [0, 0],
        };
    }

    /**
     * Load plan features for a user.
     *
     * Uses PricingService/SubscriptionService when available (new billing system),
     * falls back to legacy PlanRepository.
     *
     * @return array<string, int|string>
     */
    private function loadPlanFeatures(int $userId): array
    {
        // New billing system: get plan_key from subscription, features from pricing.json
        if ($this->pricingService !== null && $this->subscriptionService !== null) {
            $planKey = $this->subscriptionService->getPlanKey($userId);
            if ($planKey === null) {
                return [];
            }

            $featureKeys = $this->pricingService->getFeatureKeys($planKey);
            $features = [];
            foreach ($featureKeys as $key) {
                $features[$key] = 1;
            }

            return $features;
        }

        // Legacy fallback
        $isSubscribed = $this->planRepo->isSubscribed($userId);
        $isEnterprise = $this->planRepo->isEnterprise($userId);

        if (!$isSubscribed && !$isEnterprise) {
            return [];
        }

        if ($isEnterprise) {
            return $this->planRepo->getAllFeatures();
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        $planId = $userPlan !== null ? (int) $userPlan['plan_id'] : 0;

        $baseFeatures = $this->planRepo->getPlanFeatures($planId);
        $userOverrides = $this->planRepo->getUserPlanFeatureOverrides($userId, $planId);

        foreach ($userOverrides as $key => $value) {
            $baseFeatures[$key] = $value;
        }

        return $baseFeatures;
    }

    /**
     * Check if a feature is available.
     * Legacy behavior: returns true (available) if the feature is NOT in the list.
     *
     * @param array<string, int|string> $features
     */
    private function checkFeature(string $featureName, array $features): bool
    {
        if (!isset($features[$featureName])) {
            return true; // Legacy default: feature is available if not in list
        }

        return (int) $features[$featureName] === 1;
    }

    /**
     * Determine compliance status (mirrors legacy logic exactly).
     */
    private function determineStatus(int $totalChecked, int $totalPassed, int $totalFailed, string $templateName): string
    {
        if ($totalChecked <= 0) {
            return 'failed';
        }

        if ($totalPassed === $totalChecked) {
            return 'full';
        }

        if ($totalPassed > $totalFailed && $totalPassed < $totalChecked) {
            // Legacy is lenient: treat partial as full when template is set
            if ($templateName !== '') {
                return 'full';
            }

            return 'partial';
        }

        return 'failed';
    }

    /**
     * Decode JSON safely.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        if ($json === '' || $json === '{}') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Ensure a nested array path exists.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $path
     * @return array<string, mixed>
     */
    private function ensurePath(array $data, array $path): array
    {
        $current = &$data;
        foreach ($path as $key) {
            if (!isset($current[$key]) || !\is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        unset($current);

        return $data;
    }

    /**
     * Infer a country code from a domain's TLD.
     *
     * Returns null for generic TLDs (.com, .org, .net, .io, etc.)
     * where the site's target audience cannot be determined.
     */
    private function inferCountryFromDomain(string $domain): ?string
    {
        // Strip port if present (e.g. localhost:8106)
        $domain = strtolower(explode(':', $domain)[0]);

        // Skip localhost/IP addresses
        if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Check compound TLDs first (e.g. .co.uk, .com.br)
        $compoundMap = [
            'co.uk' => 'GB', 'org.uk' => 'GB', 'me.uk' => 'GB',
            'com.au' => 'AU', 'com.br' => 'BR', 'co.nz' => 'NZ',
            'co.za' => 'ZA', 'co.in' => 'IN', 'co.jp' => 'JP',
            'co.kr' => 'KR', 'com.mx' => 'MX', 'com.ar' => 'AR',
            'com.sg' => 'SG', 'com.hk' => 'HK', 'com.tw' => 'TW',
        ];

        $parts = explode('.', $domain);
        if (\count($parts) >= 3) {
            $compound = $parts[\count($parts) - 2] . '.' . $parts[\count($parts) - 1];
            if (isset($compoundMap[$compound])) {
                return $compoundMap[$compound];
            }
        }

        // Single TLD map (ccTLDs → ISO country codes)
        $tldMap = [
            // Europe
            'at' => 'AT', 'be' => 'BE', 'bg' => 'BG', 'hr' => 'HR',
            'cy' => 'CY', 'cz' => 'CZ', 'dk' => 'DK', 'ee' => 'EE',
            'fi' => 'FI', 'fr' => 'FR', 'de' => 'DE', 'gr' => 'GR',
            'hu' => 'HU', 'ie' => 'IE', 'it' => 'IT', 'lv' => 'LV',
            'lt' => 'LT', 'lu' => 'LU', 'mt' => 'MT', 'nl' => 'NL',
            'pl' => 'PL', 'pt' => 'PT', 'ro' => 'RO', 'sk' => 'SK',
            'si' => 'SI', 'es' => 'ES', 'se' => 'SE', 'is' => 'IS',
            'li' => 'LI', 'no' => 'NO', 'ch' => 'CH', 'uk' => 'GB',
            // Americas
            'us' => 'US', 'ca' => 'CA', 'br' => 'BR', 'mx' => 'MX',
            'ar' => 'AR', 'cl' => 'CL', 'co' => 'CO',
            // Asia-Pacific
            'au' => 'AU', 'nz' => 'NZ', 'jp' => 'JP', 'kr' => 'KR',
            'cn' => 'CN', 'in' => 'IN', 'sg' => 'SG', 'hk' => 'HK',
            'tw' => 'TW', 'th' => 'TH', 'my' => 'MY', 'ph' => 'PH',
            'id' => 'ID', 'vn' => 'VN',
            // Middle East / Africa
            'za' => 'ZA', 'ae' => 'AE', 'il' => 'IL', 'sa' => 'SA',
            'ng' => 'NG', 'ke' => 'KE', 'eg' => 'EG',
        ];

        $tld = end($parts);
        return $tldMap[$tld] ?? null;
    }
}
