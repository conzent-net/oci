<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\LayoutService;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /banners — Banner configuration for the current site.
 *
 * Mirrors legacy: consent_banner_setting.php
 * - Full settings form with General, Layout, Content, Color sections
 * - Site selector for multi-site users
 * - Banner template info
 * - Site language list for content editing
 */
final class BannerListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly LayoutService $layoutService,
        private readonly TwigEnvironment $twig,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly PrivacyFrameworkService $frameworkService,
        private readonly PricingService $pricingService,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        // Resolve site
        $sites = $this->siteRepo->findAllByUser($userId);

        if ($sites === []) {
            return ApiResponse::redirect('/sites');
        }

        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);
        $siteId = 0;

        if (isset($queryParams['site_id'])) {
            $siteId = (int) $queryParams['site_id'];
        } elseif (isset($cookies['site_id']) && \in_array((int) $cookies['site_id'], $siteIds, true)) {
            $siteId = (int) $cookies['site_id'];
        }

        if (!\in_array($siteId, $siteIds, true)) {
            $siteId = $siteIds[0];
        }

        $currentSite = $this->siteRepo->findById($siteId);
        $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        $templates = $this->bannerRepo->getAllBannerTemplates();
        $siteLanguages = $this->languageRepo->getSiteLanguages($siteId);

        // Parse JSON settings for the first/active banner
        $bannerSettings = [];
        $siteBannerId = 0;
        $templateId = 0;
        if (!empty($banners)) {
            $banner = $banners[0];
            $siteBannerId = (int) $banner['id'];
            $templateId = (int) ($banner['banner_template_id'] ?? 0);
            $bannerSettings = [
                'id' => $siteBannerId,
                'template_id' => $templateId ?: null,
                'general' => $this->parseJson($banner['general_setting'] ?? ''),
                'layout' => $this->parseJson($banner['layout_setting'] ?? ''),
                'content' => $this->parseJson($banner['content_setting'] ?? ''),
                'colors' => $this->denormaliseColors($this->parseJson($banner['color_setting'] ?? '')),
                'custom_css' => $banner['custom_css'] ?? '',
                'layout_key' => $banner['layout_key'] ?? 'gdpr/classic',
                'custom_layout_id' => $banner['custom_layout_id'] ?? null,
                'updated_at' => $banner['updated_at'] ?? '',
            ];
        }

        // Determine banner type from site — prefer frameworks over legacy column
        $siteFrameworkIds = $this->frameworkRepo->getFrameworksForSite($siteId);
        $siteFrameworkNames = [];
        if ($siteFrameworkIds !== []) {
            foreach ($siteFrameworkIds as $fwId) {
                $fw = $this->frameworkService->getFramework($fwId);
                if ($fw !== null) {
                    $siteFrameworkNames[] = $fw['name'];
                }
            }
        }
        $bannerType = $this->deriveBannerType($siteFrameworkIds, (string) ($currentSite['banner_type'] ?? 'gdpr'));

        // Load field groups and all language values for inline content editing
        $fieldGroups = $templateId > 0
            ? $this->bannerRepo->getBannerFieldsGrouped($templateId)
            : [];

        $defaultLang = $this->languageRepo->getDefaultLanguage($siteId);
        $defaultLangId = $defaultLang !== null ? (int) $defaultLang['lang_id'] : 0;

        // Pre-load field values for every site language (instant tab switching)
        $allLangValues = [];
        foreach ($siteLanguages as $lang) {
            $langId = (int) $lang['id'];
            $values = $this->bannerRepo->getSiteBannerFieldValues($siteBannerId, $langId);
            if ($values === [] && $templateId > 0) {
                $values = $this->bannerRepo->getDefaultFieldValues($templateId, $langId);
            }
            $allLangValues[$langId] = $values;
        }

        // Parse disable_on_pages JSON into newline-separated text for textarea
        $disableOnPagesText = '';
        $disableOnPagesRaw = (string) ($currentSite['disable_on_pages'] ?? '');
        if ($disableOnPagesRaw !== '') {
            $pages = json_decode($disableOnPagesRaw, true);
            if (\is_array($pages)) {
                $disableOnPagesText = implode("\n", array_filter($pages));
            }
        }

        // Parse allowed_scripts JSON into newline-separated text for textarea
        $allowedScriptsText = '';
        $allowedScriptsRaw = (string) ($currentSite['allowed_scripts'] ?? '');
        if ($allowedScriptsRaw !== '') {
            $scripts = json_decode($allowedScriptsRaw, true);
            if (\is_array($scripts)) {
                $allowedScriptsText = implode("\n", array_filter($scripts));
            }
        }

        // Determine if user's plan allows removing branding
        $canRemoveBranding = true;
        if ($this->subscriptionService !== null) {
            $planKey = $this->subscriptionService->getPlanKey($userId);
            $canRemoveBranding = $planKey !== null && $this->pricingService->hasFeature($planKey, 'custom_branding');
        }

        $html = $this->twig->render('pages/banners/index.html.twig', [
            'title' => 'Banner Settings',
            'user' => $user,
            'sites' => $sites,
            'currentSite' => $currentSite,
            'siteId' => $siteId,
            'banners' => $banners,
            'bannerSettings' => $bannerSettings,
            'bannerType' => $bannerType,
            'siteFrameworkNames' => $siteFrameworkNames,
            'templates' => $templates,
            'siteLanguages' => $siteLanguages,
            'siteBannerId' => $siteBannerId,
            'fieldGroups' => $fieldGroups,
            'defaultLangId' => $defaultLangId,
            'allLangValues' => $allLangValues,
            'disableOnPagesText' => $disableOnPagesText,
            'allowedScriptsText' => $allowedScriptsText,
            'websiteKey' => (string) ($currentSite['website_key'] ?? ''),
            'customLayouts' => $this->layoutService->getCustomLayouts($siteId),
            'systemLayouts' => $this->layoutService->getSystemLayouts('gdpr'),
            'canRemoveBranding' => $canRemoveBranding,
        ]);

        return ApiResponse::html($html);
    }

    private function parseJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * Convert normalised color format (section → theme → element → field) back to
     * the flat format the UI expects (element → theme → field).
     *
     * Handles both formats: if already flat, passes through unchanged.
     * Preserves metadata keys like _activeTheme.
     *
     * @param array<string, mixed> $colors
     * @return array<string, mixed>
     */
    private function denormaliseColors(array $colors): array
    {
        // Known section names used by normaliseColorSetting in ScriptGenerationService
        $sectionNames = [
            'cookie_notice',
            'preference_center',
            'revisit_consent_button',
            'alttext_blocked_content',
            'opt_out_center',
        ];

        // Reverse rename map: normalised element name → flat element name
        $reverseRename = [
            'alttext_blocked_content' => ['button' => 'alttext_button'],
        ];

        $hasNormalisedKeys = false;
        foreach ($sectionNames as $section) {
            if (isset($colors[$section])) {
                $hasNormalisedKeys = true;
                break;
            }
        }

        if (!$hasNormalisedKeys) {
            return $colors; // Already flat format
        }

        $flat = [];

        foreach ($colors as $key => $value) {
            if (!\is_array($value) || !\in_array($key, $sectionNames, true)) {
                // Preserve metadata (_activeTheme) and already-flat element keys
                if (\is_array($value)) {
                    $flat[$key] = $value;
                } else {
                    $flat[$key] = $value;
                }
                continue;
            }

            // $key is a section name, $value is [theme => [element => [field => color]]]
            $renameMap = $reverseRename[$key] ?? [];
            foreach ($value as $theme => $elements) {
                if (!\is_array($elements)) {
                    continue;
                }
                foreach ($elements as $element => $fields) {
                    if (!\is_array($fields)) {
                        continue;
                    }
                    $flatElement = $renameMap[$element] ?? $element;
                    if (!isset($flat[$flatElement])) {
                        $flat[$flatElement] = [];
                    }
                    $flat[$flatElement][$theme] = $fields;
                }
            }
        }

        return $flat;
    }

    /**
     * Derive display_banner_type from selected frameworks for backward compat.
     *
     * @param list<string> $frameworkIds
     */
    private function deriveBannerType(array $frameworkIds, string $fallback): string
    {
        if ($frameworkIds === []) {
            return $fallback;
        }

        $hasGdpr = \in_array('gdpr', $frameworkIds, true) || \in_array('eprivacy_directive', $frameworkIds, true);
        $hasCcpa = \in_array('ccpa_cpra', $frameworkIds, true);

        if ($hasGdpr && $hasCcpa) {
            return 'gdpr_ccpa';
        }
        if ($hasCcpa) {
            return 'ccpa';
        }

        return 'gdpr';
    }
}
