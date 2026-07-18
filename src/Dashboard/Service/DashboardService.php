<?php

declare(strict_types=1);

namespace OCI\Dashboard\Service;

use OCI\Agency\Repository\AgencyRepositoryInterface;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Dashboard\DTO\AgencyDashboardData;
use OCI\Dashboard\DTO\ConsentReportData;
use OCI\Dashboard\DTO\CustomerDashboardData;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Site\Repository\PageviewRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

final class DashboardService
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly PageviewRepositoryInterface $pageviewRepo,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly AgencyRepositoryInterface $agencyRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?ComplianceCheckService $complianceCheckService = null,
    ) {}

    /**
     * Build the customer dashboard data for a specific site.
     *
     * @param array<string, mixed> $currentUser
     */
    public function getCustomerDashboard(array $currentUser, int $siteId): CustomerDashboardData
    {
        $userId = (int) $currentUser['id'];
        $sites = $this->siteRepo->findAllByUser($userId, 'active');

        $siteData = $this->siteRepo->findById($siteId);
        if ($siteData === null) {
            $siteData = [];
        }

        // Determine effective customer ID (agency plan model)
        $effectiveUserId = $userId;
        $agencyInfo = $this->agencyRepo->findAgencyForCustomer($userId);
        if ($agencyInfo !== null) {
            $agencyId = (int) $agencyInfo['id'];
            $priceModel = $this->planRepo->getPriceModel($agencyId);
            if ($priceModel === 'plan') {
                $effectiveUserId = $agencyId;
            }
        }

        // Plan and feature resolution
        $isPaidPlan = false;
        $planFeatures = [];
        $isSubscribed = false;
        $isEnterprise = false;

        // New billing system: get plan features from pricing.json via subscription
        if ($this->pricingService !== null && $this->subscriptionService !== null) {
            $isSubscribed = $this->subscriptionService->hasActiveAccess($effectiveUserId);
            if ($isSubscribed) {
                $isPaidPlan = true;
                $planKey = $this->subscriptionService->getPlanKey($effectiveUserId);
                if ($planKey !== null) {
                    $featureKeys = $this->pricingService->getFeatureKeys($planKey);
                    foreach ($featureKeys as $key) {
                        $planFeatures[$key] = 1;
                    }
                }
            }
        } else {
            // Legacy fallback
            $isSubscribed = $this->planRepo->isSubscribed($effectiveUserId);
            $isEnterprise = $this->planRepo->isEnterprise($effectiveUserId);
            $planId = 0;

            if ($isSubscribed || $isEnterprise) {
                $userPlan = $this->planRepo->getUserPlan($effectiveUserId);
                if ($userPlan !== null) {
                    $planId = (int) $userPlan['plan_id'];
                }

                if ($isEnterprise) {
                    $planFeatures = $this->planRepo->getAllFeatures();
                } else {
                    $basePlanFeatures = $this->planRepo->getPlanFeatures($planId);
                    $userOverrides = $this->planRepo->getUserPlanFeatureOverrides($effectiveUserId, $planId);
                    $planFeatures = $this->combineFeatures($basePlanFeatures, $userOverrides);
                }

                if ($planId > 0) {
                    $planInfo = $this->planRepo->findPlanById($planId);
                    $isPaidPlan = $this->determinePaidPlan($planInfo);
                }
            }
        }

        // Template info — always show the actual applied template from the DB.
        // The template cards already disable options the user's plan doesn't allow.
        $templateApplied = (string) ($siteData['template_applied'] ?? '');
        $templateLabel = $this->getTemplateLabel($templateApplied);
        $templateType = $templateApplied !== ''
            ? strtoupper(str_replace('_', ' + ', $templateApplied))
            : '';

        // Framework compliance check (must run before score calculation and recommendations)
        $frameworkCompliance = [];
        if ($this->complianceCheckService !== null) {
            try {
                $frameworkCompliance = $this->complianceCheckService->checkFrameworkCompliance($siteId);
            } catch (\Throwable) {
                $frameworkCompliance = ['frameworks' => [], 'warnings' => [], 'merged_rules' => []];
            }
        }

        // Scan info
        $scanInfo = $this->scanRepo->getLastCompletedScan($siteId) ?? [];
        $nextScan = $this->scanRepo->getNextScheduledScan($siteId) ?? [];
        $scanAnalysis = $this->buildScanAnalysis($scanInfo);

        // Wizard data
        $wizardData = $this->siteRepo->getWizard($siteId, $effectiveUserId) ?? [];

        // Check IAB requirement from wizard
        $checkIab = false;
        if (!empty($wizardData) && isset($wizardData['ads_type']) && (int) $wizardData['ads_type'] === 1) {
            $checkIab = true;
        }

        // Derive bannerType from selected frameworks (overrides legacy column)
        $bannerType = $this->deriveBannerType($siteData, $frameworkCompliance);

        // Recommendations checklist (uses derived bannerType)
        $recommendations = $this->buildRecommendations($siteData, $planFeatures, $siteId, $checkIab, $bannerType, $frameworkCompliance);
        $showRecommendations = true;

        // Compliance score — derived from recommendations (single source of truth)
        $siteStatus = (string) ($siteData['status'] ?? 'inactive');
        $complianceScore = $this->scoreFromRecommendations($recommendations, $siteStatus);
        $complianceStatus = $complianceScore === 100 ? 'full' : ($complianceScore >= 50 ? 'partial' : 'failed');

        // Recent consent logs
        $recentConsents = $this->consentRepo->getRecentLog($siteId);

        // Consent summary (7 days default)
        $consentSummary = $this->buildConsentSummary(
            $this->consentRepo->getConsentSummaryByDays($siteId, 7),
        );

        // Pageview data (7 days default, with gap filling)
        $pageviewData = $this->buildPageviewData(
            $this->pageviewRepo->getByDays($siteId, 7),
            7,
        );

        // Pageview usage (monthly limit)
        $pageviewsUsed = $this->pageviewRepo->getMonthlyTotalForUser($effectiveUserId);
        $pageviewsLimit = 0;
        if ($this->pricingService !== null && $this->subscriptionService !== null) {
            $planKey = $this->subscriptionService->getPlanKey($effectiveUserId);
            if ($planKey !== null) {
                $pageviewsLimit = $this->pricingService->getLimit($planKey, 'pageviews_per_month');
            }
        }

        // URLs
        $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8098';
        $websiteKey = (string) ($siteData['website_key'] ?? '');
        $scriptUrl = $this->scriptService->getScriptUrl($websiteKey);

        return new CustomerDashboardData(
            selectedSiteId: $siteId,
            sites: array_map(static fn (array $s): array => [
                'id' => (int) $s['id'],
                'site_name' => $s['site_name'] ?? null,
                'domain' => (string) $s['domain'],
                'status' => (string) $s['status'],
            ], $sites),
            siteData: $siteData,
            isSubscribed: $isSubscribed,
            isEnterprise: $isEnterprise,
            isPaidPlan: $isPaidPlan,
            templateApplied: $templateApplied,
            templateLabel: $templateLabel,
            templateType: $templateType,
            complianceScore: $complianceScore,
            complianceStatus: $complianceStatus,
            scanInfo: $scanInfo,
            scanAnalysis: $scanAnalysis,
            nextScan: $nextScan,
            wizardData: $wizardData,
            recommendations: $recommendations,
            showRecommendations: $showRecommendations,
            recentConsents: $recentConsents,
            consentSummary: $consentSummary,
            pageviewData: $pageviewData,
            websiteKey: $websiteKey,
            scriptUrl: $scriptUrl,
            gtmWizardUrl: $baseUrl . '/gtm-wizard',
            helpUrl: 'https://help.conzent.net/collection/integrations',
            bannerType: $bannerType,
            checkIab: $checkIab,
            planFeatures: $planFeatures,
            pageviewsUsed: $pageviewsUsed,
            pageviewsLimit: $pageviewsLimit,
            frameworkCompliance: $frameworkCompliance,
        );
    }

    /**
     * Build agency/reseller dashboard data.
     *
     * @param array<string, mixed> $currentUser
     */
    public function getAgencyDashboard(array $currentUser): AgencyDashboardData
    {
        $userId = (int) $currentUser['id'];
        $priceModel = $this->planRepo->getPriceModel($userId);

        $agencyInfo = $this->agencyRepo->findByUserId($userId);

        $payoutCurrency = '$';
        if ($agencyInfo !== null && ($agencyInfo['country_code'] ?? '') === 'DK') {
            $payoutCurrency = 'kr.';
        }

        $payoutAmount = 0.0;
        if ($priceModel === 'commission') {
            $payoutAmount = $this->agencyRepo->getPayoutLast30Days($userId);
        }

        // Build commission chart data (last 12 months with gap filling)
        $commissionData = $this->buildMonthlyData(
            $priceModel === 'commission' ? $this->agencyRepo->getMonthlyCommissions($userId) : [],
            'total_commission',
            'commission',
            12,
        );

        // Build customer chart data (last 12 months with gap filling)
        $customerData = $this->buildMonthlyData(
            $this->agencyRepo->getMonthlyCustomers($userId),
            'total_customers',
            'customers',
            12,
        );

        return new AgencyDashboardData(
            userId: $userId,
            priceModel: $priceModel,
            payoutCurrency: $payoutCurrency,
            payoutAmount: $payoutAmount,
            commissionData: $commissionData,
            customerData: $customerData,
        );
    }

    /**
     * Get consent report data for AJAX calls.
     */
    public function getConsentReport(int $siteId, string $reportType, string $dateRange = ''): ConsentReportData
    {
        $rows = match ($reportType) {
            '7days' => $this->consentRepo->getConsentSummaryByDays($siteId, 7),
            '30days' => $this->consentRepo->getConsentSummaryByDays($siteId, 30),
            'alltime' => $this->consentRepo->getConsentSummary($siteId),
            'custom_range' => $this->parseAndQueryDateRange($siteId, $dateRange),
            default => $this->consentRepo->getConsentSummaryByDays($siteId, 7),
        };

        return $this->buildConsentSummary($rows);
    }

    /**
     * Get pageview report data for AJAX calls.
     *
     * @return array<string, array{date: string, views: int}>
     */
    public function getPageviewReport(int $siteId, string $reportType, string $dateRange = ''): array
    {
        $days = match ($reportType) {
            '7days' => 7,
            '30days' => 30,
            default => 7,
        };

        if ($reportType === 'alltime') {
            $rows = $this->pageviewRepo->getAll($siteId);
            return $this->buildPageviewData($rows, 0);
        }

        if ($reportType === 'custom_range') {
            $dateRange = str_replace(' ', '', $dateRange);
            $dates = explode('-', $dateRange);
            if (\count($dates) >= 2) {
                $startDate = date('Y-m-d', (int) strtotime($dates[0]));
                $endDate = date('Y-m-d', (int) strtotime($dates[1]));
                $rows = $this->pageviewRepo->getByDateRange($siteId, $startDate, $endDate);
                $diff = (int) (((int) strtotime($endDate) - (int) strtotime($startDate)) / 86400);
                return $this->buildPageviewData($rows, $diff, $startDate);
            }
        }

        $rows = $this->pageviewRepo->getByDays($siteId, $days);
        return $this->buildPageviewData($rows, $days);
    }

    /**
     * Determine the selected site ID for the current user.
     *
     * Resolution order:
     *   1. site_id cookie (set by POST /app/switch-site)
     *   2. First active site (fallback)
     *
     * The site_id is always validated against the user's owned sites.
     *
     * @param array<string, mixed> $currentUser
     * @param array<string, string> $cookies
     * @return array{siteId: int, sites: array<int, array<string, mixed>>, redirect: string|null}
     */
    public function resolveSiteId(array $currentUser, array $cookies): array
    {
        $userId = (int) $currentUser['id'];

        // Include both active and suspended sites so users can navigate freely.
        // Suspended sites already block script deployment — no need to block the UI.
        $sites = $this->siteRepo->findAllByUser($userId, 'active');
        if (empty($sites)) {
            $sites = $this->siteRepo->findAllByUser($userId, 'suspended');
        }

        if (empty($sites)) {
            $allSites = $this->siteRepo->findAllByUser($userId);
            $company = $this->planRepo->getUserCompany($userId);

            if ($company === null) {
                return ['siteId' => 0, 'sites' => [], 'redirect' => '/account/setup'];
            }

            if (empty($allSites)) {
                return ['siteId' => 0, 'sites' => [], 'redirect' => '/sites'];
            }

            return ['siteId' => 0, 'sites' => $allSites, 'redirect' => '/sites'];
        }

        $siteIds = array_map(static fn (array $s): int => (int) $s['id'], $sites);
        $defaultSiteId = $siteIds[0];

        // Determine site from cookie (set server-side by SiteSwitchHandler)
        $siteId = 0;
        if (isset($cookies['site_id'])) {
            $siteId = (int) $cookies['site_id'];
        }

        if (!\in_array($siteId, $siteIds, true)) {
            $siteId = $defaultSiteId;
        }

        return ['siteId' => $siteId, 'sites' => $sites, 'redirect' => null];
    }

    // ─── Private helpers ────────────────────────────────────

    /**
     * Build scan analysis from the last completed scan's cookie breakdown.
     *
     * @param array<string, mixed> $scanInfo
     * @return array{total: int, necessary: int, non_necessary: int, unclassified: int, categories: array<string, int>, status: string}
     */
    private function buildScanAnalysis(array $scanInfo): array
    {
        $empty = [
            'total' => 0,
            'necessary' => 0,
            'non_necessary' => 0,
            'unclassified' => 0,
            'categories' => [],
            'status' => 'none',
        ];

        if (empty($scanInfo) || !isset($scanInfo['id'])) {
            return $empty;
        }

        $breakdown = $this->scanRepo->getScanCookieBreakdown((int) $scanInfo['id']);
        if (empty($breakdown)) {
            $empty['status'] = 'clean';
            return $empty;
        }

        $total = 0;
        $necessary = 0;
        $unclassified = 0;
        $nonNecessary = 0;
        $categories = [];

        foreach ($breakdown as $row) {
            $slug = (string) $row['category_slug'];
            $count = (int) $row['total'];
            $total += $count;
            $categories[$slug] = $count;

            if ($slug === 'necessary') {
                $necessary = $count;
            } elseif ($slug === 'unclassified') {
                $unclassified = $count;
            } else {
                $nonNecessary += $count;
            }
        }

        // Status: clean = only necessary, review = has non-necessary but all categorized, warning = has unclassified
        $status = 'clean';
        if ($unclassified > 0) {
            $status = 'warning';
        } elseif ($nonNecessary > 0) {
            $status = 'review';
        }

        return [
            'total' => $total,
            'necessary' => $necessary,
            'non_necessary' => $nonNecessary,
            'unclassified' => $unclassified,
            'categories' => $categories,
            'status' => $status,
        ];
    }

    /**
     * @param array<int, array{consent_status: string, total: int}> $rows
     */
    private function buildConsentSummary(array $rows): ConsentReportData
    {
        $accepted = 0;
        $rejected = 0;
        $partiallyAccepted = 0;

        foreach ($rows as $row) {
            $status = $row['consent_status'];
            $total = (int) $row['total'];

            // Legacy: 1 = accepted, 2 = partially_accepted, 0 = rejected
            // OCI: string statuses
            if ($status === '1' || $status === 'accepted') {
                $accepted = $total;
            } elseif ($status === '2' || $status === 'partially_accepted') {
                $partiallyAccepted = $total;
            } else {
                $rejected += $total;
            }
        }

        return new ConsentReportData($accepted, $rejected, $partiallyAccepted);
    }

    /**
     * Build pageview data with gap filling.
     *
     * @param array<int, array{period_date: string, pageview_count: int}> $rows
     * @return array<string, array{date: string, views: int}>
     */
    private function buildPageviewData(array $rows, int $days, string $startDate = ''): array
    {
        $items = [];

        if ($days > 0) {
            $start = $startDate !== ''
                ? $startDate
                : date('Y-m-d', (int) strtotime("today - {$days} days"));

            for ($i = 0; $i < $days; $i++) {
                $date = date('Y-m-d', (int) strtotime($start . " + {$i} days"));
                $items[$date] = ['date' => $date, 'views' => 0];
            }
        }

        foreach ($rows as $row) {
            $date = (string) $row['period_date'];
            $items[$date] = ['date' => $date, 'views' => (int) $row['pageview_count']];
        }

        return $items;
    }

    /**
     * Build monthly data with gap filling (last N months).
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function buildMonthlyData(array $rows, string $valueKey, string $outputKey, int $months): array
    {
        $items = [];
        $start = date('Y-m-d', (int) strtotime("today - {$months} month"));

        for ($i = 0; $i < $months; $i++) {
            $date = date('m-Y', (int) strtotime($start . " + {$i} month"));
            $items[$date] = ['date' => $date, $outputKey => 0];
        }

        foreach ($rows as $row) {
            $month = (string) $row['date_month'];
            $items[$month] = ['date' => $month, $outputKey => $row[$valueKey]];
        }

        return $items;
    }

    /**
     * Parse custom date range and query consent summary.
     *
     * @return array<int, array{consent_status: string, total: int}>
     */
    private function parseAndQueryDateRange(int $siteId, string $dateRange): array
    {
        $dateRange = str_replace(' ', '', $dateRange);
        $dates = explode('-', $dateRange);
        if (\count($dates) < 2) {
            return $this->consentRepo->getConsentSummaryByDays($siteId, 7);
        }

        $startDate = date('Y-m-d', (int) strtotime($dates[0]));
        $endDate = date('Y-m-d', (int) strtotime($dates[1]));

        return $this->consentRepo->getConsentSummaryByDateRange($siteId, $startDate, $endDate);
    }

    /**
     * Map template key to human-readable label.
     */
    private function getTemplateLabel(string $template): string
    {
        return match ($template) {
            'basic' => 'Google Consent Mode Basic',
            'advanced' => 'Google Consent Mode Advanced',
            'basic_tcf' => 'Google Consent Mode Basic + TCF',
            'advanced_tcf' => 'Google Consent Mode Advanced + TCF',
            default => 'None',
        };
    }

    /**
     * Check if template needs to be downgraded based on plan features.
     *
     * @param array<string, int|string> $planFeatures
     */
    private function checkTemplateDowngrade(string $template, array $planFeatures): string
    {
        if ($template === '') {
            return '';
        }

        if (!$this->checkFeature('advanced_template', $planFeatures) && $template === 'advanced') {
            return 'basic';
        }

        if (!$this->checkFeature('basic_tcf_template', $planFeatures) && $template === 'basic_tcf') {
            return 'basic';
        }

        if (!$this->checkFeature('advanced_tcf_template', $planFeatures) && $template === 'advanced_tcf') {
            return 'basic';
        }

        return '';
    }

    /**
     * Check if a feature is enabled.
     *
     * @param array<string, int|string> $features
     */
    private function checkFeature(string $featureName, array $features): bool
    {
        if (!isset($features[$featureName])) {
            return false;
        }

        return (int) $features[$featureName] === 1 || $features[$featureName] === '1';
    }

    /**
     * Determine if a plan is a paid plan (mirrors legacy logic).
     *
     * @param array<string, mixed>|null $planInfo
     */
    private function determinePaidPlan(?array $planInfo): bool
    {
        if ($planInfo === null) {
            return false;
        }

        $price = (float) ($planInfo['monthly_price'] ?? 0);
        $yearlyPrice = (float) ($planInfo['yearly_price'] ?? 0);
        $isDefault = (int) ($planInfo['is_default'] ?? 0);
        $isTrial = (int) ($planInfo['is_trial'] ?? 0);

        if (($price > 0 || $yearlyPrice > 0) && $isDefault !== 1) {
            return true;
        }

        if (($price === 0.0 || $yearlyPrice === 0.0) && $isDefault !== 1) {
            return $isTrial !== 1;
        }

        return false;
    }

    /**
     * Calculate compliance score from actual banner/site settings.
     *
     * Checks: GCM enabled, accept button, reject button, revisit button,
     * Google privacy policy. Each check is worth equal weight.
     *
     * @param array<string, mixed> $siteData
     */
    private function deriveBannerType(array $siteData, array $frameworkCompliance): string
    {
        $fwIds = $frameworkCompliance['frameworks'] ?? [];
        if ($fwIds === []) {
            // No frameworks selected — fall back to legacy column
            return (string) ($siteData['display_banner_type'] ?? 'gdpr');
        }

        $hasGdpr = \in_array('gdpr', $fwIds, true) || \in_array('eprivacy_directive', $fwIds, true);
        $hasCcpa = \in_array('ccpa_cpra', $fwIds, true);

        if ($hasGdpr && $hasCcpa) {
            return 'gdpr_ccpa';
        }
        if ($hasCcpa) {
            return 'ccpa';
        }

        // Any other framework combination (GDPR, TDDDG, UK GDPR, etc.) → gdpr mode
        return 'gdpr';
    }

    /**
     * Derive compliance score from the recommendations checklist.
     *
     * Only 'done' and 'fail' items count — 'info' items are advisory.
     * Inactive sites always return 25.
     *
     * @param array<int, array{text: string, status: string}> $recommendations
     */
    private function scoreFromRecommendations(array $recommendations, string $siteStatus): int
    {
        if ($siteStatus !== 'active') {
            return 25;
        }

        $scored = array_filter($recommendations, static fn (array $r): bool => $r['status'] === 'done' || $r['status'] === 'fail');
        $total = \count($scored);

        if ($total === 0) {
            return 100;
        }

        $passed = \count(array_filter($scored, static fn (array $r): bool => $r['status'] === 'done'));

        return (int) round(($passed / $total) * 100);
    }

    /**
     * Get fresh recommendations for a site (used by AJAX refresh).
     *
     * @param array<string, mixed> $currentUser
     * @return array<int, array{text: string, status: string}>
     */
    /**
     * @return array<int, array{text: string, status: string}>
     */
    public function getRecommendations(array $currentUser, int $siteId): array
    {
        return $this->getRecommendationsWithScore($currentUser, $siteId)['recommendations'];
    }

    /**
     * Return recommendations and the server-calculated compliance score.
     *
     * @param array<string, mixed> $currentUser
     * @return array{recommendations: array<int, array{text: string, status: string}>, complianceScore: int}
     */
    public function getRecommendationsWithScore(array $currentUser, int $siteId): array
    {
        $userId = (int) $currentUser['id'];
        $siteData = $this->siteRepo->findById($siteId);
        if ($siteData === null) {
            return ['recommendations' => [], 'complianceScore' => 25];
        }

        // Effective user for plan resolution
        $effectiveUserId = $userId;
        $agencyInfo = $this->agencyRepo->findAgencyForCustomer($userId);
        if ($agencyInfo !== null) {
            $priceModel = $this->planRepo->getPriceModel((int) $agencyInfo['id']);
            if ($priceModel === 'plan') {
                $effectiveUserId = (int) $agencyInfo['id'];
            }
        }

        $planFeatures = [];

        // New billing system
        if ($this->pricingService !== null && $this->subscriptionService !== null) {
            $planKey = $this->subscriptionService->getPlanKey($effectiveUserId);
            if ($planKey !== null) {
                $featureKeys = $this->pricingService->getFeatureKeys($planKey);
                foreach ($featureKeys as $key) {
                    $planFeatures[$key] = 1;
                }
            }
        } else {
            // Legacy fallback
            $isSubscribed = $this->planRepo->isSubscribed($effectiveUserId);
            $isEnterprise = $this->planRepo->isEnterprise($effectiveUserId);
            if ($isSubscribed || $isEnterprise) {
                $userPlan = $this->planRepo->getUserPlan($effectiveUserId);
                $planId = $userPlan !== null ? (int) $userPlan['plan_id'] : 0;
                if ($isEnterprise) {
                    $planFeatures = $this->planRepo->getAllFeatures();
                } else {
                    $basePlanFeatures = $this->planRepo->getPlanFeatures($planId);
                    $userOverrides = $this->planRepo->getUserPlanFeatureOverrides($effectiveUserId, $planId);
                    $planFeatures = $this->combineFeatures($basePlanFeatures, $userOverrides);
                }
            }
        }

        $wizardData = $this->siteRepo->getWizard($siteId, $effectiveUserId) ?? [];
        $checkIab = !empty($wizardData) && isset($wizardData['ads_type']) && (int) $wizardData['ads_type'] === 1;

        $frameworkCompliance = [];
        if ($this->complianceCheckService !== null) {
            try {
                $frameworkCompliance = $this->complianceCheckService->checkFrameworkCompliance($siteId);
            } catch (\Throwable) {
                $frameworkCompliance = ['frameworks' => [], 'warnings' => [], 'merged_rules' => []];
            }
        }

        $bannerType = $this->deriveBannerType($siteData, $frameworkCompliance);
        $recommendations = $this->buildRecommendations($siteData, $planFeatures, $siteId, $checkIab, $bannerType, $frameworkCompliance);

        $siteStatus = (string) ($siteData['status'] ?? 'inactive');
        $complianceScore = $this->scoreFromRecommendations($recommendations, $siteStatus);

        return [
            'recommendations' => $recommendations,
            'complianceScore' => $complianceScore,
        ];
    }

    /**
     * Build the recommendations checklist from actual banner settings.
     *
     * @param array<string, mixed> $siteData
     * @param array<string, int|string> $planFeatures
     * @return array<int, array{text: string, status: string}>
     */
    private function buildRecommendations(array $siteData, array $planFeatures, int $siteId, bool $checkIab, string $bannerType = 'gdpr', array $frameworkCompliance = []): array
    {
        $checklist = [];
        $isGdpr = in_array($bannerType, ['gdpr', 'both', 'gdpr_ccpa', 'combined'], true);
        $isCcpa = in_array($bannerType, ['ccpa', 'both', 'gdpr_ccpa', 'combined'], true);

        // Load banner options once per type
        $gdprOptions = [];
        if ($isGdpr) {
            $gdprOptions = $this->parseBannerOptions(
                $this->bannerRepo->getSiteBannerSettings($siteId, 'gdpr'),
            );
        }

        // 1. Google Consent Mode
        $gcmEnabled = (int) ($siteData['gcm_enabled'] ?? 0);
        $templateApplied = (string) ($siteData['template_applied'] ?? '');
        if ($gcmEnabled === 1) {
            $templateLabel = $templateApplied !== ''
                ? strtoupper(str_replace('_', ' + ', $templateApplied))
                : 'Custom Settings';
            $gcmText = 'Google Consent Mode <b><span style="color:#22b573;">(' . $templateLabel . ')</span></b> is active';
            $checklist[] = ['text' => $gcmText, 'status' => 'done'];
        } else {
            $checklist[] = ['text' => 'Google Consent Mode - Should be enabled', 'status' => 'fail'];
        }

        // 2. Google Tags detection (GTM/gtag.js)
        if ($gcmEnabled === 1) {
            $gtmAutoInjected = !empty($siteData['gtm_container_id']);

            if ($gtmAutoInjected) {
                $checklist[] = ['text' => 'Google Tag Manager (<b>' . htmlspecialchars((string) $siteData['gtm_container_id']) . '</b>) auto-injected by Conzent', 'status' => 'done'];
            } else {
                $gcmConfigRaw = $siteData['gcm_config_status'] ?? null;
                $gcmConfig = $gcmConfigRaw !== null ? json_decode((string) $gcmConfigRaw, true) : null;

                if (\is_array($gcmConfig) && isset($gcmConfig['consent']['status'])) {
                    $gcmActive = (int) $gcmConfig['consent']['status'];
                    if ($gcmActive === 1) {
                        $checklist[] = ['text' => 'Google Tags detected on your site', 'status' => 'done'];
                    } else {
                        $checklist[] = ['text' => 'Google Tags (GTM or gtag.js) not detected - Install Google Tag Manager or gtag.js for Consent Mode to work', 'status' => 'fail'];
                    }
                } else {
                    $checklist[] = ['text' => 'Google Tag Manager not verified - You get the best compliance with Google Tag Manager', 'status' => 'info'];
                }
            }
        }

        // 3. CCPA cookie policy
        if ($isCcpa) {
            if ($this->checkCookiePolicyUrl($siteData, $siteId, 'ccpa')) {
                $checklist[] = ['text' => 'US Laws Cookie Policy', 'status' => 'done'];
            } else {
                $checklist[] = ['text' => 'US Laws Cookie Policy - Must be set', 'status' => 'fail'];
            }
        }

        // 4. GDPR-specific checks
        if ($isGdpr) {
            // Accept All + Reject All buttons
            $acceptBtn = $this->getBannerOption($gdprOptions, 'content.accept_all_button');
            $rejectBtn = $this->getBannerOption($gdprOptions, 'content.reject_all_button');
            if ($acceptBtn && $rejectBtn) {
                $checklist[] = ['text' => 'Buttons uses Accept All and Reject All', 'status' => 'done'];
            } else {
                $checklist[] = ['text' => 'Buttons uses Accept All and Reject All (must be set)', 'status' => 'fail'];
            }

            // Revisit button
            if ($this->getBannerOption($gdprOptions, 'content.floating_button')) {
                $checklist[] = ['text' => 'Revisit button is configured', 'status' => 'done'];
            } else {
                $checklist[] = ['text' => 'Revisit button is configured (must be on)', 'status' => 'fail'];
            }

            // Google Privacy Policy
            if ($this->getBannerOption($gdprOptions, 'content.show_google_privacy_policy')) {
                $checklist[] = ['text' => 'Show Google Policy', 'status' => 'done'];
                $checklist[] = ['text' => 'Google Cookie Policy Enabled', 'status' => 'done'];
            } else {
                $checklist[] = ['text' => 'Show Google Policy - Must be set', 'status' => 'fail'];
                $checklist[] = ['text' => 'Google Cookie Policy - Must be enabled', 'status' => 'fail'];
            }

            // Close button (should be deactivated for GDPR compliance)
            if ($this->getBannerOption($gdprOptions, 'content.close_button')) {
                $checklist[] = ['text' => 'Close button must be deactivated', 'status' => 'fail'];
            }
        }

        // 5. IAB/TCF
        if ($checkIab) {
            if ($this->getBannerOption($gdprOptions, 'general.iab_support')) {
                $checklist[] = ['text' => 'IAB/TCF', 'status' => 'done'];
            } else {
                $checklist[] = ['text' => 'Enable IAB/TCF (Google Ads, AdSense or AdMob)', 'status' => 'fail'];
            }
        }

        // 6. Privacy frameworks selected
        $hasFrameworks = ($frameworkCompliance['frameworks'] ?? []) !== [];
        if ($hasFrameworks) {
            $checklist[] = ['text' => 'Privacy framework configured', 'status' => 'done'];
        } else {
            $checklist[] = ['text' => 'Select at least one privacy framework (e.g. GDPR, CCPA) in the setup wizard', 'status' => 'fail'];
        }

        // 7. Compliance template applied
        $templateApplied = (string) ($siteData['template_applied'] ?? '');
        if ($templateApplied !== '') {
            $checklist[] = ['text' => 'Compliance template applied', 'status' => 'done'];
        } else {
            $checklist[] = ['text' => 'Apply a compliance template to configure recommended settings', 'status' => 'fail'];
        }

        // 8. Framework compliance warnings
        // Only actionable errors (missing buttons, missing Do Not Sell) count as 'fail'.
        // Coverage suggestions (country mismatch, EU gap) and soft recommendations are 'info'.
        $fwWarnings = $frameworkCompliance['warnings'] ?? [];
        foreach ($fwWarnings as $w) {
            $check = $w['check'] ?? '';
            $severity = $w['severity'] ?? 'info';

            // GPC is handled automatically — skip from recommendations entirely
            if ($check === 'gpc_required') {
                continue;
            }

            // Coverage suggestions are advisory, not requirements
            $isCoverageSuggestion = \in_array($check, ['country_framework_mismatch', 'eu_coverage_gap', 'iab_tcf_recommended'], true);

            if ($isCoverageSuggestion) {
                $status = 'info';
            } else {
                $status = $severity === 'error' ? 'fail' : 'info';
            }

            $checklist[] = ['text' => $w['message'] ?? '', 'status' => $status];
        }

        return $checklist;
    }

    /**
     * Check if cookie policy URL is set for a specific consent type.
     *
     * @param array<string, mixed> $siteData
     */
    private function checkCookiePolicyUrl(array $siteData, int $siteId, string $type): bool
    {
        $defaultPrivacyUrl = (string) ($siteData['privacy_policy_url'] ?? '');
        $defaultLang = $this->languageRepo->getDefaultLanguage($siteId);
        if ($defaultLang === null) {
            return $defaultPrivacyUrl !== '';
        }

        $contents = $this->bannerRepo->getUserBannerContent($siteId, $defaultLang['lang_id'], $type);
        foreach ($contents as $content) {
            if (($content['field_name'] ?? '') === 'cookie_policy_url') {
                $value = (string) ($content['u_field_value'] ?? '');
                if ($value !== '') {
                    return true;
                }

                return $defaultPrivacyUrl !== '';
            }
        }

        return $defaultPrivacyUrl !== '';
    }

    /**
     * Parse banner options from the JSON stored in site_banners.
     *
     * @param array<int, array<string, mixed>> $banners
     * @return array<string, mixed>
     */
    private function parseBannerOptions(array $banners): array
    {
        if (empty($banners)) {
            return [];
        }

        $banner = reset($banners);

        // OCI stores settings as JSON in content_setting or general_setting
        $general = [];
        $content = [];

        if (isset($banner['general_setting'])) {
            $decoded = json_decode((string) $banner['general_setting'], true);
            if (\is_array($decoded)) {
                $general = $decoded;
            }
        }

        if (isset($banner['content_setting'])) {
            $decoded = json_decode((string) $banner['content_setting'], true);
            if (\is_array($decoded)) {
                $content = $decoded;
            }
        }

        return ['general' => $general, 'content' => $content];
    }

    /**
     * Get a nested banner option using dot notation.
     *
     * @param array<string, mixed> $options
     */
    private function getBannerOption(array $options, string $path): bool
    {
        $keys = explode('.', $path);
        $current = $options;

        foreach ($keys as $key) {
            if (!\is_array($current) || !isset($current[$key])) {
                return false;
            }
            $current = $current[$key];
        }

        return (int) $current === 1;
    }

    /**
     * Combine base plan features with user-specific overrides.
     * Mirrors legacy combineOnlyFeatures() function.
     *
     * @param array<string, int|string> $baseFeatures
     * @param array<string, int|string> $overrides
     * @return array<string, int|string>
     */
    private function combineFeatures(array $baseFeatures, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            $baseFeatures[$key] = $value;
        }

        return $baseFeatures;
    }
}
