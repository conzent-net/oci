<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Shared\Service\EditionService;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET  /sites/frameworks — Framework selection page
 * POST /app/sites/frameworks — Save selected frameworks
 */
final class SiteFrameworksHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly PrivacyFrameworkService $frameworkService,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly EditionService $edition,
        private readonly TwigEnvironment $twig,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?ScriptGenerationService $scriptService = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $method = strtoupper($request->getMethod());

        if ($method === 'POST') {
            return $this->handleSave($request, $user);
        }

        return $this->handleShow($request, $user);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleShow(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];
        $sites = $resolved['sites'] ?? [];

        $site = $this->siteRepo->findById($siteId);
        if ($site === null) {
            return ApiResponse::redirect('/sites');
        }

        // Verify ownership
        if (!$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::redirect('/sites');
        }

        $selectedFrameworks = $this->frameworkRepo->getFrameworksForSite($siteId);
        $groupedFrameworks = $this->frameworkService->getFrameworksGroupedByRegion();
        $maxFrameworks = $this->resolveMaxFrameworks((int) $user['id']);

        $html = $this->twig->render('pages/sites/frameworks.html.twig', [
            'title' => 'Privacy Frameworks',
            'active_page' => 'frameworks',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'site' => $site,
            'selectedFrameworks' => $selectedFrameworks,
            'groupedFrameworks' => $groupedFrameworks,
            'maxFrameworks' => $maxFrameworks,
        ]);

        return ApiResponse::html($html);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function handleSave(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (is_string($body)) {
            $body = json_decode($body, true) ?? [];
        }

        $siteId = (int) ($body['site_id'] ?? 0);
        $frameworkIds = $body['frameworks'] ?? [];

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 400);
        }

        // Verify ownership
        if (!$this->siteRepo->belongsToUser($siteId, (int) $user['id'])) {
            return ApiResponse::error('Unauthorized', 403);
        }

        if (!is_array($frameworkIds)) {
            return ApiResponse::error('Invalid frameworks', 400);
        }

        // Filter to valid framework IDs only
        $frameworkIds = array_values(array_filter($frameworkIds, 'is_string'));
        $invalid = $this->frameworkService->validateFrameworkIds($frameworkIds);
        if ($invalid !== []) {
            return ApiResponse::error('Unknown framework(s): ' . implode(', ', $invalid), 400);
        }

        // Check plan limit
        $maxFrameworks = $this->resolveMaxFrameworks((int) $user['id']);
        if ($maxFrameworks > 0 && count($frameworkIds) > $maxFrameworks) {
            return ApiResponse::error(
                "Your plan allows a maximum of {$maxFrameworks} privacy frameworks. Please upgrade your plan.",
                403,
            );
        }

        // Save
        $this->frameworkRepo->setFrameworksForSite($siteId, $frameworkIds);

        // Sync banner template & settings with new frameworks
        $this->syncBannerWithFrameworks($siteId, $frameworkIds);

        // Regenerate script with new framework rules
        if ($this->scriptService !== null) {
            try {
                $this->scriptService->generate($siteId);
            } catch (\Throwable) {
                // Script generation failure is non-fatal — user can retry via purge cache
            }
        }

        return ApiResponse::success([
            'message' => 'Privacy frameworks updated successfully',
            'frameworks' => $frameworkIds,
        ]);
    }

    /**
     * Resolve max_privacy_frameworks limit from plan. Returns 0 for unlimited.
     */
    private function resolveMaxFrameworks(int $userId): int
    {
        if (!$this->edition->arePlanLimitsEnforced()) {
            return 0; // Self-hosted: unlimited
        }

        if ($this->pricingService === null || $this->subscriptionService === null) {
            return 0;
        }

        $planKey = $this->subscriptionService->getPlanKey($userId);
        if ($planKey === null) {
            return 2; // No plan: default to personal limit
        }

        return $this->pricingService->getLimit($planKey, 'max_privacy_frameworks');
    }

    /**
     * Ensure banner template and settings are compatible with the new frameworks.
     *
     * - For gdpr_ccpa: ensures two site_banner records exist (one per consent model)
     * - Switches banner template when current one doesn't match the new display type
     * - Disables IAB support when GDPR is removed
     *
     * @param list<string> $frameworkIds
     */
    private function syncBannerWithFrameworks(int $siteId, array $frameworkIds): void
    {
        $hasGdpr = \in_array('gdpr', $frameworkIds, true)
            || \in_array('eprivacy_directive', $frameworkIds, true);
        $hasCcpa = \in_array('ccpa_cpra', $frameworkIds, true);

        $newDisplay = match (true) {
            $hasGdpr && $hasCcpa => 'gdpr_ccpa',
            $hasCcpa && !$hasGdpr => 'ccpa',
            default => 'gdpr',
        };

        // Keep oci_sites in sync — reset template since it may not match new frameworks
        $this->siteRepo->updateSiteSettings($siteId, [
            'display_banner_type' => $newDisplay,
            'template_applied' => null,
        ]);

        $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        if (empty($banners)) {
            return;
        }

        // For gdpr_ccpa: ensure both GDPR and CCPA banner records exist
        if ($newDisplay === 'gdpr_ccpa') {
            $this->ensureBothBannersExist($siteId, $banners);
            // Re-fetch after potential creation
            $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        }

        // When moving away from gdpr_ccpa, remove banners that no longer match
        // the active display type instead of switching their template (which leaves
        // them with stripped-down content settings).
        if ($newDisplay !== 'gdpr_ccpa' && \count($banners) > 1) {
            $kept = false;
            foreach ($banners as $banner) {
                $bannerId = (int) $banner['id'];
                $templateId = (int) ($banner['banner_template_id'] ?? 0);
                $templateRow = $this->db_getTemplate($templateId);
                $templateLaw = $this->getTemplateLaw($templateRow);

                // Keep the first banner that matches the target display type,
                // or the first banner if none match (it will be template-switched below).
                if (!$kept && ($templateLaw === $newDisplay || $templateLaw === 'gdpr')) {
                    $kept = true;
                    continue;
                }

                // Delete orphaned banners from the other consent type
                if ($templateLaw !== $newDisplay) {
                    $this->bannerRepo->deleteSiteBanner($bannerId);
                    continue;
                }

                // Duplicate of the target type — keep only the first
                if ($kept) {
                    $this->bannerRepo->deleteSiteBanner($bannerId);
                }
            }

            // Re-fetch after cleanup
            $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        }

        foreach ($banners as $banner) {
            $bannerId = (int) $banner['id'];
            $templateId = (int) ($banner['banner_template_id'] ?? 0);
            $generalSetting = json_decode((string) ($banner['general_setting'] ?? '{}'), true) ?: [];
            $updates = [];

            // Switch template if the current one doesn't support the new display type
            $needsTemplateSwitch = false;
            if ($newDisplay === 'ccpa') {
                $templateRow = $this->db_getTemplate($templateId);
                $templateLaw = $this->getTemplateLaw($templateRow);
                if ($templateLaw !== 'ccpa') {
                    $needsTemplateSwitch = true;
                }
            }
            if ($newDisplay === 'gdpr') {
                $templateRow = $this->db_getTemplate($templateId);
                $templateLaw = $this->getTemplateLaw($templateRow);
                if ($templateLaw !== 'gdpr') {
                    $needsTemplateSwitch = true;
                }
            }

            if ($needsTemplateSwitch) {
                $newTemplate = $this->bannerRepo->findTemplateForLaw($newDisplay);
                if ($newTemplate !== null) {
                    $updates['banner_template_id'] = (int) $newTemplate['id'];
                }
            }

            // Disable IAB support when GDPR is removed
            if (!$hasGdpr && !empty($generalSetting['iab_support'])) {
                $generalSetting['iab_support'] = 0;
                $updates['general_setting'] = json_encode($generalSetting, JSON_THROW_ON_ERROR);
            }

            if (!empty($updates)) {
                $this->bannerRepo->updateBannerSetting($bannerId, $updates);
            }
        }
    }

    /**
     * For gdpr_ccpa display mode, ensure the site has both a GDPR and a CCPA
     * banner record. Creates the missing one by cloning settings from the existing banner.
     *
     * @param array<int, array<string, mixed>> $banners Existing site banners
     */
    private function ensureBothBannersExist(int $siteId, array $banners): void
    {
        $hasGdprBanner = false;
        $hasCcpaBanner = false;

        foreach ($banners as $banner) {
            $templateId = (int) ($banner['banner_template_id'] ?? 0);
            $templateRow = $this->db_getTemplate($templateId);
            $law = $this->getTemplateLaw($templateRow);

            if ($law === 'gdpr') {
                $hasGdprBanner = true;
            } elseif ($law === 'ccpa') {
                $hasCcpaBanner = true;
            }
        }

        if (!$hasGdprBanner) {
            $this->createBannerForLaw($siteId, 'gdpr');
        }
        if (!$hasCcpaBanner) {
            $this->createBannerForLaw($siteId, 'ccpa');
        }
    }

    /**
     * Create a new site banner record for a given law type with default settings.
     */
    private function createBannerForLaw(int $siteId, string $law): void
    {
        $template = $this->bannerRepo->findTemplateForLaw($law);
        if ($template === null) {
            return;
        }

        $templateId = (int) $template['id'];

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

        $siteBannerId = $this->bannerRepo->createSiteBanner([
            'site_id' => $siteId,
            'banner_template_id' => $templateId,
            'layout_key' => $law === 'ccpa' ? 'ccpa/classic' : null,
            'general_setting' => json_encode($generalSetting, JSON_THROW_ON_ERROR),
            'layout_setting' => null,
            'content_setting' => json_encode($contentSetting, JSON_THROW_ON_ERROR),
            'color_setting' => json_encode(['light' => []], JSON_THROW_ON_ERROR),
        ]);

        // Resolve the site's default language for banner translations
        $langId = $this->resolveDefaultLanguageId($siteId);
        $this->bannerRepo->copyDefaultBannerTranslations($siteBannerId, $templateId, $langId);
    }

    /**
     * Get the default language ID for a site.
     * Falls back to 1 (English) which matches system defaults.
     */
    private function resolveDefaultLanguageId(int $siteId): int
    {
        // Site languages are not accessible here — use English as the base
        // language for default translations. Users can customize via the UI.
        return 1;
    }

    /**
     * Resolve the cookie_laws of a template to a simple string ('gdpr' or 'ccpa').
     */
    private function getTemplateLaw(?array $templateRow): string
    {
        if ($templateRow === null) {
            return 'gdpr';
        }

        $raw = (string) ($templateRow['cookie_laws'] ?? '');

        // Plain string: 'gdpr' or 'ccpa'
        if (\in_array($raw, ['gdpr', 'ccpa'], true)) {
            return $raw;
        }

        // JSON format: {"gdpr":1,"ccpa":0}
        $decoded = json_decode($raw, true);
        if (\is_array($decoded)) {
            if (!empty($decoded['ccpa'])) {
                return 'ccpa';
            }
            if (!empty($decoded['gdpr'])) {
                return 'gdpr';
            }
        }

        return 'gdpr';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function db_getTemplate(int $templateId): ?array
    {
        if ($templateId <= 0) {
            return null;
        }

        $banners = $this->bannerRepo->getAllBannerTemplates();
        foreach ($banners as $t) {
            if ((int) $t['id'] === $templateId) {
                return $t;
            }
        }

        return null;
    }
}
