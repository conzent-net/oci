<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\BannerColors;
use OCI\Banner\Service\BannerContent;
use OCI\Banner\Service\BannerImageUrl;
use OCI\Banner\Service\BrandingPolicy;
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
        private readonly BrandingPolicy $brandingPolicy,
        private readonly ?PricingService $pricingService = null,
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

        // A site with two laws has one banner row per law, each generating its
        // own config. The page edits the row asked for, defaulting to the
        // first (GDPR before CCPA, as the repository orders them).
        $bannerRows = [];
        foreach ($banners as $row) {
            $bannerRows[] = [
                'id' => (int) $row['id'],
                'law' => self::rowLaw($row),
                'name' => (string) ($row['template_name'] ?? ''),
            ];
        }

        $requestedBannerId = (int) ($queryParams['banner_id'] ?? 0);
        $selectedIndex = 0;
        foreach ($banners as $index => $row) {
            if ((int) $row['id'] === $requestedBannerId) {
                $selectedIndex = $index;
                break;
            }
        }

        // Parse JSON settings for the selected banner
        $bannerSettings = [];
        $siteBannerId = 0;
        $templateId = 0;
        if (!empty($banners)) {
            $banner = $banners[$selectedIndex];
            $siteBannerId = (int) $banner['id'];
            $templateId = (int) ($banner['banner_template_id'] ?? 0);
            $bannerSettings = [
                'id' => $siteBannerId,
                'template_id' => $templateId ?: null,
                'general' => $this->parseJson($banner['general_setting'] ?? ''),
                'layout' => $this->parseJson($banner['layout_setting'] ?? ''),
                // Flattened below, once the site's law is known.
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

        // Which sections the page shows follows the banner being edited, not
        // the site: on a two-law site the GDPR row has no opt-out centre and
        // the CCPA row has no preference centre. With one row the site's own
        // type still decides, so a gdpr_ccpa site on a single banner keeps
        // every section it has today.
        $selectedLaw = $bannerRows !== [] ? $bannerRows[$selectedIndex]['law'] : $bannerType;
        if (\count($bannerRows) > 1) {
            $bannerType = $selectedLaw;
        }

        // The page edits content flat; a banner imported from v1 stores it
        // nested. Without this its toggles all read as off and the next save
        // switched them off on the live banner.
        if ($bannerSettings !== []) {
            $bannerSettings['content'] = $this->withImageLinks(
                BannerContent::flatten($bannerSettings['content'], $selectedLaw === 'ccpa' ? 'ccpa' : 'gdpr'),
            );
        }

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

        // Whether the branding toggle is offered at all. Same predicate as the
        // update handler and script generation, so the control a customer sees
        // and the banner they actually get cannot disagree — an agency-managed
        // site showing a disabled toggle while its script already drops the
        // branding would be a support ticket with no obvious cause.
        $canRemoveBranding = $this->brandingPolicy->canRemoveBranding($userId);

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
            // One row per law when a site has more than one; the page edits
            // the selected one, or every one at once.
            'bannerRows' => $bannerRows,
            'selectedBannerId' => $siteBannerId,
            // What the generator fills an unsaved color with, so the color tab
            // shows the banner as it will actually render.
            'colorDefaults' => BannerColors::pageDefaults(),
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
     * The law a banner row is for. `cookie_laws` is a plain string on the
     * seeded templates and JSON ({"gdpr":1,…}) on legacy-imported ones, the
     * same two shapes ScriptGenerationService::normalizeCookieLaws() reads.
     *
     * @param array<string, mixed> $row
     */
    private static function rowLaw(array $row): string
    {
        $raw = (string) ($row['consent_type'] ?? '');
        if (\in_array($raw, ['gdpr', 'ccpa', 'gdpr_ccpa'], true)) {
            return $raw;
        }

        $decoded = json_decode($raw, true);
        if (\is_array($decoded)) {
            $hasGdpr = !empty($decoded['gdpr']);
            $hasCcpa = !empty($decoded['ccpa']);
            if ($hasGdpr && $hasCcpa) {
                return 'gdpr_ccpa';
            }
            if ($hasCcpa) {
                return 'ccpa';
            }
        }

        return 'gdpr';
    }

    /**
     * Stored colors in the flat shape the banner page edits. The mapping is
     * BannerColors', shared with the generator.
     *
     * @param array<string, mixed> $colors
     * @return array<string, mixed>
     */
    private function denormaliseColors(array $colors): array
    {
        return BannerColors::flatten($colors);
    }

    /**
     * The logo and revisit icon links as flat content keys, which is how the
     * page edits and saves them. An imported banner keeps them nested, and
     * the page saves content flat, so without this the first save of such a
     * banner dropped its logo.
     *
     * @param array<string, mixed> $content
     * @return array<string, mixed>
     */
    private function withImageLinks(array $content): array
    {
        $content[BannerImageUrl::LOGO] = BannerImageUrl::fromContent($content, BannerImageUrl::LOGO);
        $content[BannerImageUrl::REVISIT_ICON] = BannerImageUrl::fromContent($content, BannerImageUrl::REVISIT_ICON);

        return $content;
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
