<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\LayoutService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
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
                'colors' => $this->parseJson($banner['color_setting'] ?? ''),
                'custom_css' => $banner['custom_css'] ?? '',
                'layout_key' => $banner['layout_key'] ?? 'gdpr/classic',
                'custom_layout_id' => $banner['custom_layout_id'] ?? null,
                'updated_at' => $banner['updated_at'] ?? '',
            ];
        }

        // Determine banner type from site
        $bannerType = (string) ($currentSite['banner_type'] ?? 'gdpr');

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

        $html = $this->twig->render('pages/banners/index.html.twig', [
            'title' => 'Banner Settings',
            'user' => $user,
            'sites' => $sites,
            'currentSite' => $currentSite,
            'siteId' => $siteId,
            'banners' => $banners,
            'bannerSettings' => $bannerSettings,
            'bannerType' => $bannerType,
            'templates' => $templates,
            'siteLanguages' => $siteLanguages,
            'siteBannerId' => $siteBannerId,
            'fieldGroups' => $fieldGroups,
            'defaultLangId' => $defaultLangId,
            'allLangValues' => $allLangValues,
            'disableOnPagesText' => $disableOnPagesText,
            'websiteKey' => (string) ($currentSite['website_key'] ?? ''),
            'customLayouts' => $this->layoutService->getCustomLayouts($siteId),
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
}
