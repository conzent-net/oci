<?php

declare(strict_types=1);

namespace OCI\Dashboard\Service;

use OCI\Agency\Repository\AgencyRepositoryInterface;
use OCI\Banner\Repository\BannerRepositoryInterface;
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
}
