<?php

declare(strict_types=1);

namespace OCI\Dashboard\DTO;

/**
 * All data needed to render the customer dashboard view.
 */
final readonly class CustomerDashboardData
{
    /**
     * @param array<int, array{id: int, site_name: ?string, domain: string, status: string}> $sites
     * @param array<string, mixed> $siteData
     * @param array<string, mixed> $scanInfo
     * @param array<string, mixed> $nextScan
     * @param array<string, mixed> $wizardData
     * @param array<int, array{text: string, status: string}> $recommendations
     * @param array<int, array<string, mixed>> $recentConsents
     * @param \OCI\Dashboard\DTO\ConsentReportData $consentSummary
     * @param array<string, array{date: string, views: int}> $pageviewData
     */
    public function __construct(
        public int $selectedSiteId,
        public array $sites,
        public array $siteData,
        public bool $isSubscribed,
        public bool $isEnterprise,
        public bool $isPaidPlan,
        public string $templateApplied,
        public string $templateLabel,
        public string $templateType,
        public int $complianceScore,
        public string $complianceStatus,
        public array $scanInfo,
        /** @var array{total: int, necessary: int, non_necessary: int, unclassified: int, categories: array<string, int>, status: string} */
        public array $scanAnalysis,
        public array $nextScan,
        public array $wizardData,
        public array $recommendations,
        public bool $showRecommendations,
        public array $recentConsents,
        public ConsentReportData $consentSummary,
        public array $pageviewData,
        public string $websiteKey,
        public string $scriptUrl,
        public string $gtmWizardUrl,
        public string $helpUrl,
        public string $bannerType,
        public bool $checkIab,
        /** @var array<string, int|string> */
        public array $planFeatures,
        public int $pageviewsUsed = 0,
        public int $pageviewsLimit = 0,
        /** @var array{frameworks: list<string>, warnings: list<array<string, string>>, merged_rules: array<string, mixed>} */
        public array $frameworkCompliance = [],
    ) {}
}
