<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Doctrine\DBAL\Connection;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Site\Repository\PageviewRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates the per-site consent script (script.js).
 *
 * This is a faithful port of legacy ProcessSite::generate().
 * The generated output MUST be byte-identical to the legacy system.
 *
 * Pipeline:
 *   1. Load site info, plan, languages, banners
 *   2. For each banner type (gdpr/ccpa): build config JSON + HTML template
 *   3. Load JS template, replace ~33 placeholders
 *   4. Minify with MatthiasMullie\Minify
 *   5. Write to public/sites_data/{website_key}/script.js
 */
final class ScriptGenerationService
{
    private const CMP_ID = 446;
    private const CMP_VERSION = 3;

    private string $basePath;
    private string $resourcePath;
    private string $outputPath;
    private string $webRoot;

    public function __construct(
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
        private readonly CachePurgeService $cachePurge,
        private readonly LayoutService $layoutService,
        private readonly PrivacyFrameworkService $frameworkService,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
        private readonly ?PageviewRepositoryInterface $pageviewRepo = null,
    ) {
        $this->basePath = \dirname(__DIR__, 3);
        $this->resourcePath = $this->basePath . '/resources/consent';
        $this->outputPath = $this->basePath . '/public/sites_data';
        $this->webRoot = rtrim($_ENV['APP_URL'] ?? 'http://localhost:8098', '/') . '/';
    }

    /**
     * Delete the generated script files for a site and purge caches.
     */
    public function deleteScriptFiles(string $websiteKey, string $domain = ''): void
    {
        $dir = $this->outputPath . '/' . $websiteKey;

        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
            @rmdir($dir);
        }

        $this->cachePurge->purgeForSite($websiteKey, $domain);
        $this->logger->info('Script files deleted for site', ['website_key' => $websiteKey]);
    }

    /**
     * Generate the consent script for a site.
     */
    public function generate(int $siteId): bool
    {
        try {
            $site = $this->loadSite($siteId);
            if ($site === null) {
                return false;
            }

            $websiteKey = (string) $site['website_key'];
            $this->ensureOutputDir($websiteKey);
            $scriptPath = $this->outputPath . '/' . $websiteKey . '/script.js';

            // If site is not active (status != 1 in legacy, != 'active' in OCI), write empty script
            if ($this->isSiteDisabled($site)) {
                @file_put_contents($scriptPath, '');
                return true;
            }

            $userId = (int) $site['user_id'];

            // ── Plan & Feature checks ──────────────────────────
            $planData = $this->loadPlanData($userId);
            $planFeatures = $planData['features'];
            $isPaidPlan = $planData['is_paid'];
            $isEnterprise = $planData['is_enterprise'];

            // Check pageview limits — write exceeded flag to version.json
            if (!$isEnterprise && $planData['exceeded']) {
                @file_put_contents($scriptPath, '');
                $versionPath = $this->outputPath . '/' . $websiteKey . '/version.json';
                $versionData = ['v' => '', 't' => time(), 'x' => true];
                @file_put_contents($versionPath, json_encode($versionData));
                return true;
            }

            // ── Site metadata ──────────────────────────────────
            $siteDomain = (string) $site['domain'];
            // Strip protocol (safety net for legacy data) and port for JS domain matching
            $siteDomain = (string) preg_replace('#^https?://#i', '', $siteDomain);
            $siteDomain = explode('/', $siteDomain)[0]; // strip path
            $siteDomainHostname = preg_replace('#:\d+$#', '', $siteDomain);
            $debugMode = $site['debug_mode'] ?? 0;
            $blockIframe = $site['block_iframe'] ?? 0;
            $allowCrossDomain = $site['cross_domain_enabled'] ?? ($site['allow_cross_domain'] ?? 0);
            $defaultPrivacyPolicyUrl = $this->normalizeUrl($site['privacy_policy_url'] ?? ($site['privacy_policy'] ?? ''));
            $consentSharing = (int) ($site['consent_sharing_enabled'] ?? ($site['consent_sharing'] ?? 0));
            $rootDomain = $consentSharing === 1 ? $siteDomainHostname : '';
            $bannerDisplay = $this->resolveBannerDisplay($siteId, (string) ($site['display_banner_type'] ?? ($site['display_banner'] ?? 'gdpr')));
            $supportGcm = $site['gcm_enabled'] ?? ($site['support_gcm'] ?? 0);
            $supportMetaConsent = $site['meta_consent_enabled'] ?? 1;
            $supportUet = $site['uet_enabled'] ?? 1;
            $supportClarity = $site['clarity_enabled'] ?? 0;
            $supportAmazonConsent = $site['amazon_consent_enabled'] ?? 0;
            $gtmContainerId = trim((string) ($site['gtm_container_id'] ?? ''));
            $gtmDataLayer = trim((string) ($site['gtm_data_layer'] ?? '')) ?: 'dataLayer';
            $allowTagFire = $site['tag_fire_enabled'] ?? ($site['allow_tag_fire'] ?? 0);
            $renewConsent = !empty($site['renew_user_consent_at'])
                ? strtotime((string) $site['renew_user_consent_at'])
                : (!empty($site['renew_user_consent'])
                    ? strtotime((string) $site['renew_user_consent'])
                    : strtotime((string) ($site['created_at'] ?? $site['created_date'] ?? 'now')));
            $siteLogo = $site['site_logo'] ?? '';
            $iconLogo = $site['icon_logo'] ?? '';
            $includeAllLang = (int) ($site['include_all_languages'] ?? ($site['include_all_lang'] ?? 0));
            $bannerDelay = (int) ($site['banner_delay_ms'] ?? ($site['banner_delay'] ?? 100));
            if ($bannerDelay === 0) {
                $bannerDelay = 100;
            }

            // ── Publisher country ──────────────────────────────
            $publisherCountry = $this->loadPublisherCountry($userId);

            // ── Languages ──────────────────────────────────────
            $defaultLang = $this->loadDefaultSiteLang($siteId);
            $defaultLangCode = $defaultLang['lang_code'] ?? 'en';
            $defaultLangId = (int) ($defaultLang['lang_id'] ?? 1);

            $defaultMainLang = $this->loadDefaultUserLang($userId);
            $defaultMainCode = $defaultMainLang['lang_code'] ?? $defaultLangCode;
            $defaultMainId = (int) ($defaultMainLang['id'] ?? $defaultLangId);

            $allLangs = $this->loadAllLanguages($siteId, $planData['max_lang'] ?? 0, $includeAllLang, $planFeatures);

            // ── Block providers ────────────────────────────────
            $providerBlockLists = $this->loadBlockProviders($siteId, $userId);

            // ── Allowed domains ────────────────────────────────
            $allowedDomains = [$siteDomainHostname];
            $policyList = [$this->getDomainOnly($siteDomain) => $defaultPrivacyPolicyUrl];
            $associatedDomains = $this->loadAssociatedDomains($siteId, $userId);
            foreach ($associatedDomains as $ad) {
                $allowedDomains[] = preg_replace('#:\d+$#', '', $ad['domain']);
                $policyList[$this->getDomainOnly($ad['domain'])] = $this->normalizeUrl($ad['privacy_policy'] ?? '');
            }

            // ── Load banners ───────────────────────────────────
            $bannerList = $this->loadBanners($siteId, $bannerDisplay);

            $directSetting = json_encode([
                'allowed_categories' => ['necessary'],
                'allowed_scripts' => [],
                'allowed_cookies' => [],
                'cookieTypes' => [],
                'beaconsList' => [],
                'cookiesList' => [],
                'shortCodes' => new \stdClass(),
                'allowedVendors' => [],
                'allowedGoogleVendors' => [],
                'policy_list' => new \stdClass(),
                'themeSettings' => new \stdClass(),
                'css_content' => '',
                'html' => '',
                'cookie_audit_table' => '',
                'cookie_policy_html' => '',
                'privacy_policy_html' => '',
                'banner_type' => 'popup',
                'banner_position' => '-center',
                'geo_target_selected' => [],
            ], JSON_THROW_ON_ERROR);
            $directCcpaSetting = '[]';
            $geoTarget = '';
            $isIab = 0;
            $siteCookies = [];
            $cookiesCategory = [];
            $cookiesCategoryList = [];
            $preferedLangs = [];
            $placeholderText = [];
            $necessaryCookies = [];
            $siteinfo = $site;

            // Clean siteinfo (remove sensitive/internal fields)
            unset($siteinfo['created_by'], $siteinfo['created_date'], $siteinfo['modified_date'], $siteinfo['website_key']);
            if (isset($siteinfo['created_at'])) {
                unset($siteinfo['created_at']);
            }
            if (isset($siteinfo['updated_at'])) {
                unset($siteinfo['updated_at']);
            }
            if (isset($siteinfo['deleted_at'])) {
                unset($siteinfo['deleted_at']);
            }
            // Convert string status to numeric for legacy script compatibility
            // Legacy: 1=active, 0=disabled; OCI: 'active', 'disabled', 'suspended', 'deleted'
            if (isset($siteinfo['status']) && !\is_int($siteinfo['status'])) {
                $siteinfo['status'] = $siteinfo['status'] === 'active' ? 1 : 0;
            }

            $disableOnPages = $siteinfo['disable_on_pages'] ?? '';
            if ($disableOnPages !== '' && $disableOnPages !== null) {
                $siteinfo['disable_on_pages'] = json_decode((string) $disableOnPages, true) ?: [];
            } else {
                $siteinfo['disable_on_pages'] = [];
            }

            // ── Banner categories (all categories) ─────────────
            $allCats = $this->loadBannerCategories();

            foreach ($bannerList as $bannerinfo) {
                if (!$bannerinfo) {
                    continue;
                }

                $langList = [];
                $beacons = [];
                $cookies = [];
                $trans = [];
                $catItems = [];
                $cookieTypes = [];
                $translations = [];
                $siteLanguages = [];

                // Build language list
                foreach ($allLangs as $val) {
                    $langList[(int) $val['id']] = $val['lang_code'];
                    $siteLanguages[] = $val['lang_code'];
                }
                // Ensure at least the default language is present
                if ($langList === []) {
                    $langList[$defaultLangId] = $defaultLangCode;
                    $siteLanguages[] = $defaultLangCode;
                }
                // Ensure default language is first so fallback lookups work
                $preferedLangs = $langList;
                if (isset($preferedLangs[$defaultLangId])) {
                    $preferedLangs = [$defaultLangId => $preferedLangs[$defaultLangId]] + $preferedLangs;
                }

                // ── Decode banner settings ─────────────────────
                $cookieLaws = $bannerinfo['consent_type'] ?? 'gdpr';
                $userBannerId = (int) ($bannerinfo['id'] ?? 0);
                $defaultBannerId = (int) ($bannerinfo['banner_id'] ?? $bannerinfo['template_id'] ?? 0);

                // Color config from default template
                $consentColorConfig = $this->loadConsentBannerSetting($defaultBannerId, 'color_setting');
                $consentColorConfig['option_value'] = json_decode($consentColorConfig['option_value'] ?? '{}', true) ?: [];

                // OCI stores settings in separate columns; legacy uses single option_value JSON
                if (isset($bannerinfo['option_value']) && \is_string($bannerinfo['option_value']) && $bannerinfo['option_value'] !== '') {
                    $optionsVal = json_decode($bannerinfo['option_value'], true) ?: [];
                } else {
                    // Reconstruct from OCI separate columns
                    $colorData = \is_string($bannerinfo['color_setting'] ?? null) ? (json_decode($bannerinfo['color_setting'], true) ?: []) : ($bannerinfo['color_setting'] ?? []);
                    $activeTheme = $colorData['_activeTheme'] ?? null;
                    $optionsVal = [
                        'general' => \is_string($bannerinfo['general_setting'] ?? null) ? (json_decode($bannerinfo['general_setting'], true) ?: []) : ($bannerinfo['general_setting'] ?? []),
                        'layout' => \is_string($bannerinfo['layout_setting'] ?? null) ? (json_decode($bannerinfo['layout_setting'], true) ?: []) : ($bannerinfo['layout_setting'] ?? []),
                        'content' => \is_string($bannerinfo['content_setting'] ?? null) ? (json_decode($bannerinfo['content_setting'], true) ?: []) : ($bannerinfo['content_setting'] ?? []),
                        'colors' => $colorData,
                        'custom_css' => $bannerinfo['custom_css'] ?? '',
                        'color_theme' => $activeTheme ?? $bannerinfo['color_theme'] ?? 'dark',
                        'base_theme' => $activeTheme ?? $bannerinfo['base_theme'] ?? 'dark',
                    ];
                }

                $catNames = [];
                $cookieTypesList = [];
                $cookieDesc = [];
                $cookieDuration = [];
                $catSlugs = [];
                $showRespectGpc = 0;
                $googlePrivacyPolicy = '';
                $globalPrivacyPolicy = '';
                $showMoreText = '';
                $showLessText = '';
                $doNotSellCheckbox = '';
                $categoryOnFirstLayer = 0;

                // Build category slug map
                foreach ($allCats as $catItem) {
                    $catSlugs[(int) $catItem['id']] = $catItem['slug'];
                }

                // ── Load cookie categories per language ────────
                foreach ($preferedLangs as $langIdKey => $langCode) {
                    $userCatList = $this->loadUserCookieCategories($userId, $siteId, $langIdKey);
                    $catListArr = [];
                    foreach ($userCatList as $ccVal) {
                        $catListArr[$ccVal['slug']][$langCode] = $ccVal;
                    }

                    $defaultCats = $this->loadDefaultCookieCategories($langIdKey);
                    foreach ($defaultCats as $valItem) {
                        $val = $catListArr[$valItem['slug']][$langCode] ?? $valItem;

                        $cookieTypesList[$val['slug']]['type'][$langCode] = $val['name'];
                        $cookieTypesList[$val['slug']]['name'][$langCode] = $val['name'];
                        $cookieTypesList[$val['slug']]['value'] = $val['slug'];
                        $cookieTypesList[$val['slug']]['description'][$langCode] = $val['description'];
                        $translations['conzent_preference_' . $val['slug'] . '_title'][$langCode] = $val['name'];
                        $translations['conzent_preference_' . $val['slug'] . '_description'][$langCode] = $val['description'];

                        $catItems[(int) $val['id']] = [
                            'slug' => $val['slug'],
                            'website_id' => $siteId,
                            'default_consent' => $val['default_consent'] ?? 0,
                        ];
                        $catNames[(int) $val['id']]['name'][$langCode] = $val['name'];
                        $catNames[(int) $val['id']]['description'][$langCode] = $val['description'];
                    }
                }

                // Merge names/descriptions into catItems
                foreach ($catItems as $ckey => $cval) {
                    if (isset($catNames[$ckey])) {
                        $catItems[$ckey]['name'] = $catNames[$ckey]['name'];
                        $catItems[$ckey]['description'] = $catNames[$ckey]['description'];
                    }
                }

                // Convert cookieTypes to indexed array
                foreach ($cookieTypesList as $cval) {
                    $cookieTypes[] = $cval;
                }

                // ── Load banner content/translations per language
                $cookiesArr = [];
                $iabTranslations = [];
                $cookieDescription = [];

                foreach ($preferedLangs as $langIdKey => $langCode) {
                    $contentsRaw = $this->loadUserBannerContent($siteId, $langIdKey, $cookieLaws);
                    $defaultContent = $this->loadDefaultBannerContent($langIdKey, $cookieLaws);
                    if (empty($defaultContent)) {
                        $defaultContent = $this->loadDefaultBannerContent($defaultMainId, $cookieLaws);
                    }

                    // Index user overrides by field_name for reliable lookup
                    $contentsByField = [];
                    foreach ($contentsRaw as $row) {
                        $fn = $row['field_name'] ?? '';
                        if ($fn !== '') {
                            $contentsByField[$fn] = $row;
                        }
                    }

                    foreach ($defaultContent as $key => $val) {
                        $langItem = $val;
                        // Use banner field category_key (cookie_notice, preference_center, etc.)
                        // NOT cookie category slugs — these are different taxonomies
                        $fieldCategoryKey = $langItem['category_key'] ?? '';
                        $fieldName = $langItem['field_name'] ?? '';
                        $fieldValue = $langItem['trans_value'] ?? $langItem['default_value'] ?? '';

                        if (isset($contentsByField[$fieldName])) {
                            $userValue = $contentsByField[$fieldName]['u_field_value'] ?? '';
                            if ($userValue !== '') {
                                $fieldValue = $userValue;
                            }
                        }

                        // For critical UI fields (buttons, labels), fall back to the
                        // default language translation if the current language is empty.
                        if ($fieldValue === '' && $langCode !== $defaultLangCode) {
                            $translationKey = str_starts_with($fieldName, 'iab_')
                                ? 'conzent_' . $fieldName
                                : 'conzent_' . $fieldCategoryKey . '_' . $fieldName;
                            $fieldValue = $translations[$translationKey][$defaultLangCode]
                                ?? $translations[$translationKey][$defaultMainCode]
                                ?? '';
                        }

                        if ($fieldName === 'cookie_policy_url') {
                            if ($fieldValue === '') {
                                $fieldValue = $defaultPrivacyPolicyUrl;
                            } else {
                                $fieldValue = $this->normalizeUrl($fieldValue);
                            }
                            $policyList[$this->getDomainOnly($siteDomain)] = $fieldValue;
                        }
                        if ($fieldName === 'google_privacy_url') {
                            if ($fieldValue === '') {
                                $fieldValue = 'https://business.safety.google/privacy';
                            }
                        }
                        if ($fieldName === 'alt_text_blocked_content') {
                            $placeholderText[$langCode] = $fieldValue;
                        }
                        if (str_starts_with($fieldName, 'iab_')) {
                            $iabTranslations['conzent_' . $fieldName][$langCode] = $fieldValue;
                        } else {
                            $translations['conzent_' . $fieldCategoryKey . '_' . $fieldName][$langCode] = $fieldValue;
                        }
                    }

                    // Load cookies
                    $items = $this->loadSiteCookies($userId, $siteId, $langIdKey);
                    if (empty($items) && $defaultLangId !== $langIdKey) {
                        $items = $this->loadSiteCookies($userId, $siteId, $defaultLangId);
                    }

                    foreach ($items as $rows) {
                        $catName = $catItems[(int) ($rows['category_id'] ?? 0)]['slug'] ?? '';
                        $expiryDate = $rows['duration'] ?? '';
                        $cookieName = $rows['cookie_name'] ?? $rows['name'] ?? '';

                        $siteCookies[$cookieName] = [
                            'name' => $cookieName,
                            'domain' => $rows['cookie_domain'] ?? $rows['domain'] ?? '',
                            'category' => $catName,
                        ];
                        $cookiesArr[$cookieName] = [
                            'name' => $cookieName,
                            'category' => $catName,
                            'domain' => $rows['cookie_domain'] ?? $rows['domain'] ?? '',
                            'description' => $rows['description'] ?? '',
                            'expire' => $expiryDate,
                        ];
                        $cookieDescription[$cookieName]['description'][$langCode] = $rows['description'] ?? '';
                        $cookieDuration[$cookieName]['duration'][$langCode] = $expiryDate;

                        if ($catName === 'necessary' || $catName === 'functional') {
                            if (!\in_array($cookieName, $necessaryCookies, true)) {
                                $necessaryCookies[] = $cookieName;
                            }
                        }
                    }
                }

                // Merge multilingual descriptions/durations into cookies
                foreach ($cookiesArr as $ckey => $cval) {
                    $cookiesArr[$ckey]['duration'] = $cookieDuration[$ckey]['duration'] ?? [];
                    $cookiesArr[$ckey]['description'] = $cookieDescription[$ckey]['description'] ?? [];
                    $cookies[$cval['category']][] = $cookiesArr[$ckey];
                    $cookiesCategoryList[] = $cval['category'];
                }

                // Load beacons
                $beaconsArr = $this->loadBeacons($siteId);
                foreach ($beaconsArr as $rows) {
                    if (isset($catItems[(int) ($rows['category_id'] ?? 0)])) {
                        $beacons[] = [
                            'category' => $catItems[(int) $rows['category_id']]['slug'],
                            'url' => $rows['url'],
                        ];
                    }
                }

                // ── Build banner config (extracted for A/B variant reuse) ──
                $configCtx = [
                    'cookieLaws' => $cookieLaws,
                    'consentColorConfig' => $consentColorConfig,
                    'planFeatures' => $planFeatures,
                    'siteLogo' => $siteLogo,
                    'iconLogo' => $iconLogo,
                    'siteDomain' => $siteDomain,
                    'cookieTypes' => $cookieTypes,
                    'translations' => $translations,
                    'iabTranslations' => $iabTranslations,
                    'cookies' => $cookies,
                    'beacons' => $beacons,
                    'necessaryCookies' => $necessaryCookies,
                    'allowTagFire' => (int) $allowTagFire,
                    'supportGcm' => (int) $supportGcm,
                    'supportMetaConsent' => (int) $supportMetaConsent,
                    'supportUet' => (int) $supportUet,
                    'supportClarity' => (int) $supportClarity,
                    'supportAmazonConsent' => (int) $supportAmazonConsent,
                    'gtmContainerId' => $gtmContainerId,
                    'gtmDataLayer' => $gtmDataLayer,
                    'renewConsent' => $renewConsent,
                    'bannerDelay' => $bannerDelay,
                    'websiteKey' => $websiteKey,
                    'policyList' => $policyList,
                    'rootDomain' => $rootDomain,
                    'siteId' => $siteId,
                    'userBannerId' => $userBannerId,
                ];

                $bannerResult = $this->buildBannerConfig($optionsVal, $bannerinfo, $configCtx);
                $configArray = $bannerResult['configArray'];
                $geoTarget = $bannerResult['geoTarget'];
                $isIab = max($isIab, $bannerResult['isIab']);

                // Save context for A/B variant building (first banner pass wins)
                if (!isset($abConfigCtx)) {
                    $abConfigCtx = $configCtx;
                    $abControlOptionsVal = $optionsVal;
                    $abControlConfig = $configArray;
                    $abControlBannerRow = $bannerinfo;
                }

                // Write config JSON
                $configJsonPath = $this->outputPath . '/' . $websiteKey . '/' . $cookieLaws . '_config.json';
                $scriptContent = json_encode($configArray, JSON_THROW_ON_ERROR);
                $scriptContent = str_replace('[WEBSITE_KEY]', $websiteKey, $scriptContent);
                $scriptContent = str_replace('[API_PATH]', $this->webRoot . 'api/v1', $scriptContent);
                $scriptContent = str_replace('[WEB_PATH]', $this->webRoot, $scriptContent);

                if ($bannerDisplay !== 'gdpr_ccpa') {
                    $directSetting = $scriptContent;
                } else {
                    if ($cookieLaws === 'ccpa') {
                        $directCcpaSetting = $scriptContent;
                    }
                    if ($cookieLaws === 'gdpr') {
                        $directSetting = $scriptContent;
                    }
                    @file_put_contents($configJsonPath, $scriptContent);
                    $geoTarget = '';
                }
            }
            // ── End banner loop ────────────────────────────────

            // ── IAB2 support ───────────────────────────────────
            $IAB2_STUB = '';
            $IAB2_SCRIPT = '';
            $IAB2LOADTCF = '';
            $SAVE_TCF = '';
            $APPEND_FIELD_TCF = '';
            $UPDATE_TCF = '';
            $Load_elements = '';
            $iab_replace_tags = '';
            $iab_replace_count = '';

            if ($isIab === 1) {
                $IAB2_STUB = (string) file_get_contents($this->resourcePath . '/js/stub.js');
                $IAB2_SCRIPT = (string) file_get_contents($this->resourcePath . '/js/iab-script.js');
                $IAB2LOADTCF = "\nif(CNZ_config.settings.iab_support == 1){\n\tloadIabtcf();\n}\n";
                $SAVE_TCF = "\nif(CNZ_config.settings.iab_support == 1){\n\tsaveTcf(b_action,law_type);\n}";
                $APPEND_FIELD_TCF = "\nif(CNZ_config.settings.iab_support == 1){\n\tparams_form.append(\"tcf_data\", cz._cnzStore._tcStringValue);\n\tparams_form.append(\"gacm_data\", cz._addtlConsent);\n}";
                $UPDATE_TCF = "\nif(CNZ_config.settings.iab_support == 1){\n\tupdateTcf(b_action);\n}";
                $Load_elements = "\nif(CNZ_config.settings.iab_support == 1){\n\tloadIabelements();\n}";

                $iab_replace_tags = "if(CNZ_config.settings.iab_support == 1){\n"
                    . "\t\t\t\t\t\t\t\t\tvar iab_tabs = createPnfTabs(),iab_vendors = createVendorTabs();\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('{{count}}',total_vendors);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('{vendor_count}',total_vendors);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_PURPOSES]',iab_tabs['purposes']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_PURPOSES_COUNT]',iab_tabs['purposes_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_PURPOSES_TOGGLE]',iab_tabs['purposes_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_PURPOSES]',iab_tabs['special_purposes']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_PURPOSES_COUNT]',iab_tabs['special_purposes_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_PURPOSES_TOGGLE]',iab_tabs['special_purposes_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_FEATURES]',iab_tabs['features']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_FEATURES_COUNT]',iab_tabs['features_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_FEATURES_TOGGLE]',iab_tabs['features_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_FEATURES]',iab_tabs['special_features']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_FEATURES_COUNT]',iab_tabs['special_features_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_SPECIAL_FEATURES_TOGGLE]',iab_tabs['special_features_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_THIRDPARTY]',iab_vendors['third_party']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_THIRDPARTY_COUNT]',iab_vendors['third_party_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_THIRDPARTY_TOGGLE]',iab_vendors['third_party_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_GOOGLE_AD]',iab_vendors['google_ad']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_GOOGLE_AD_COUNT]',iab_vendors['google_ad_count']);\n"
                    . "\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('[IAB_VENDORS_GOOGLE_AD_TOGGLE]',iab_vendors['google_ad_toggle']);\n"
                    . "\t\t\t\t\t\t\t\t}";

                $iab_replace_count = "if(CNZ_config.settings.iab_support == 1){\n"
                    . "\t\t\t\t\t\t\t\t\t\tvar total_vendors = 0;\n"
                    . "\t\t\t\t\t\t\t\t\t\tif(cz._thirdPartyLists){ \n"
                    . "\t\t\t\t\t\t\t\t\t\t\ttotal_vendors = (cz._thirdPartyLists[0].sublist.length + cz._thirdPartyLists[1].sublist.length);\n"
                    . "\t\t\t\t\t\t\t\t\t\t}\n"
                    . "\t\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('{{count}}',total_vendors);\n"
                    . "\t\t\t\t\t\t\t\t\t\tcookieNotice = cookieNotice.replaceAll('{vendor_count}',total_vendors);\n"
                    . "\t\t\t\t\t\t\t\t\t}";
            }

            // ── Show ads ───────────────────────────────────────
            $showAds = 'showInfo();';
            /*if ($isPaidPlan) {
                $showAds = '';
            }*/

            // ── Main script template replacement ───────────────
            $mainScript = (string) file_get_contents($this->resourcePath . '/js/conzent.script.js');

            // IAB stub/script are already minified — use unique string tokens so we
            // can inject them AFTER minification (the minifier chokes on re-minifying
            // the 100 KB IAB bundle, producing truncated output).
            // Tokens are string literals that survive minification (comments get stripped).
            $iabStubToken = '"__IAB2_STUB_TOKEN__"';
            $iabScriptToken = '"__IAB2_SCRIPT_TOKEN__"';
            $mainScript = str_replace('[IAB2_STUB]', $IAB2_STUB !== '' ? $iabStubToken : '', $mainScript);
            $mainScript = str_replace('[IAB2_SCRIPT]', $IAB2_SCRIPT !== '' ? $iabScriptToken : '', $mainScript);
            $mainScript = str_replace('[IAB2LOADTCF]', $IAB2LOADTCF, $mainScript);
            $mainScript = str_replace('[SAVE_TCF]', $SAVE_TCF, $mainScript);
            $mainScript = str_replace('[UPDATE_TCF]', $UPDATE_TCF, $mainScript);
            $mainScript = str_replace('[APPEND_FIELD_TCF]', $APPEND_FIELD_TCF, $mainScript);
            $mainScript = str_replace('[IAB_LOADELEMENT]', $Load_elements, $mainScript);
            $mainScript = str_replace('[IAB_REPLACE_TAGS]', $iab_replace_tags, $mainScript);
            $mainScript = str_replace('[IAB_REPLACE_COUNT]', $iab_replace_count, $mainScript);

            // Advanced vs Basic Consent Mode: whitelist Google hosts only in Advanced mode
            // Token is a string literal to survive minification (brackets get stripped).
            $googleHostsWhitelist = (int) $allowTagFire === 1
                ? '["www.googletagmanager.com","googletagmanager.com","www.google-analytics.com","google-analytics.com","www.googleadservices.com","googleads.g.doubleclick.net","pagead2.googlesyndication.com"]'
                : '[]';
            $mainScript = str_replace('"__GOOGLE_HOSTS__"', $googleHostsWhitelist, $mainScript);

            // Core replacements
            $mainScript = str_replace('[CNCMPID]', (string) self::CMP_ID, $mainScript);
            $mainScript = str_replace('[CNCMPVERSION]', (string) self::CMP_VERSION, $mainScript);
            $mainScript = str_replace('[WEBSITE_KEY]', $websiteKey, $mainScript);
            $mainScript = str_replace('[DISPLAY_BANNER]', $bannerDisplay, $mainScript);
            $mainScript = str_replace('[SITE_DOMAIN]', $siteDomainHostname, $mainScript);
            $mainScript = str_replace('[API_PATH]', $this->webRoot . 'api/v1', $mainScript);
            $mainScript = str_replace('[WEB_PATH]', $this->webRoot, $mainScript);
            $mainScript = str_replace('[SETTINGS]', $directSetting, $mainScript);
            $mainScript = str_replace('[CCPA_SETTINGS]', $directCcpaSetting, $mainScript);
            $mainScript = str_replace('[DEBUG_MODE]', (string) $debugMode, $mainScript);
            $mainScript = str_replace('[BLOCK_IFRAMES]', (string) $blockIframe, $mainScript);
            $mainScript = str_replace('[CROSS_DOMAINS]', (string) $allowCrossDomain, $mainScript);
            $mainScript = str_replace('[PUBLISHER_COUNTRY]', $publisherCountry, $mainScript);
            $mainScript = str_replace('[USER_SITE]', json_encode($siteinfo, JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[PLACEHOLDER_TRANS]', json_encode($placeholderText, JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[DEFAULT_LANG]', $defaultLangCode, $mainScript);
            $mainScript = str_replace('[MAIN_LANG]', $defaultMainCode, $mainScript);
            $mainScript = str_replace('[PREFERED_LANGS]', json_encode($siteLanguages ?? array_values($preferedLangs), JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[PLACEHOLDER_TEXT]', '[conzent_alt_text_blocked_content]', $mainScript);
            $mainScript = str_replace('[SHOW_ADS]', $showAds, $mainScript);

            // Cookie categories and site cookies
            if (!empty($cookiesCategoryList)) {
                $uniqueCats = array_unique($cookiesCategoryList);
                foreach ($uniqueCats as $ckval) {
                    $cookiesCategory[] = ['slug' => $ckval];
                }
            }
            if (!empty($siteCookies)) {
                $siteCookiesArr = [];
                foreach ($siteCookies as $ckval) {
                    $siteCookiesArr[$ckval['category']][] = ['name' => $ckval['name'], 'domain' => $ckval['domain']];
                }
                $siteCookies = $siteCookiesArr;
            }

            $mainScript = str_replace('[COOKIES_CATEGORIES]', json_encode($cookiesCategory, JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[SITE_COOKIES_LIST]', json_encode($siteCookies, JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[PROVIDERS_BLOCKED]', json_encode($providerBlockLists, JSON_THROW_ON_ERROR), $mainScript);
            $mainScript = str_replace('[BLOCKED_COOKIE_PATTERNS]', json_encode($this->loadBlockedCookiePatterns(), JSON_THROW_ON_ERROR), $mainScript);

            // Privacy framework rules map (geo-aware behaviour)
            $frameworkRules = $this->buildFrameworkRulesMap($siteId);
            $mainScript = str_replace('[FRAMEWORK_RULES]', json_encode($frameworkRules, JSON_THROW_ON_ERROR), $mainScript);

            // Geo target
            if ($isIab === 1 && $geoTarget === 'all') {
                $mainScript = str_replace('[GEO_TARGET]', '', $mainScript);
            } else {
                $mainScript = str_replace('[GEO_TARGET]', $geoTarget, $mainScript);
            }

            $mainScript = str_replace('[ALLOWED_DOMAINS]', json_encode($allowedDomains, JSON_THROW_ON_ERROR), $mainScript);

            // Load location
            if ($geoTarget === 'all') {
                if (($bannerDisplay === 'gdpr_ccpa' || $isIab === 1) && $isIab) {
                    $mainScript = str_replace('[LOAD_LOCATION]', 'await _loadLocation();', $mainScript);
                } else {
                    $mainScript = str_replace('[LOAD_LOCATION]', '', $mainScript);
                }
            } else {
                $mainScript = str_replace('[LOAD_LOCATION]', 'await _loadLocation();', $mainScript);
            }

            // Load config / direct load / ready load
            if ($bannerDisplay === 'gdpr_ccpa' || $isIab === 1) {
                $scriptCode = '';
                if (!($bannerDisplay === 'gdpr' || $bannerDisplay === 'ccpa') || $isIab !== 1) {
                    $scriptCode = "\n\t\t\t\t\t\t\t//await load_config(config_json);\n\t\t\t\t\t\t\t//waitforload(1000);\n\t\t\t\t\t\t\t";
                }

                if ($isIab === 1) {
                    // For TCF: loadIabtcf() handles Conzent.init() after vendor list loads (iab-script.js:2608).
                    // Do NOT call Conzent.init() in $directLoad — vendor data isn't ready yet.
                    // But loadInit() (GCM, GTM inject, consent listeners) must still run.
                    $readyScript = "\n\t\t\t\t\t\tloadInit();\n\t\t\t\t\t\t\"complete\" === document.readyState ? scanCookie() : window.addEventListener(\"load\", scanCookie);";
                    $directLoad = '';
                } else {
                    $readyScript = '';
                    $directLoad = "\n\t\t\t\t\t\tvar show_banner_new = _showBanner();\n\t\t\t\t\t\tif(show_banner_new){\n\t\t\t\t\t\t\t_cnzDebug('show','Conzent.init() called — rendering banner');\n\t\t\t\t\t\t\tConzent.init();\n\t\t\t\t\t\t} else {\n\t\t\t\t\t\t\t_cnzDebug('hide','Conzent.init() skipped — _showBanner() returned 0');\n\t\t\t\t\t\t\t_showDebugState();\n\t\t\t\t\t\t}\n\t\t\t\t\t\tloadInit();\n\t\t\t\t\t\t\n\t\t\t\t\t\t\"complete\" === document.readyState ? scanCookie() : window.addEventListener(\"load\", scanCookie);\n\t\t\t\t\t\t";
                }

                $mainScript = str_replace('[LOAD_CONFIG]', $scriptCode, $mainScript);
                $mainScript = str_replace('[DIRECT_LOAD]', $directLoad, $mainScript);
                $mainScript = str_replace('[READY_LOAD]', $readyScript, $mainScript);
            } else {
                $directLoad = "\n\t\t\t\t\tvar show_banner_new = _showBanner();\n\t\t\t\t\tif(show_banner_new){\n\t\t\t\t\t\t_cnzDebug('show','Conzent.init() called — rendering banner');\n\t\t\t\t\t\tConzent.init();\n\t\t\t\t\t} else {\n\t\t\t\t\t\t_cnzDebug('hide','Conzent.init() skipped — _showBanner() returned 0');\n\t\t\t\t\t\t_showDebugState();\n\t\t\t\t\t}\n\t\t\t\t\tloadInit();\n\t\t\t\t\t";
                $mainScript = str_replace('[LOAD_CONFIG]', '', $mainScript);
                $mainScript = str_replace('[DIRECT_LOAD]', $directLoad, $mainScript);
                $mainScript = str_replace('[READY_LOAD]', '', $mainScript);
            }

            // ── A/B variant configs ─────────────────────────────
            $abVariants = $this->loadABVariants(
                $siteId,
                $abControlConfig ?? [],
                $abControlOptionsVal ?? [],
                $abControlBannerRow ?? [],
                $abConfigCtx ?? [],
            );
            $mainScript = str_replace('[AB_VARIANTS]', json_encode($abVariants, JSON_THROW_ON_ERROR), $mainScript);

            // ── Write, minify, then inject pre-minified IAB code ─
            $written = file_put_contents($scriptPath, $mainScript);
            if ($written === false) {
                throw new \RuntimeException('Failed to write script: ' . $scriptPath);
            }
            $cacheDisabled = filter_var($_ENV['DISABLE_CACHE'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

            if (!$cacheDisabled) {
                $this->minifyScript($scriptPath);
            }

            // Inject pre-minified IAB stub/script after minification
            if ($IAB2_STUB !== '' || $IAB2_SCRIPT !== '') {
                $minified = (string) file_get_contents($scriptPath);
                $minified = str_replace($iabStubToken, $IAB2_STUB, $minified);
                $minified = str_replace($iabScriptToken, $IAB2_SCRIPT, $minified);
                // The IAB script uses placeholders that need replacing after injection
                $minified = str_replace('[CNCMPID]', (string) self::CMP_ID, $minified);
                $minified = str_replace('[CNCMPVERSION]', (string) self::CMP_VERSION, $minified);
                $minified = str_replace('[WEB_PATH]', $this->webRoot, $minified);
                file_put_contents($scriptPath, $minified);
            }

            // ── Cache-busting version file ────────────────────
            // Pass user-defined script whitelist so the early blocker can skip them
            $userAllowedRaw = (string) ($site['allowed_scripts'] ?? '');
            $userWhitelist = [];
            if ($userAllowedRaw !== '') {
                $decoded = json_decode($userAllowedRaw, true);
                if (\is_array($decoded)) {
                    $userWhitelist = array_values(array_filter($decoded));
                }
            }
            $this->writeVersionFile($websiteKey, $scriptPath, $cacheDisabled, $userWhitelist);

            // ── Purge caches ──────────────────────────────────
            if (!$cacheDisabled) {
                $this->cachePurge->purgeForSite($websiteKey, $siteDomain);
            } else {
                // Still flush Redis so stale config doesn't linger
                $this->cachePurge->purgeRedis($websiteKey);
            }

            $this->logger->info('Script generated', ['site_id' => $siteId, 'key' => $websiteKey]);
            return true;

        } catch (\Throwable $e) {
            $this->logger->error('Script generation failed', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Regenerate scripts for all active sites of a user.
     */
    public function generateForUser(int $userId): void
    {
        $sites = $this->db->fetchAllAssociative(
            'SELECT id FROM oci_sites WHERE user_id = :uid AND status = :s AND deleted_at IS NULL',
            ['uid' => $userId, 's' => 'active'],
        );

        foreach ($sites as $site) {
            $this->generate((int) $site['id']);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  DATA LOADING METHODS
    // ═══════════════════════════════════════════════════════════

    private function loadSite(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_sites WHERE id = :id',
            ['id' => $siteId],
        );

        return $row !== false ? $row : null;
    }

    private function isSiteDisabled(array $site): bool
    {
        // Legacy: status != 1 means disabled
        // OCI: status != 'active' means disabled
        $status = $site['status'] ?? '';
        if ($status === 'active' || $status === '1' || $status === 1) {
            return false;
        }
        return true;
    }

    private function loadPlanData(int $userId): array
    {
        $result = [
            'features' => [],
            'is_paid' => false,
            'is_enterprise' => false,
            'exceeded' => false,
            'max_lang' => 0,
        ];

        try {
            // Enterprise check
            $isEnterprise = $this->db->fetchOne(
                'SELECT is_enterprise FROM oci_users WHERE id = :uid',
                ['uid' => $userId],
            );
            if ($isEnterprise !== false && (int) $isEnterprise > 0) {
                $result['is_enterprise'] = true;
                $result['is_paid'] = true;
            }

            // Use new pricing system (PricingService + SubscriptionService)
            if ($this->subscriptionService !== null && $this->pricingService !== null) {
                $planKey = $this->subscriptionService->getPlanKey($userId);

                if ($planKey === null && !$result['is_enterprise']) {
                    // No subscription and not enterprise → exceeded
                    $result['exceeded'] = true;
                    return $result;
                }

                if ($planKey !== null) {
                    $result['is_paid'] = true;
                    $result['max_lang'] = $this->pricingService->getLimit($planKey, 'max_languages');

                    // Check monthly pageview limit
                    $pvLimit = $this->pricingService->getLimit($planKey, 'pageviews_per_month');
                    if ($pvLimit > 0 && $this->pageviewRepo !== null) {
                        $monthlyTotal = $this->pageviewRepo->getMonthlyTotalForUser($userId);
                        if ($monthlyTotal >= $pvLimit) {
                            $result['exceeded'] = true;
                            return $result;
                        }
                    }

                    // Convert feature keys to the array format that checkFeature() expects
                    $featureKeys = $this->pricingService->getFeatureKeys($planKey);
                    $result['features'] = array_map(
                        static fn(string $key): array => ['feature_key' => $key],
                        $featureKeys,
                    );
                } elseif ($result['is_enterprise']) {
                    // Enterprise: all features unlocked
                    $result['features'] = [];
                }

                return $result;
            }

            // Fallback: legacy tables (oci_*_legacy)
            // This path is used only during migration or in self-hosted editions
            $result['features'] = [];

        } catch (\Throwable $e) {
            $this->logger->warning('Plan data load failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    private function loadPublisherCountry(int $userId): string
    {
        try {
            $country = $this->db->fetchOne(
                'SELECT country_code FROM oci_user_companies WHERE user_id = :uid LIMIT 1',
                ['uid' => $userId],
            );
            if ($country !== false && $country !== '' && $country !== null) {
                return (string) $country;
            }
        } catch (\Throwable) {
        }

        return 'AA';
    }

    private function loadDefaultSiteLang(int $siteId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT sl.language_id AS lang_id, l.lang_code FROM oci_site_languages sl INNER JOIN oci_languages l ON l.id = sl.language_id WHERE sl.site_id = :sid AND sl.is_default = 1 LIMIT 1',
            ['sid' => $siteId],
        );

        if ($row !== false) {
            return $row;
        }

        // Fallback: first language
        $fallback = $this->db->fetchAssociative(
            'SELECT sl.language_id AS lang_id, l.lang_code FROM oci_site_languages sl INNER JOIN oci_languages l ON l.id = sl.language_id WHERE sl.site_id = :sid LIMIT 1',
            ['sid' => $siteId],
        );

        return $fallback !== false ? $fallback : null;
    }

    private function loadDefaultUserLang(int $userId): ?array
    {
        // In legacy: $userSite->defaultUserLang() — gets the user's default lang from their first site
        try {
            $siteId = $this->db->fetchOne(
                "SELECT id FROM oci_sites WHERE user_id = :uid AND status = 'active' AND deleted_at IS NULL ORDER BY id ASC LIMIT 1",
                ['uid' => $userId],
            );
            if ($siteId !== false) {
                return $this->loadDefaultSiteLang((int) $siteId);
            }
        } catch (\Throwable) {
            // ignore
        }

        return ['lang_code' => 'en', 'id' => 1, 'lang_id' => 1];
    }

    private function loadAllLanguages(int $siteId, int $maxLang, int $includeAllLang, array $planFeatures): array
    {
        $allLangsList = $this->db->fetchAllAssociative(
            'SELECT * FROM oci_languages ORDER BY lang_name ASC',
        );

        $siteLangs = $this->db->fetchAllAssociative(
            'SELECT l.*, sl.is_default FROM oci_site_languages sl INNER JOIN oci_languages l ON l.id = sl.language_id WHERE sl.site_id = :sid ORDER BY sl.is_default DESC, l.lang_name ASC',
            ['sid' => $siteId],
        );

        $siteLangCodes = array_map(static fn(array $l) => $l['lang_code'], $siteLangs);

        if ($maxLang > 0 || !$includeAllLang) {
            // Filter all langs to only include site langs and default
            $filtered = [];
            foreach ($allLangsList as $lg) {
                if (($lg['is_default'] ?? 0) == 1 || \in_array($lg['lang_code'], $siteLangCodes, true)) {
                    $filtered[] = $lg;
                }
            }
            return $filtered;
        }

        // Include all languages
        return $allLangsList;
    }

    private function loadBlockProviders(int $siteId, int $userId): array
    {
        $providers = [];

        try {
            // Default block providers
            $defaults = $this->db->fetchAllAssociative(
                'SELECT provider_url, default_action, default_category FROM oci_block_providers',
            );
            foreach ($defaults as $row) {
                if (($row['provider_url'] ?? '') !== '') {
                    $categories = [];
                    if (!empty($row['default_category'])) {
                        $categories[] = $row['default_category'];
                    }
                    $providers[] = [
                        'url' => $row['provider_url'],
                        'categories' => $categories,
                    ];
                }
            }

            // Site-specific block providers
            $siteProviders = $this->db->fetchAllAssociative(
                'SELECT bp.provider_url FROM oci_site_block_providers sbp
                 INNER JOIN oci_block_providers bp ON bp.id = sbp.provider_id
                 WHERE sbp.site_id = :sid AND sbp.enabled = 1',
                ['sid' => $siteId],
            );
            foreach ($siteProviders as $row) {
                if (($row['provider_url'] ?? '') !== '') {
                    $providers[] = [
                        'url' => $row['provider_url'],
                        'categories' => [],
                    ];
                }
            }
        } catch (\Throwable) {
        }

        return $providers;
    }

    /**
     * Load cookie-name patterns for the document.cookie interceptor.
     * Returns [{p: "regex", c: "category"}, ...] for the JS early blocker.
     */
    private function loadBlockedCookiePatterns(): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT cookie_pattern, category FROM oci_blocked_cookie_names WHERE enabled = 1',
            );

            $patterns = [];
            foreach ($rows as $row) {
                if (($row['cookie_pattern'] ?? '') !== '') {
                    $patterns[] = [
                        'p' => $row['cookie_pattern'],
                        'c' => $row['category'] ?? 'marketing',
                    ];
                }
            }

            return $patterns;
        } catch (\Throwable) {
            // Table may not exist yet — return hardcoded defaults
            return [
                ['p' => '^(_fbp|_fbc|fr)$', 'c' => 'marketing'],
                ['p' => '^(_gcl_au|_gcl_aw|_gcl_dc|_gcl_gb|_gcl_gs|_gcl_ha|_gcl_gf)$', 'c' => 'marketing'],
                ['p' => '^(_ga|_ga_|_gid|_gat|__utm)', 'c' => 'analytics'],
                ['p' => '^(_tt_|_ttp)$', 'c' => 'marketing'],
                ['p' => '^(_uet|_uetsid|_uetvid|MUID|_clck|_clsk)$', 'c' => 'marketing'],
                ['p' => '^(_scid|_sctr|sc_at)$', 'c' => 'marketing'],
                ['p' => '^(_pin_unauth|_pinterest_)$', 'c' => 'marketing'],
                ['p' => '^(_rdt_uuid|_rdt_cid)$', 'c' => 'marketing'],
                ['p' => '^(IDE|test_cookie|DSID)$', 'c' => 'marketing'],
                ['p' => '^(_hjid|_hjSession)', 'c' => 'analytics'],
                ['p' => '^(mp_|amplitude_id)', 'c' => 'analytics'],
                ['p' => '^(ajs_anonymous_id|ajs_user_id)', 'c' => 'analytics'],
                ['p' => '^(_li_ss|li_sugr|bcookie|lidc|UserMatchHistory)$', 'c' => 'marketing'],
            ];
        }
    }

    /**
     * Build the country→framework rules map for embedding in script.js.
     *
     * Returns a compact map that the client-side script uses to determine
     * blocking, consent model, and required buttons per visitor country.
     * Falls back to empty object if framework services are unavailable.
     *
     * @return array<string, mixed>
     */
    /**
     * Derive banner display type from selected frameworks, falling back to the DB column.
     */
    private function resolveBannerDisplay(int $siteId, string $fallback): string
    {
        try {
            $fwIds = $this->frameworkRepo->getFrameworksForSite($siteId);
            if ($fwIds === []) {
                return $fallback;
            }

            $hasGdpr = \in_array('gdpr', $fwIds, true) || \in_array('eprivacy_directive', $fwIds, true);
            $hasCcpa = \in_array('ccpa_cpra', $fwIds, true);

            if ($hasGdpr && $hasCcpa) {
                return 'gdpr_ccpa';
            }
            if ($hasCcpa) {
                return 'ccpa';
            }

            return 'gdpr';
        } catch (\Throwable) {
            return $fallback;
        }
    }

    private function buildFrameworkRulesMap(int $siteId): array|object
    {
        try {
            $frameworkIds = $this->frameworkRepo->getFrameworksForSite($siteId);

            if ($frameworkIds === []) {
                return new \stdClass();
            }

            return $this->frameworkService->getCountryToFrameworkMap($frameworkIds);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to build framework rules map', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);

            return new \stdClass();
        }
    }

    private function loadAssociatedDomains(int $siteId, int $userId): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT domain, privacy_policy_url AS privacy_policy FROM oci_associated_sites WHERE site_id = :sid',
                ['sid' => $siteId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadBanners(int $siteId, string $bannerDisplay): array
    {
        $baseSql = 'SELECT sb.*, bt.cookie_laws AS consent_type, bt.id AS template_id
                FROM oci_site_banners sb
                LEFT JOIN oci_banner_templates bt ON bt.id = sb.banner_template_id
                WHERE sb.site_id = :siteId';

        $params = ['siteId' => $siteId];

        // cookie_laws may be stored as JSON (e.g. {"gdpr":1,"ccpa":0}) or plain string
        $sql = $baseSql;
        if ($bannerDisplay === 'gdpr') {
            $sql .= " AND (bt.cookie_laws IS NULL OR bt.cookie_laws LIKE '%\"gdpr\":1%' OR bt.cookie_laws = 'gdpr')";
        } elseif ($bannerDisplay === 'ccpa') {
            $sql .= " AND (bt.cookie_laws LIKE '%\"ccpa\":1%' OR bt.cookie_laws = 'ccpa')";
        }

        $banners = $this->db->fetchAllAssociative($sql, $params);

        // Fallback: if no template matches the framework filter, load any banner
        // assigned to the site. Override consent_type to match the target display
        // so the correct layout is used even with a mismatched template.
        $isFallback = false;
        if ($banners === [] && $sql !== $baseSql) {
            $banners = $this->db->fetchAllAssociative($baseSql, $params);
            $isFallback = true;
        }

        // Normalize consent_type from JSON to simple string
        foreach ($banners as &$b) {
            if ($isFallback) {
                // Force consent_type to target display type for mismatched templates
                $b['consent_type'] = $bannerDisplay === 'gdpr_ccpa' ? 'gdpr' : $bannerDisplay;
            } else {
                $b['consent_type'] = $this->normalizeCookieLaws($b['consent_type'] ?? '', $bannerDisplay);
            }
        }
        unset($b);

        return $banners;
    }

    /**
     * Convert cookie_laws from JSON format ({"gdpr":1,"ccpa":0}) to simple string ('gdpr'/'ccpa').
     */
    private function normalizeCookieLaws(string $raw, string $fallback): string
    {
        if ($raw === '' || $raw === null) {
            return $fallback === 'gdpr_ccpa' ? 'gdpr' : $fallback;
        }

        // Already a simple string
        if (\in_array($raw, ['gdpr', 'ccpa', 'gdpr_ccpa'], true)) {
            return $raw;
        }

        // JSON format: {"gdpr":1,"ccpa":0}
        $decoded = json_decode($raw, true);
        if (\is_array($decoded)) {
            $hasGdpr = !empty($decoded['gdpr']);
            $hasCcpa = !empty($decoded['ccpa']);

            if ($hasGdpr && $hasCcpa) {
                return 'gdpr_ccpa';
            }
            if ($hasGdpr) {
                return 'gdpr';
            }
            if ($hasCcpa) {
                return 'ccpa';
            }
        }

        return $fallback === 'gdpr_ccpa' ? 'gdpr' : $fallback;
    }

    /**
     * Normalise flat OCI layout_setting into nested structure expected by config builder.
     *
     * Flat format (from BannerUpdateHandler):
     *   {"banner_type":"banner","position":"bottom","preference_display":"center","category_on_first_layer":false,...}
     *
     * Nested format (expected by config builder):
     *   {"cookie_notice":{"display_mode":"banner","position":"bottom"},"preference":{"display_mode":"center"},...}
     */
    private function normaliseLayoutSetting(array $layout): array
    {
        // Already nested? Return as-is.
        if (isset($layout['cookie_notice']) || isset($layout['preference'])) {
            return $layout;
        }

        if ($layout === []) {
            return $layout;
        }

        // Map flat → nested
        $cookieNotice = [];
        $preference = [];
        $optOutCenter = [];
        $notice = [];

        // Banner display mode: "box", "banner", "popup"
        if (isset($layout['banner_type'])) {
            $cookieNotice['display_mode'] = $layout['banner_type'];
        }

        // Banner position: "bottom", "top", "bottom_left", "bottom_right", "top_left", "top_right", "center"
        if (isset($layout['position'])) {
            $cookieNotice['position'] = $layout['position'];
        }

        // Preference center display mode
        if (isset($layout['preference_display'])) {
            $preference['display_mode'] = $layout['preference_display'];
        }
        if (isset($layout['preference_position'])) {
            $preference['position'] = $layout['preference_position'];
        }

        // Opt-out center (CCPA)
        if (isset($layout['optout_display'])) {
            $optOutCenter['display_mode'] = $layout['optout_display'];
        }
        if (isset($layout['optout_position'])) {
            $optOutCenter['position'] = $layout['optout_position'];
        }

        // Show categories on first layer
        if (!empty($layout['category_on_first_layer'])) {
            $notice['category_on_first_layer'] = 1;
        }

        return [
            'cookie_notice' => $cookieNotice,
            'preference' => $preference,
            'opt_out_center' => $optOutCenter,
            'notice' => $notice,
        ];
    }

    /**
     * Normalise flat OCI content_setting into nested structure expected by buildGdprNotice/buildCcpaNotice.
     *
     * Flat format (from BannerUpdateHandler):
     *   {"accept_all_button": true, "reject_all_button": true, "floating_button": true, ...}
     *
     * Nested format (expected by builder):
     *   {"gdpr": {"cookie_notice": {"accept_all_button": "1", ...}, "preference_center": {...}, ...}}
     */
    private function normaliseContentSetting(array $content, string $cookieLaws): array
    {
        // Already fully nested (gdpr/ccpa wrapper)? Return as-is.
        if (isset($content['gdpr']) || isset($content['ccpa'])) {
            return $content;
        }

        // If empty, return as-is (builders handle defaults).
        if ($content === []) {
            return $content;
        }

        // Semi-nested: has section keys (cookie_notice, preference_center, etc.)
        // but missing the gdpr/ccpa wrapper. Wrap directly.
        if (isset($content['cookie_notice']) || isset($content['preference_center'])
            || isset($content['revisit_consent_button']) || isset($content['cookie_list'])) {
            return [$cookieLaws => $content];
        }

        // Map flat keys → nested structure
        $cookieNotice = [];
        $preferenceCenter = [];
        $cookieList = [];
        $revisitConsentButton = [];

        // cookie_policy_link (frontend toggle) → cookie_policy_label (legacy key used by builder)
        if (isset($content['cookie_policy_link'])) {
            $content['cookie_policy_label'] = $content['cookie_policy_link'];
            unset($content['cookie_policy_link']);
        }

        $noticeKeys = [
            'accept_all_button', 'reject_all_button', 'customize_button',
            'close_button', 'cookie_policy_label', 'disable_branding', 'custom_logo',
        ];
        $buttonOrderKeys = ['accept_all', 'reject_all', 'customize'];

        foreach ($noticeKeys as $key) {
            if (isset($content[$key]) && $content[$key]) {
                $cookieNotice[$key] = \is_bool($content[$key]) ? '1' : $content[$key];
            }
        }

        // Button ordering
        if (isset($content['button_order']) && \is_array($content['button_order'])) {
            $cookieNotice['button_order'] = $content['button_order'];
        } else {
            $cookieNotice['button_order'] = [
                'accept_all' => $content['accept_all_order'] ?? 'right',
                'reject_all' => $content['reject_all_order'] ?? 'center',
                'customize' => $content['customize_order'] ?? 'left',
            ];
        }

        // Preference center
        if (!empty($content['show_google_privacy_policy'])) {
            $preferenceCenter['show_google_privacy_policy'] = '1';
        }

        // Cookie list
        if (!empty($content['show_cookie_on_banner'])) {
            $cookieList['show_cookie_on_banner'] = '1';
        }

        // Revisit consent button
        if (!empty($content['floating_button'])) {
            $revisitConsentButton['floating_button'] = '1';
        }
        $revisitConsentButton['button_position'] = $content['button_position'] ?? 'left';
        if (isset($content['revisit_custom_icon'])) {
            $revisitConsentButton['custom_icon'] = $content['revisit_custom_icon'];
        }

        // Respect GPC
        if (!empty($content['respect_global_privacy_control'])) {
            $preferenceCenter['respect_global_privacy_control'] = '1';
        }

        $nested = [
            'cookie_notice' => $cookieNotice,
            'preference_center' => $preferenceCenter,
            'cookie_list' => $cookieList,
            'revisit_consent_button' => $revisitConsentButton,
        ];

        if ($cookieLaws === 'ccpa') {
            return ['ccpa' => $nested];
        }

        $result = ['gdpr' => $nested];

        if ($cookieLaws === 'gdpr_ccpa') {
            $result['ccpa'] = $nested;
        }

        return $result;
    }

    /**
     * Normalise flat color settings (from the UI) into the nested section structure
     * expected by buildNoticeConfig().
     *
     * UI saves:   colors[element][theme][field]
     * Backend needs: colorSetting[section][theme][element][field]
     */
    /**
     * Sensible default color palette used when a site has no color_setting stored.
     * Keeps the banner usable out of the box instead of rendering grey/unstyled buttons.
     */
    private static function defaultColorPalette(): array
    {
        $btn = static fn (string $bg, string $text, string $border) => [
            'background' => $bg, 'text' => $text, 'border' => $border,
        ];

        $theme = [
            'banner' => ['background' => '#ffffff', 'border' => '#f4f4f4', 'title' => '#212121', 'message' => '#4b4b4b'],
            'accept_all_button' => $btn('#10a37f', '#ffffff', '#10a37f'),
            'reject_all_button' => $btn('#ffffff', '#10a37f', '#10a37f'),
            'customize_button' => $btn('#ffffff', '#10a37f', '#10a37f'),
        ];

        return [
            'cookie_notice' => ['light' => $theme, 'dark' => $theme],
            'preference_center' => [
                'light' => [
                    'save_preference_button' => $btn('#10a37f', '#ffffff', '#10a37f'),
                    'toggle_switch' => ['enabled_state' => '#10a37f', 'disabled_state' => '#dddddd'],
                ],
                'dark' => [
                    'save_preference_button' => $btn('#10a37f', '#ffffff', '#10a37f'),
                    'toggle_switch' => ['enabled_state' => '#10a37f', 'disabled_state' => '#dddddd'],
                ],
            ],
            'revisit_consent_button' => [
                'light' => ['floating_button' => $btn('#10a37f', '#ffffff', '#10a37f')],
                'dark' => ['floating_button' => $btn('#10a37f', '#ffffff', '#10a37f')],
            ],
        ];
    }

    private function normaliseColorSetting(array $colors): array
    {
        // Already nested? (has a section key)
        if (isset($colors['cookie_notice']) || isset($colors['preference_center'])) {
            return $colors;
        }

        if ($colors === []) {
            return self::defaultColorPalette();
        }

        // Map element names → section
        $sectionMap = [
            'banner'               => 'cookie_notice',
            'accept_all_button'    => 'cookie_notice',
            'reject_all_button'    => 'cookie_notice',
            'customize_button'     => 'cookie_notice',
            'save_preference_button' => 'preference_center',
            'toggle_switch'        => 'preference_center',
            'checkbox'             => 'preference_center',
            'cancel_button'        => 'preference_center',
            'floating_button'      => 'revisit_consent_button',
            'alttext_button'       => 'alttext_blocked_content',
            'do_not_sell_link'     => 'opt_out_center',
        ];

        // Rename element keys for backend compatibility
        $elementRename = [
            'alttext_button' => 'button',
        ];

        $nested = [];
        foreach ($colors as $element => $themes) {
            if (!\is_array($themes)) {
                continue;
            }
            $section = $sectionMap[$element] ?? 'cookie_notice';
            $targetElement = $elementRename[$element] ?? $element;

            foreach ($themes as $theme => $fields) {
                if (!\is_array($fields)) {
                    continue;
                }
                if (!isset($nested[$section][$theme][$targetElement])) {
                    $nested[$section][$theme][$targetElement] = [];
                }
                $nested[$section][$theme][$targetElement] = array_merge(
                    $nested[$section][$theme][$targetElement],
                    $fields,
                );
            }
        }

        // If normalisation produced nothing useful, fall back to defaults
        return $nested !== [] ? $nested : self::defaultColorPalette();
    }

    private function loadBannerCategories(): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT id, slug FROM oci_cookie_categories WHERE is_active = 1 ORDER BY sort_order ASC',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadUserCookieCategories(int $userId, int $siteId, int $langId): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT cc.id, cc.slug, cc.default_consent,
                        COALESCE(scct.name, cct.name, cc.slug) AS name,
                        COALESCE(scct.description, cct.description, \'\') AS description
                 FROM oci_site_cookie_categories scc
                 INNER JOIN oci_cookie_categories cc ON cc.id = scc.category_id
                 LEFT JOIN oci_site_cookie_category_translations scct ON scct.site_cookie_category_id = scc.id AND scct.language_id = :langId
                 LEFT JOIN oci_cookie_category_translations cct ON cct.category_id = cc.id AND cct.language_id = :langId2
                 WHERE scc.site_id = :sid
                 ORDER BY cc.sort_order ASC',
                ['sid' => $siteId, 'langId' => $langId, 'langId2' => $langId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadDefaultCookieCategories(int $langId): array
    {
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT c.id, c.slug, c.default_consent, ct.name, ct.description
                 FROM oci_cookie_categories c
                 INNER JOIN oci_cookie_category_translations ct ON c.id = ct.category_id
                 WHERE c.is_active = 1 AND ct.language_id = :langId
                 ORDER BY c.sort_order ASC',
                ['langId' => $langId],
            );

            if (!empty($rows)) {
                return $rows;
            }

            // Fallback to English (language_id = 1)
            return $this->db->fetchAllAssociative(
                'SELECT c.id, c.slug, c.default_consent, ct.name, ct.description
                 FROM oci_cookie_categories c
                 INNER JOIN oci_cookie_category_translations ct ON c.id = ct.category_id
                 WHERE c.is_active = 1 AND ct.language_id = 1
                 ORDER BY c.sort_order ASC',
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadUserBannerContent(int $siteId, int $langId, string $cookieLaws): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT sbft.*, bf.field_key AS field_name, sbft.value AS u_field_value
                 FROM oci_site_banner_field_translations sbft
                 INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
                 INNER JOIN oci_site_banners sb ON sb.id = sbft.site_banner_id
                 WHERE sb.site_id = :siteId AND sbft.language_id = :langId
                 ORDER BY bf.sort_order ASC',
                ['siteId' => $siteId, 'langId' => $langId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadDefaultBannerContent(int $langId, string $cookieLaws): array
    {
        try {
            // Determine which template to load fields for
            $templateId = $cookieLaws === 'ccpa' ? 2 : 1;

            return $this->db->fetchAllAssociative(
                'SELECT bf.id, bf.field_key AS field_name, bfc.id AS category_id,
                        bfc.category_key,
                        COALESCE(bft.label, bf.default_value, \'\') AS trans_value
                 FROM oci_banner_fields bf
                 INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
                 LEFT JOIN oci_banner_field_translations bft ON bft.field_id = bf.id AND bft.language_id = :langId
                 WHERE bfc.template_id = :templateId
                 ORDER BY bfc.sort_order ASC, bf.sort_order ASC',
                ['langId' => $langId, 'templateId' => $templateId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadSiteCookies(int $userId, int $siteId, int $langId): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT sc.id, sc.cookie_name, sc.cookie_domain, sc.category_id,
                        sc.default_duration AS duration,
                        COALESCE(sct.description, \'\') AS description
                 FROM oci_site_cookies sc
                 LEFT JOIN oci_site_cookie_translations sct ON sc.id = sct.site_cookie_id AND sct.language_id = :langId
                 WHERE sc.site_id = :sid
                 ORDER BY sc.cookie_name ASC',
                ['sid' => $siteId, 'langId' => $langId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadBeacons(int $siteId): array
    {
        try {
            return $this->db->fetchAllAssociative(
                'SELECT id AS category_id, beacon_url AS url FROM oci_beacons WHERE site_id = :sid',
                ['sid' => $siteId],
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function loadConsentBannerSetting(int $bannerId, string $optionName): array
    {
        try {
            // Try OCI site_banner_settings table
            $row = $this->db->fetchAssociative(
                'SELECT setting_value AS option_value FROM oci_site_banner_settings WHERE site_banner_id = :bid AND setting_key = :oname LIMIT 1',
                ['bid' => $bannerId, 'oname' => $optionName],
            );
            return $row !== false ? $row : ['option_value' => '{}'];
        } catch (\Throwable) {
            return ['option_value' => '{}'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  BUILDER METHODS
    // ═══════════════════════════════════════════════════════════

    private function buildGdprNotice(
        array &$notice,
        array &$preferenceCenter,
        array &$revisitConsentButton,
        array &$alttextBlockedContent,
        array $contentSetting,
        array $colorSetting,
        string $themeMode,
        array $planFeatures,
        string $siteLogo,
        string $iconLogo,
        string $siteDomain,
        string &$showMoreText,
        string &$showLessText,
        int &$showCookieOnBanner,
        string &$googlePrivacyPolicy,
        string &$disableBranding,
        string &$revisitButtonHtml,
        string &$noticeCustomLogo,
        string &$revisitCustomIcon,
        int $categoryOnFirstLayer,
    ): void {
        $gdpr = $contentSetting['gdpr'] ?? [];
        $siteUrl = 'https://' . $siteDomain;

        $acceptButtonStatus = isset($gdpr['cookie_notice']['accept_all_button']) ? 1 : 0;
        $closeButtonStatus = isset($gdpr['cookie_notice']['close_button']) ? 1 : 0;
        if (!$this->checkFeature('banner_close_button', $planFeatures)) {
            $closeButtonStatus = 1;
        }
        $rejectButtonStatus = isset($gdpr['cookie_notice']['reject_all_button']) ? 1 : 0;
        $customizeButtonStatus = isset($gdpr['cookie_notice']['customize_button']) ? 1 : 0;

        $acceptButtonOrder = $gdpr['cookie_notice']['button_order']['accept_all'] ?? 'right';
        $rejectButtonOrder = $gdpr['cookie_notice']['button_order']['reject_all'] ?? 'center';
        $customizeButtonOrder = $gdpr['cookie_notice']['button_order']['customize'] ?? 'left';

        $showCookieOnBanner = isset($gdpr['cookie_list']['show_cookie_on_banner']) ? 1 : 0;
        $showGooglePrivacyPolicy = isset($gdpr['preference_center']['show_google_privacy_policy']) ? 1 : 0;

        if ($showGooglePrivacyPolicy) {
            $googlePrivacyPolicy = '[conzent_preference_center_google_privacy_message]<a href="[conzent_preference_center_google_privacy_url]" rel="nofollow" target="_blank">[conzent_preference_center_google_privacy_label]</a>';
        }

        $showMoreButton = '[conzent_preference_center_show_more_button]';
        $showLessButton = '[conzent_preference_center_show_less_button]';

        // Buttons
        $cn = $colorSetting['cookie_notice'][$themeMode] ?? [];
        $acceptButton = $this->buildButton($acceptButtonStatus, '[conzent_cookie_notice_accept_all_button]', 'cookieAccept', 'button', $cn['accept_all_button'] ?? []);
        $closeButton = $this->buildButton($closeButtonStatus, '[conzent_close_button]', 'closeIcon', 'link', []);
        $rejectButton = $this->buildButton($rejectButtonStatus, '[conzent_cookie_notice_reject_all_button]', 'cookieReject', 'button', $cn['reject_all_button'] ?? []);
        $customizeButton = $this->buildButton($customizeButtonStatus, '[conzent_cookie_notice_customize_button]', 'cookieSettings', 'button', $cn['customize_button'] ?? []);

        // Button ordering
        $notice['buttons'] = $this->orderButtons(
            $acceptButton, $rejectButton, $customizeButton,
            $acceptButtonOrder, $rejectButtonOrder, $customizeButtonOrder,
            $planFeatures,
        );
        $notice['buttons']['close_button'] = $closeButton;

        // Cookie policy
        $cookiePolicyStatus = isset($gdpr['cookie_notice']['cookie_policy_label']) ? 1 : 0;
        $notice['cookie_policy'] = [
            'status' => $cookiePolicyStatus,
            'label' => '[conzent_cookie_notice_cookie_policy_label]',
            'link' => '[conzent_cookie_notice_cookie_policy_url]',
        ];

        // Logos
        $noticeCustomLogo = $gdpr['cookie_notice']['custom_logo'] ?? '';
        $noticeCustomLogo = str_replace('#', '', $noticeCustomLogo);
        $revisitCustomIcon = $gdpr['revisit_consent_button']['custom_icon'] ?? '';

        if ($noticeCustomLogo === '' && $this->checkFeature('custom_branding', $planFeatures) && $siteLogo !== '') {
            $noticeCustomLogo = $this->webRoot . 'files/' . $siteLogo;
        }
        if ($revisitCustomIcon === '' && $this->checkFeature('custom_branding', $planFeatures) && $iconLogo !== '') {
            $revisitCustomIcon = $this->webRoot . 'files/' . $iconLogo;
        }

        $notice['custom_logo'] = $noticeCustomLogo;

        // Branding
        $brandingHtml = 'Powered by&nbsp;<a href="https://getconzent.com" target="_blank" rel="nofollow"><img src="' . $this->webRoot . 'media/branding_logo.png" alt="Conzent"></a>';
        if (isset($gdpr['cookie_notice']['disable_branding']) && $this->checkFeature('custom_branding', $planFeatures)) {
            $brandingHtml = '';
        }
        $notice['branding'] = $brandingHtml;
        $disableBranding = $brandingHtml;

        // Banner style
        $notice['banner'] = [
            'style' => [
                'background' => $cn['banner']['background'] ?? '',
                'border' => $cn['banner']['border'] ?? '',
                'title' => $cn['banner']['title'] ?? '',
                'message' => $cn['banner']['message'] ?? '',
            ],
        ];

        // Preference center
        $pc = $colorSetting['preference_center'][$themeMode] ?? [];
        $preferenceCenter['buttons'] = [
            'reject_button' => $rejectButton,
            'save_preferences' => $this->buildButton(1, '[conzent_preference_center_save_preferences]', 'cookieSavePreferences', 'button', $pc['save_preference_button'] ?? []),
            'accept_button' => $acceptButton,
        ];

        $preferenceCenter['toggle_switch'] = [
            'style' => [
                'enabled_state' => $pc['toggle_switch']['enabled_state'] ?? '',
                'disabled_state' => $pc['toggle_switch']['style']['disabled_state'] ?? $pc['toggle_switch']['disabled_state'] ?? '',
            ],
        ];

        $showMoreText = '<span id="cookieOptShowMore">' . $showMoreButton . '</span>';
        $showLessText = '<span id="cookieOptShowLess">' . $showLessButton . '</span>';

        // Revisit button
        $revisitConsentButton = [
            'style' => ['background' => $colorSetting['revisit_consent_button'][$themeMode]['floating_button']['background'] ?? ''],
        ];

        $alttextBlockedContent = [
            'style' => [
                'background' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['background'] ?? '',
                'border' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['border'] ?? '',
                'text' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['text'] ?? '',
            ],
        ];

        $revisitButtonPosition = $gdpr['revisit_consent_button']['button_position'] ?? 'left';
        $revisitButtonStatus = isset($gdpr['revisit_consent_button']['floating_button']) ? 1 : 0;

        $revisitButtonHtml = '';
        if ($revisitButtonStatus) {
            $styles = [];
            // Offset support — override default CSS positioning
            $offsetBottom = $gdpr['revisit_consent_button']['offset_bottom'] ?? '';
            $offsetSide = $gdpr['revisit_consent_button']['offset_side'] ?? '';
            if ($offsetBottom !== '') {
                $styles[] = 'bottom:' . ((int) $offsetBottom) . 'px';
            }
            if ($offsetSide !== '') {
                $sideProperty = $revisitButtonPosition === 'right' ? 'right' : 'left';
                $styles[] = $sideProperty . ':' . ((int) $offsetSide) . 'px';
            }
            $revisitStyle = !empty($styles) ? ' style="' . implode(';', $styles) . '"' : '';
            $revisitButtonHtml = '<div class="cnz-btn-revisit-wrapper cnz-revisit-hide conzent-revisit-bottom-' . $revisitButtonPosition . '"' . $revisitStyle . ' data-tooltip="[conzent_revisit_consent_button_text_on_hover]"> <span class="conzent-revisit" id="revisitBtn" role="button" tabindex="0" title="[conzent_revisit_consent_button_text_on_hover]" aria-label="[conzent_revisit_consent_button_text_on_hover]">[conzent_revisit_icon]</span> </div>';
        }
    }

    private function buildCcpaNotice(
        array &$notice,
        array &$optOutCenter,
        array &$revisitConsentButton,
        array &$alttextBlockedContent,
        array $contentSetting,
        array $colorSetting,
        string $themeMode,
        array $planFeatures,
        string $siteLogo,
        string $iconLogo,
        string $siteDomain,
        string &$showMoreText,
        string &$showLessText,
        int &$showCookieOnBanner,
        int &$showRespectGpc,
        string &$globalPrivacyPolicy,
        string &$doNotSellCheckbox,
        string &$disableBranding,
        string &$revisitButtonHtml,
        string &$noticeCustomLogo,
        string &$revisitCustomIcon,
    ): void {
        $ccpa = $contentSetting['ccpa'] ?? [];
        $siteUrl = 'https://' . $siteDomain;
        $cn = $colorSetting['cookie_notice'][$themeMode] ?? [];

        $optOutCenter['show_respect_gpc'] = isset($ccpa['opt_out_center']['respect_global_privacy_control']) ? 1 : 0;
        $showRespectGpc = $optOutCenter['show_respect_gpc'];
        $globalPrivacyPolicy = '[conzent_opt_out_center_respect_global_privacy_control]';
        $showCookieOnBanner = 0;

        $doNotSellLink = '[conzent_cookie_notice_do_not_sell_link]';

        $showMoreText = '<span id="cookieOptShowMore">[conzent_opt_out_center_show_more_button]</span>';
        $showLessText = '<span id="cookieOptShowLess">[conzent_opt_out_center_show_less_button]</span>';

        // Notice banner style
        $notice['banner'] = [
            'style' => [
                'background' => $cn['banner']['background'] ?? '',
                'border' => $cn['banner']['border'] ?? '',
                'title' => $cn['banner']['title'] ?? '',
                'message' => $cn['banner']['message'] ?? '',
            ],
        ];

        $closeButton = $this->buildButton(1, '[conzent_close_button]', 'closeIcon', 'link', []);
        $notice['buttons'] = ['close_button' => $closeButton];

        $notice['do_not_sell_link'][] = [
            'status' => 1,
            'label' => $doNotSellLink,
            'tagId' => 'donotselllink',
            'link' => '',
            'type' => 'link',
            'style' => [
                'background' => '',
                'border' => '',
                'text' => $cn['do_not_sell_link']['text'] ?? '',
            ],
        ];

        $doNotSellCheckbox = '<div class="donotsell_checkbox_line"><input type="checkbox" class="cnz-custom-checkbox" value="1" id="donotsell_checkbox" name="donotsell_checkbox">&nbsp;&nbsp;<lable for="donotsell_checkbox">' . $doNotSellLink . '</label></div>';

        // Opt out center buttons
        $oc = $colorSetting['opt_out_center'][$themeMode] ?? [];
        $optOutCenter['buttons'] = [
            'save_preferences' => $this->buildButton(1, '[conzent_opt_out_center_save_preferences]', 'cookieOptSavePreferences', 'button', $oc['save_preference_button'] ?? []),
            'cancel_button' => $this->buildButton(1, '[conzent_opt_out_center_cancel_button]', 'cookieOptCancel', 'link', $oc['cancel_button'] ?? []),
        ];

        $optOutCenter['checkbox'] = [
            'style' => [
                'enabled_state' => $oc['checkbox']['enabled_state'] ?? '',
                'disabled_state' => $oc['checkbox']['disabled_state'] ?? '',
            ],
        ];

        // Cookie policy
        $ccpaCookiePolicyStatus = isset($ccpa['opt_out_center']['cookie_policy_label']) ? 1 : 0;
        $notice['cookie_policy'] = [
            'status' => $ccpaCookiePolicyStatus,
            'label' => '[conzent_opt_out_center_cookie_policy_label]',
            'link' => '[conzent_opt_out_center_cookie_policy_url]',
        ];

        // Logos
        $noticeCustomLogo = $ccpa['cookie_notice']['custom_logo'] ?? '';
        $noticeCustomLogo = str_replace('#', '', $noticeCustomLogo);
        $revisitCustomIcon = $ccpa['revisit_consent_button']['custom_icon'] ?? '';

        if ($noticeCustomLogo === '' && $this->checkFeature('custom_branding', $planFeatures) && $siteLogo !== '') {
            $noticeCustomLogo = $this->webRoot . 'files/' . $siteLogo;
        }
        if ($revisitCustomIcon === '' && $this->checkFeature('custom_branding', $planFeatures) && $iconLogo !== '') {
            $revisitCustomIcon = $this->webRoot . 'files/' . $iconLogo;
        }

        // Branding
        $disableBranding = 'Powered by&nbsp;<a href="https://getconzent.com" target="_blank" rel="nofollow"><img alt="Conzent" src="' . $this->webRoot . 'media/branding_logo.png"></a>';
        if (isset($ccpa['cookie_notice']['disable_branding']) && $this->checkFeature('custom_branding', $planFeatures)) {
            $disableBranding = '';
        }

        // Revisit button
        $revisitConsentButton = [
            'style' => ['background' => $colorSetting['revisit_consent_button'][$themeMode]['floating_button']['background'] ?? ''],
        ];
        $alttextBlockedContent = [
            'style' => [
                'background' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['background'] ?? '',
                'border' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['border'] ?? '',
                'text' => $colorSetting['alttext_blocked_content'][$themeMode]['button']['text'] ?? '',
            ],
        ];

        $revisitButtonPosition = $ccpa['revisit_consent_button']['button_position'] ?? 'left';
        $revisitButtonStatus = isset($ccpa['revisit_consent_button']['floating_button']) ? 1 : 0;

        $revisitButtonHtml = '';
        if ($revisitButtonStatus) {
            $revisitStyle = '';
            if (isset($revisitConsentButton['style']['background']) && $revisitConsentButton['style']['background'] !== '') {
                $revisitStyle = ' style="background-color:' . $revisitConsentButton['style']['background'] . '"';
            }
            $revisitButtonHtml = '<div class="cnz-btn-revisit-wrapper cnz-revisit-hide conzent-revisit-bottom-' . $revisitButtonPosition . '"' . $revisitStyle . ' data-tooltip="[conzent_revisit_consent_button_text_on_hover]"> <span class="conzent-revisit" id="revisitBtn" role="button" tabindex="0" aria-label="[conzent_revisit_consent_button_text_on_hover]">[conzent_revisit_icon]</span> </div>';
        }
    }

    private function buildButton(int $status, string $label, string $tagId, string $type, array $style): array
    {
        return [
            'status' => $status,
            'label' => $label,
            'tagId' => $tagId,
            'link' => '',
            'type' => $type,
            'style' => [
                'background' => $style['background'] ?? '',
                'border' => $style['border'] ?? '',
                'text' => $style['text'] ?? '',
            ],
        ];
    }

    private function orderButtons(
        array $acceptButton,
        array $rejectButton,
        array $customizeButton,
        string $acceptOrder,
        string $rejectOrder,
        string $customizeOrder,
        array $planFeatures,
    ): array {
        $default = [
            'customize_button' => $customizeButton,
            'reject_button' => $rejectButton,
            'accept_button' => $acceptButton,
        ];

        if (!$this->checkFeature('change_button_order', $planFeatures)) {
            return $default;
        }

        // Build ordered array based on left/center/right positions
        $ordered = [];
        $buttons = [
            'accept_button' => [$acceptButton, $acceptOrder],
            'reject_button' => [$rejectButton, $rejectOrder],
            'customize_button' => [$customizeButton, $customizeOrder],
        ];

        // Sort by position: left first, center second, right third
        $positionOrder = ['left' => 0, 'center' => 1, 'right' => 2];
        uasort($buttons, static function ($a, $b) use ($positionOrder) {
            return ($positionOrder[$a[1]] ?? 1) <=> ($positionOrder[$b[1]] ?? 1);
        });

        foreach ($buttons as $key => [$button]) {
            $ordered[$key] = $button;
        }

        return $ordered;
    }

    private function loadHtmlTemplate(string $cookieLaws, array $generalOption, array $bannerRow = [], int $siteId = 0): string
    {
        $isIab = ($generalOption['iab_support'] ?? 0) === 1 && $cookieLaws === 'gdpr';

        $layoutKey = $bannerRow['layout_key'] ?? null;
        $customLayoutId = isset($bannerRow['custom_layout_id']) ? (int) $bannerRow['custom_layout_id'] : null;

        if ($customLayoutId !== null && $customLayoutId > 0) {
            // Custom layout from database — flat HTML but still has {{ }} Twig placeholders
            $html = $this->layoutService->getLayoutHtml(null, $customLayoutId, $siteId);
            if ($html === '') {
                // Fall through to system layout
                $customLayoutId = null;
            }
        }

        if ($customLayoutId === null || $customLayoutId <= 0) {
            // Default layout based on cookie law type
            if ($layoutKey === null || $layoutKey === '') {
                $layoutKey = $cookieLaws === 'ccpa' ? 'ccpa/classic' : 'gdpr/classic';
            }

            $html = $this->layoutService->getLayoutHtml($layoutKey, null, $siteId);
            if ($html === '') {
                throw new \RuntimeException("Layout template not found: {$layoutKey}");
            }
        }

        return $this->layoutService->renderForScript($html, [
            'banner_type' => '[banner_type]',
            'display_position' => '[display_position]',
            'preference_type' => '[preference_type]',
            'preference_position' => '[preference_position]',
            'colors' => [
                'notice_bg' => '[notice_background_color]',
                'notice_border' => '[notice_border_color]',
                'notice_title' => '[notice_title_color]',
                'notice_description' => '[notice_description_color]',
            ],
            'buttons_html' => '[button_wrap]',
            'pref_buttons_html' => '[preference_button_wrap]',
            'revisit_html' => '[conzent_revisit_html]',
            'cookie_categories_html' => '[conzent_cookie_categories]',
            'branding_html' => '[conzent_branding]',
            'logo_html' => '[conzent_logo]',
            'close_button_html' => '[conzent_close_button]',
            'privacy_policy_link' => '[privacy_policy_link]',
            'banner_cookie_list' => '[banner_cookie_list]',
            'google_privacy_policy' => '[google_privacy_policy]',
            'overlay' => true,
            'iab' => $isIab,
        ]);
    }

    /**
     * Load generated policy HTML for a site (cookie or privacy).
     * Returns the stored policy_content or empty string.
     */
    private function loadPolicyContent(int $siteId, string $type): string
    {
        $table = $type === 'cookie' ? 'oci_cookie_policies' : 'oci_privacy_policies';

        try {
            $content = $this->db->fetchOne(
                "SELECT policy_content FROM {$table} WHERE site_id = :siteId LIMIT 1",
                ['siteId' => $siteId],
            );

            return $content !== false ? (string) $content : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private function buildCategoryHtml(array $cookieTypes): string
    {
        $html = '';
        foreach ($cookieTypes as $val) {
            $slug = $val['value'] ?? '';
            if ($slug === 'necessary') {
                $btnText = '<span class="conzent-always-active">[conzent_cookie_list_always_active_text]</span><input type="checkbox"  name="gdprPrefItem" value="necessary" checked="checked" disabled="disabled" data-compulsory="on">';
            } else {
                $btnText = '<div class="conzent-switch">'
                    . "\n\t\t\t\t\t\t\t\t\t\t  <input type=\"checkbox\" id=\"gdprPrefItem{$slug}\" value=\"{$slug}\" name=\"gdprPrefItem\" class=\"sliding-switch\" data-compulsory=\"off\">"
                    . "\n\t\t\t\t\t\t\t\t\t\t</div>";
            }
            $html .= "\n\t\t\t\t\t\t\t\t\t\t<div class=\"conzent-accordion\" id=\"cnzCategory{$slug}\">"
                . "\n\t\t\t\t\t\t\t\t\t\t  <div class=\"conzent-accordion-item\">"
                . "\n\t\t\t\t\t\t\t\t\t\t\t<div class=\"conzent-accordion-arrow\"><i class=\"cnz-arrow-right\"></i></div>"
                . "\n\t\t\t\t\t\t\t\t\t\t\t<div class=\"conzent-accordion-header-wrapper\">"
                . "\n\t\t\t\t\t\t\t\t\t\t\t  <div class=\"conzent-accordion-header\">"
                . "\n\t\t\t\t\t\t\t\t\t\t\t\t<button class=\"conzent-accordion-btn\" data-cookie-category=\"{$slug}\" style=\"color:[notice_description_color]!important;\">[conzent_preference_{$slug}_title]</button>"
                . "\n\t\t\t\t\t\t\t\t\t\t\t\t{$btnText}</div>"
                . "\n\t\t\t\t\t\t\t\t\t\t\t  <div class=\"conzent-accordion-header-des\" style=\"color:[notice_description_color]\">[conzent_preference_{$slug}_description]</div>"
                . "\n\t\t\t\t\t\t\t\t\t\t\t</div>"
                . "\n\t\t\t\t\t\t\t\t\t\t  </div>"
                . "\n\t\t\t\t\t\t\t\t\t\t  <div class=\"conzent-accordion-body\">"
                . "\n\t\t\t\t\t\t\t\t\t\t\t<div class=\"conzent-cookie-table\"></div>"
                . "\n\t\t\t\t\t\t\t\t\t\t  </div>"
                . "\n\t\t\t\t\t\t\t\t\t\t</div>";
        }
        return $html;
    }

    private function replaceHtmlPlaceholders(
        string $html,
        array $notice,
        string $cookieLaws,
        string $categoryHtml,
        string $revisitButtonHtml,
        string $disableBranding,
        string $globalPrivacyPolicy,
        string $googlePrivacyPolicy,
        string $doNotSellCheckbox,
        array $preferenceCenter = [],
    ): string {
        // Render buttons HTML
        $noticeButtons = $this->renderButtonsHtml($notice['buttons'] ?? []);

        // Privacy link
        if (($notice['cookie_policy']['status'] ?? 0) === 1) {
            $privacyLink = '<a href="' . ($notice['cookie_policy']['link'] ?? '') . '" rel="nofollow" target="_blank" class="cnz-privacy-policy">' . ($notice['cookie_policy']['label'] ?? '') . '</a>';
            $html = str_replace('[privacy_policy_link]', $privacyLink, $html);
        } else {
            $html = str_replace('[privacy_policy_link]', '', $html);
        }

        $html = str_replace('[button_wrap]', $noticeButtons, $html);
        $prefButtons = $this->renderButtonsHtml($preferenceCenter['buttons'] ?? []);
        $html = str_replace('[preference_button_wrap]', $prefButtons, $html);
        $html = str_replace('[opt_center_button_wrap]', '', $html);
        $html = str_replace('[opt_notice_button_wrap]', $this->renderDoNotSellHtml($notice['do_not_sell_link'] ?? []), $html);

        if ($cookieLaws === 'ccpa') {
            $html = str_replace('[conzent_cookie_categories]', '', $html);
        } else {
            $html = str_replace('[conzent_cookie_categories]', $categoryHtml, $html);
        }

        $html = str_replace('[conzent_revisit_html]', $revisitButtonHtml, $html);
        $html = str_replace('[conzent_branding]', $disableBranding, $html);
        $html = str_replace('[global_privacy_policy]', $globalPrivacyPolicy, $html);
        $html = str_replace('[google_privacy_policy]', $googlePrivacyPolicy !== '' ? "<div class='dma-content'>" . $googlePrivacyPolicy . "</div>" : '', $html);
        $html = str_replace('[do_not_sell_checkbox]', $doNotSellCheckbox, $html);

        // Color replacements
        $html = str_replace('[notice_background_color]', $notice['banner']['style']['background'] ?? '', $html);
        $html = str_replace('[notice_border_color]', $notice['banner']['style']['border'] ?? '', $html);
        $html = str_replace('[notice_title_color]', $notice['banner']['style']['title'] ?? '', $html);
        $html = str_replace('[notice_description_color]', $notice['banner']['style']['message'] ?? '', $html);

        // Remove line breaks
        $html = str_replace("\r\n", '', $html);

        return $html;
    }

    private function renderButtonsHtml(array $buttons): string
    {
        $html = '';
        foreach ($buttons as $val) {
            if (($val['status'] ?? 0) !== 1) {
                continue;
            }
            $style = $this->buildButtonStyle($val['style'] ?? []);

            if ($val['type'] === 'button') {
                $html .= '<button id="' . $val['tagId'] . '" style="' . $style . '" class="cnz-btn btn-' . $val['tagId'] . '">' . $val['label'] . '</button>';
            } else {
                $lnk = $val['link'] === '' ? 'javascript:void(0);' : $val['link'];
                $html .= '<a href="' . $lnk . '" id="' . $val['tagId'] . '" rel="nofollow" style="' . $style . '" class="cnz-btn btn-' . $val['tagId'] . '">' . $val['label'] . '</a>';
            }
        }
        return $html;
    }

    private function renderDoNotSellHtml(array $links): string
    {
        $html = '';
        foreach ($links as $val) {
            if (($val['status'] ?? 0) !== 1) {
                continue;
            }
            $style = $this->buildButtonStyle($val['style'] ?? []);
            $lnk = ($val['link'] ?? '') === '' ? 'javascript:void(0);' : $val['link'];

            if ($val['type'] === 'button') {
                $html .= '<button id="' . $val['tagId'] . '" style="' . $style . '" class="cnz-btn btn-' . $val['tagId'] . '">' . $val['label'] . '</button>';
            } else {
                $html .= '<a href="' . $lnk . '" id="' . $val['tagId'] . '" rel="nofollow" style="' . $style . '" class="cnz-btn btn-' . $val['tagId'] . '">' . $val['label'] . '</a>';
            }
        }
        return $html;
    }

    private function buildButtonStyle(array $style): string
    {
        $css = '';
        if (($style['background'] ?? '') !== '') {
            $css .= 'background-color:' . $style['background'] . '!important;';
        }
        if (($style['border'] ?? '') !== '') {
            $css .= 'border:1px solid ' . $style['border'] . '!important;';
        }
        if (($style['text'] ?? '') !== '') {
            $css .= 'color:' . $style['text'] . '!important;';
        }
        return $css;
    }

    // ═══════════════════════════════════════════════════════════
    //  UTILITY METHODS
    // ═══════════════════════════════════════════════════════════

    private function ensureOutputDir(string $websiteKey): void
    {
        $dir = $this->outputPath . '/' . $websiteKey;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // Ensure directory and existing files are writable.
        // Fixes CLI (root) vs FPM (www-data) ownership mismatch.
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
        }
        $scriptFile = $dir . '/script.js';
        if (is_file($scriptFile) && !is_writable($scriptFile)) {
            @chmod($scriptFile, 0666);
        }
    }

    private function minifyScript(string $path): void
    {
        if (!class_exists(\MatthiasMullie\Minify\JS::class)) {
            $this->logger->warning('MatthiasMullie\Minify not available — script not minified');
            return;
        }

        // Minify in memory then write with file_put_contents
        // (avoids the library's strict file permission checks)
        $minifier = new \MatthiasMullie\Minify\JS($path);
        $minified = $minifier->minify();
        file_put_contents($path, $minified);
    }

    private function checkFeature(string $featureSlug, array $features): bool
    {
        // OCI edition: when no plan features are configured, all features are unlocked
        if ($features === []) {
            return true;
        }

        foreach ($features as $f) {
            if (($f['feature_key'] ?? $f['slug'] ?? $f['feature_slug'] ?? '') === $featureSlug) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure a URL has a protocol prefix so the browser doesn't treat it as a relative path.
     */
    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Already has a protocol or is a relative path starting with /
        if (preg_match('#^https?://#i', $url) || str_starts_with($url, '/')) {
            return $url;
        }
        return 'https://' . $url;
    }

    private function getDomainOnly(string $domain): string
    {
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = rtrim($domain, '/');
        $parts = explode('.', $domain);
        if (\count($parts) > 2) {
            return implode('.', \array_slice($parts, -2));
        }
        return $domain;
    }

    /**
     * Write a version file alongside the script for cache-busting.
     *
     * The version file contains a short hash of the script content.
     * Clients load script.js?v={hash} to bypass browser and CDN caches.
     */
    /** @param list<string> $allowedScripts User-defined script whitelist patterns */
    private function writeVersionFile(string $websiteKey, string $scriptPath, bool $cacheDisabled = false, array $allowedScripts = []): void
    {
        if (!file_exists($scriptPath)) {
            return;
        }

        // When cache is disabled, use a unique timestamp so every browser load
        // fetches a fresh script (even if content hasn't changed).
        $hash = $cacheDisabled
            ? (string) hrtime(true)
            : substr(md5_file($scriptPath) ?: '', 0, 10);

        $versionPath = $this->outputPath . '/' . $websiteKey . '/version.json';
        $versionData = [
            'v' => $hash,
            't' => time(),
        ];

        // Include user-defined whitelist so the early blocker can skip them
        if ($allowedScripts !== []) {
            $versionData['a'] = $allowedScripts;
        }

        @file_put_contents($versionPath, json_encode($versionData));
    }

    /**
     * Get the versioned script URL for a site.
     */
    public function getScriptUrl(string $websiteKey): string
    {
        $base = $this->webRoot . 'sites_data/' . $websiteKey . '/script.js';
        $versionPath = $this->outputPath . '/' . $websiteKey . '/version.json';

        if (file_exists($versionPath)) {
            $data = json_decode((string) file_get_contents($versionPath), true);
            if (isset($data['v'])) {
                return $base . '?v=' . $data['v'];
            }
        }

        return $base;
    }

    /**
     * Mark a site's version.json as exceeded (pageview limit reached).
     * Clears the script so the banner stops showing.
     */
    public function markSiteExceeded(string $websiteKey): void
    {
        $dir = $this->outputPath . '/' . $websiteKey;
        if (!is_dir($dir)) {
            return;
        }

        $scriptPath = $dir . '/script.js';
        $versionPath = $dir . '/version.json';

        @file_put_contents($scriptPath, '');
        $versionData = ['v' => '', 't' => time(), 'x' => true];
        @file_put_contents($versionPath, json_encode($versionData));
    }

    /**
     * Clear the exceeded flag from a site's version.json and regenerate the script.
     */
    public function clearSiteExceeded(int $siteId): void
    {
        $this->generate($siteId);
    }

    /**
     * Build the banner config array from decoded options and shared context.
     *
     * Extracted from the main generate() loop to enable reuse for A/B variant
     * config pre-rendering. All parameters that don't change between variants
     * are passed in $ctx.
     *
     * @return array{configArray: array, geoTarget: string, isIab: int}
     */
    private function buildBannerConfig(array $optionsVal, array $bannerRow, array $ctx): array
    {
        $cookieLaws = $ctx['cookieLaws'];
        $consentColorConfig = $ctx['consentColorConfig'];
        $planFeatures = $ctx['planFeatures'];
        $siteLogo = $ctx['siteLogo'];
        $iconLogo = $ctx['iconLogo'];
        $siteDomain = $ctx['siteDomain'];
        $cookieTypes = $ctx['cookieTypes'];
        $translations = $ctx['translations'];
        $iabTranslations = $ctx['iabTranslations'];
        $cookies = $ctx['cookies'];
        $beacons = $ctx['beacons'];
        $necessaryCookies = $ctx['necessaryCookies'];
        $allowTagFire = $ctx['allowTagFire'];
        $supportGcm = $ctx['supportGcm'];
        $supportMetaConsent = $ctx['supportMetaConsent'];
        $supportUet = $ctx['supportUet'] ?? 1;
        $supportClarity = $ctx['supportClarity'] ?? 0;
        $supportAmazonConsent = $ctx['supportAmazonConsent'] ?? 0;
        $gtmContainerId = $ctx['gtmContainerId'] ?? '';
        $gtmDataLayer = $ctx['gtmDataLayer'] ?? 'dataLayer';
        $renewConsent = $ctx['renewConsent'];
        $bannerDelay = $ctx['bannerDelay'];
        $websiteKey = $ctx['websiteKey'];
        $policyList = $ctx['policyList'];
        $rootDomain = $ctx['rootDomain'];
        $siteId = $ctx['siteId'];
        $userBannerId = $ctx['userBannerId'];

        // ── Color/theme settings ───────────────────────
        $themeMode = $optionsVal['color_theme'] ?? 'dark';
        $colorTheme = $themeMode;
        if ($themeMode === 'custom') {
            $themeMode = $optionsVal['base_theme'] ?? 'dark';
        }

        $generalSetting = $optionsVal['general'] ?? [];
        $layoutSetting = $optionsVal['layout'] ?? [];
        $contentSetting = $optionsVal['content'] ?? [];
        $colorSetting = $optionsVal['colors'] ?? [];

        // Normalise flat layout/content/color settings to nested structures
        $layoutSetting = $this->normaliseLayoutSetting($layoutSetting);
        $optionsVal['layout'] = $layoutSetting;
        $contentSetting = $this->normaliseContentSetting($contentSetting, $cookieLaws);
        $colorSetting = $this->normaliseColorSetting($colorSetting);

        if (\array_key_exists('google_additional_consent', $generalSetting)) {
            $generalSetting['additional_gcm'] = $generalSetting['google_additional_consent'];
            unset($generalSetting['google_additional_consent']);
        }

        $defaultColors = $consentColorConfig['option_value'] ?? [];
        $customCss = $optionsVal['custom_css'] ?? '';
        $notice = [];
        $categoryOnFirstLayer = isset($layoutSetting['notice']['category_on_first_layer']) ? 1 : 0;

        // Process color settings
        // Strip opposite theme from defaults (keep only the active theme mode)
        foreach ($defaultColors as $ckey => $cVal) {
            if (\is_array($cVal)) {
                if ($themeMode === 'light') {
                    unset($defaultColors[$ckey]['dark']);
                } else {
                    unset($defaultColors[$ckey]['light']);
                }
            }
        }

        if ($colorTheme === 'custom') {
            $colorSetting = $colorSetting['custom'][$cookieLaws] ?? $colorSetting;
        }

        // Merge: start with template defaults, overlay user's color settings on top
        if (!empty($defaultColors)) {
            foreach ($defaultColors as $section => $themes) {
                if (!\is_array($themes)) {
                    continue;
                }
                foreach ($themes as $theme => $elements) {
                    if (!\is_array($elements)) {
                        continue;
                    }
                    foreach ($elements as $element => $fields) {
                        if (!\is_array($fields)) {
                            continue;
                        }
                        foreach ($fields as $field => $value) {
                            // User's setting takes precedence; fill missing from defaults
                            if (!isset($colorSetting[$section][$theme][$element][$field])) {
                                $colorSetting[$section][$theme][$element][$field] = $value;
                            }
                        }
                    }
                }
            }
        }

        // Always fill missing color values from the built-in default palette
        $fallback = self::defaultColorPalette();
        foreach ($fallback as $section => $themes) {
            foreach ($themes as $theme => $elements) {
                foreach ($elements as $element => $fields) {
                    foreach ($fields as $field => $value) {
                        if (!isset($colorSetting[$section][$theme][$element][$field])
                            || $colorSetting[$section][$theme][$element][$field] === '') {
                            $colorSetting[$section][$theme][$element][$field] = $value;
                        }
                    }
                }
            }
        }

        // General settings defaults
        $generalSetting['iab_support'] = (int) ($generalSetting['iab_support'] ?? 0);
        if ($generalSetting['iab_support'] === 1 && $cookieLaws === 'ccpa') {
            $generalSetting['iab_support'] = 0;
        }
        $generalSetting['additional_gcm'] = (int) ($generalSetting['additional_gcm'] ?? 0);
        $generalSetting['reload_on'] = (int) ($generalSetting['reload_on'] ?? 0);
        $generalSetting['show_banner'] = (int) ($generalSetting['show_banner'] ?? 0);
        if ($cookieLaws === 'gdpr') {
            $generalSetting['show_banner'] = 1;
        }
        $generalSetting['geo_target_selected'] = $generalSetting['geo_target_selected'] ?? [];

        $geoTarget = $generalSetting['geo_target'] ?? '';

        $generalOption = [
            'geo_target' => $generalSetting['geo_target'] ?? '',
            'geo_target_selected' => $generalSetting['geo_target_selected'],
            'iab_support' => $generalSetting['iab_support'],
            'google_consent' => $supportGcm,
            'meta_consent' => $supportMetaConsent,
            'ms_consent' => (int) $supportUet,
            'clarity_consent' => (int) $supportClarity,
            'amazon_consent' => (int) $supportAmazonConsent,
            'gtm_id' => $gtmContainerId,
            'gtm_dl' => $gtmDataLayer,
            'allow_tag_fire' => $allowTagFire,
            'additional_gcm' => $generalSetting['additional_gcm'],
            'show_banner' => $generalSetting['show_banner'],
            'expires' => $generalSetting['consent_expiration'] ?? 365,
            'reload_on' => $generalSetting['reload_on'],
            'renew_consent' => $renewConsent,
        ];

        // ── Build notice/preference/opt-out structures ─
        $preferenceCenter = [];
        $revisitConsentButton = [];
        $alttextBlockedContent = [];
        $optOutCenter = [];
        $revisitButtonHtml = '';
        $disableBranding = '';
        $showCookieOnBanner = 0;
        $noticeCustomLogo = '';
        $revisitCustomIcon = '';
        $showMoreText = '';
        $showLessText = '';
        $showRespectGpc = 0;
        $googlePrivacyPolicy = '';
        $globalPrivacyPolicy = '';
        $doNotSellCheckbox = '';

        if ($cookieLaws === 'gdpr' || $cookieLaws === 'gdpr_ccpa') {
            $this->buildGdprNotice(
                $notice, $preferenceCenter, $revisitConsentButton, $alttextBlockedContent,
                $contentSetting, $colorSetting, $themeMode, $planFeatures,
                $siteLogo, $iconLogo, $siteDomain, $showMoreText, $showLessText,
                $showCookieOnBanner, $googlePrivacyPolicy, $disableBranding,
                $revisitButtonHtml, $noticeCustomLogo, $revisitCustomIcon,
                $categoryOnFirstLayer
            );
        }

        if ($cookieLaws === 'ccpa' || $cookieLaws === 'gdpr_ccpa') {
            $this->buildCcpaNotice(
                $notice, $optOutCenter, $revisitConsentButton, $alttextBlockedContent,
                $contentSetting, $colorSetting, $themeMode, $planFeatures,
                $siteLogo, $iconLogo, $siteDomain, $showMoreText, $showLessText,
                $showCookieOnBanner, $showRespectGpc, $globalPrivacyPolicy,
                $doNotSellCheckbox, $disableBranding, $revisitButtonHtml,
                $noticeCustomLogo, $revisitCustomIcon
            );
        }

        // ── CSS ────────────────────────────────────────
        $cssContent = (string) file_get_contents($this->resourcePath . '/css/banner.css');
        $cssContent = str_replace('/app/css/', $this->webRoot . 'css/', $cssContent);

        $additionalCss = '';
        if (isset($preferenceCenter['toggle_switch'])) {
            $enabled = $preferenceCenter['toggle_switch']['style']['enabled_state'] ?? '';
            $disabled = $preferenceCenter['toggle_switch']['style']['disabled_state'] ?? '';
            $additionalCss .= '.conzent-modal .sliding-switch, .conzent-preference .sliding-switch,.cnz-cookie-table .sliding-switch,.conzent-switch .sliding-switch{background:' . $disabled . ';}';
            $additionalCss .= '.conzent-modal .sliding-switch:checked, .conzent-preference .sliding-switch:checked,.cnz-cookie-table .sliding-switch:checked,.conzent-switch .sliding-switch:checked{background:' . $enabled . '!important;}';
        }
        if (isset($optOutCenter['checkbox'])) {
            $enabled = $optOutCenter['checkbox']['style']['enabled_state'] ?? '';
            $disabled = $optOutCenter['checkbox']['style']['disabled_state'] ?? '';
            $additionalCss .= '.conzent-modal .opt-checkbox, .conzent-preference .opt-checkbox,.cnz-cookie-table .opt-checkbox{background:' . $disabled . ';}';
            $additionalCss .= '.conzent-modal .opt-checkbox:checked, .conzent-preference .opt-checkbox:checked,.cnz-cookie-table .opt-checkbox:checked,.cnz-cookie-table .sliding-switch:checked{background:' . $enabled . ';}';
            $additionalCss .= '.cnz-custom-checkbox{background:' . $disabled . ';}';
            $additionalCss .= '.cnz-custom-checkbox:checked{background:' . $enabled . ';}';
        }
        $cssContent .= $additionalCss . $customCss;

        // ── Allowed cookies/scripts ────────────────────
        $allowedDefaultCookies = [
            'conzentConsentPrefs', 'conzentConsent', 'conzent_id',
            'lastRenewedDate', 'euconsent',
        ];
        $allowedCookies = array_merge($necessaryCookies, $allowedDefaultCookies);
        $allowedScripts = [
            'jquery.min.js', 'shopify.js', 'jquery.js',
            'sites_data/' . $websiteKey . '/script.js',
        ];

        // Merge user-defined script whitelist
        $userAllowedRaw = (string) ($site['allowed_scripts'] ?? '');
        if ($userAllowedRaw !== '') {
            $userAllowed = json_decode($userAllowedRaw, true);
            if (\is_array($userAllowed)) {
                $allowedScripts = array_values(array_unique(array_merge($allowedScripts, array_filter($userAllowed))));
            }
        }

        // ── HTML template ──────────────────────────────
        $htmlContent = $this->loadHtmlTemplate($cookieLaws, $generalOption, $bannerRow, $siteId);
        $isIab = 0;
        if ($generalOption['iab_support'] === 1 && $cookieLaws === 'gdpr') {
            $isIab = 1;
            $translations = array_merge($iabTranslations, $translations);
        }

        // Build category accordion HTML
        $categoryHtml = $this->buildCategoryHtml($cookieTypes);

        // Replace HTML template placeholders
        $htmlContent = $this->replaceHtmlPlaceholders(
            $htmlContent, $notice, $cookieLaws, $categoryHtml,
            $revisitButtonHtml, $disableBranding, $globalPrivacyPolicy,
            $googlePrivacyPolicy, $doNotSellCheckbox, $preferenceCenter
        );

        // ── Build config array ─────────────────────────
        $themeSetting = [
            'primaryColor' => '#115cfa',
            'darkColor' => '#2d2d2d',
            'lightColor' => '#ffffff',
            'themeMode' => 'dark',
        ];

        $configArray = [
            'banner_id' => $userBannerId,
            'default_laws' => $cookieLaws,
            'show_more_button' => $showMoreText,
            'show_less_button' => $showLessText,
            'show_cookie_on_banner' => $showCookieOnBanner,
            'themeSettings' => $themeSetting,
            'allowed_categories' => ['necessary'],
            'fullWidth' => false,
            'allCheckboxesChecked' => false,
            'blockUnspecifiedCookies' => true,
            'blockUnspecifiedBeacons' => true,
            'logoType' => 1,
            'delay' => $bannerDelay,
            'cookieTypes' => $cookieTypes,
            'shortCodes' => $translations,
            'cookiesList' => $cookies,
            'beaconsList' => $beacons,
            'allowed_scripts' => $allowedScripts,
            'allowed_cookies' => $allowedCookies,
            'logoUrl' => $noticeCustomLogo,
            'revisit_logo' => $revisitCustomIcon,
            'logoEmbedded' => '',
            'css_content' => '<style id="conzentCss">' . str_replace("\r\n", '', $cssContent) . '</style>',
            'html' => $htmlContent,
            'cookie_audit_table' => $categoryHtml,
            'cookie_policy_html' => $this->loadPolicyContent($siteId, 'cookie'),
            'privacy_policy_html' => $this->loadPolicyContent($siteId, 'privacy'),
            'category_on_first_layer' => $categoryOnFirstLayer,
            'allowedVendors' => [],
            'allowedGoogleVendors' => [],
            'default_logo' => $this->webRoot . 'media/revisit_icon.png',
            'allow_gpc' => $showRespectGpc,
            '_root_domain' => $rootDomain,
            'policy_list' => $policyList,
        ];

        // Banner type & position
        $configArray['banner_type'] = $optionsVal['layout']['cookie_notice']['display_mode'] ?? 'popup';
        if (isset($optionsVal['layout']['cookie_notice']['position'])) {
            $configArray['banner_position'] = '-' . str_replace('_', '-', $optionsVal['layout']['cookie_notice']['position']);
        } else {
            $configArray['banner_position'] = $configArray['banner_type'] === 'banner' ? '-bottom' : '-bottom-left';
        }
        if ($configArray['banner_type'] === 'popup') {
            $configArray['banner_position'] = '-center';
        }

        // Preference position & type
        $configArray['preference_type'] = $optionsVal['layout']['preference']['display_mode'] ?? 'center';
        $configArray['preference_position'] = isset($optionsVal['layout']['preference']['position'])
            ? '-' . str_replace('_', '-', $optionsVal['layout']['preference']['position'])
            : '';
        if ($configArray['preference_type'] === 'sidebar' && $configArray['preference_position'] === '') {
            $configArray['preference_position'] = '-right';
        }

        if ($cookieLaws === 'ccpa') {
            $configArray['preference_position'] = isset($optionsVal['layout']['opt_out_center']['position'])
                ? '-' . str_replace('_', '-', $optionsVal['layout']['opt_out_center']['position'])
                : '';
            $configArray['preference_type'] = $optionsVal['layout']['opt_out_center']['display_mode'] ?? 'center';
        }

        $configArray = array_merge($generalOption, $configArray);

        return [
            'configArray' => $configArray,
            'geoTarget' => $geoTarget,
            'isIab' => $isIab,
        ];
    }

    /**
     * Load A/B variant configs for a running experiment on this site.
     * Pre-renders each variant's config by calling buildBannerConfig() and
     * computing a delta against the control config.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadABVariants(
        int $siteId,
        array $controlConfig = [],
        array $controlOptionsVal = [],
        array $controlBannerRow = [],
        array $ctx = [],
    ): array {
        try {
            $experiment = $this->db->fetchAssociative(
                'SELECT id FROM oci_ab_experiments WHERE site_id = :siteId AND status = :status',
                ['siteId' => $siteId, 'status' => 'running'],
            );
        } catch (\Throwable) {
            // Table may not exist in OCI/community edition
            return [];
        }

        if ($experiment === false) {
            return [];
        }

        $variants = $this->db->fetchAllAssociative(
            'SELECT id, variant_key, variant_name, weight, is_control,
                    general_setting, layout_setting, content_setting, color_setting,
                    layout_key, custom_layout_id
             FROM oci_ab_variants
             WHERE experiment_id = :expId
             ORDER BY is_control DESC, id ASC',
            ['expId' => (int) $experiment['id']],
        );

        // If no context provided, return basic variant data
        if (empty($ctx)) {
            $configs = [];
            foreach ($variants as $v) {
                $configs[] = [
                    'id' => (int) $v['id'],
                    'key' => $v['variant_key'],
                    'w' => (int) $v['weight'],
                ];
            }

            return $configs;
        }

        $configs = [];
        foreach ($variants as $v) {
            $isControl = (int) $v['is_control'] === 1;

            if ($isControl) {
                // Control variant — no config delta needed
                $configs[] = [
                    'id' => (int) $v['id'],
                    'key' => $v['variant_key'],
                    'w' => (int) $v['weight'],
                    'cfg' => null,
                ];
                continue;
            }

            // Build merged optionsVal for this variant
            $variantOptionsVal = $controlOptionsVal;
            if ($v['general_setting'] !== null) {
                $variantOptionsVal['general'] = json_decode($v['general_setting'], true) ?: [];
            }
            if ($v['layout_setting'] !== null) {
                $variantOptionsVal['layout'] = json_decode($v['layout_setting'], true) ?: [];
            }
            if ($v['content_setting'] !== null) {
                $variantOptionsVal['content'] = json_decode($v['content_setting'], true) ?: [];
            }
            if ($v['color_setting'] !== null) {
                $variantOptionsVal['colors'] = json_decode($v['color_setting'], true) ?: [];
            }

            // Build variant banner row (for layout key override)
            $variantBannerRow = $controlBannerRow;
            if ($v['layout_key'] !== null) {
                $variantBannerRow['layout_key'] = $v['layout_key'];
            }
            if ($v['custom_layout_id'] !== null) {
                $variantBannerRow['custom_layout_id'] = $v['custom_layout_id'];
            }

            // Build full config for this variant
            $variantResult = $this->buildBannerConfig($variantOptionsVal, $variantBannerRow, $ctx);
            $variantConfig = $variantResult['configArray'];

            // Compute delta: only include fields that differ from control
            $delta = [];
            $diffKeys = [
                'banner_type', 'banner_position', 'preference_type', 'preference_position',
                'html', 'css_content', 'cookie_audit_table', 'cookie_policy_html', 'privacy_policy_html', 'category_on_first_layer',
                'show_more_button', 'show_less_button', 'show_cookie_on_banner',
                'shortCodes', 'logoUrl', 'revisit_logo', 'allow_gpc',
                'geo_target', 'geo_target_selected', 'iab_support', 'show_banner',
                'expires', 'reload_on', 'additional_gcm',
            ];
            foreach ($diffKeys as $key) {
                if (isset($variantConfig[$key]) && isset($controlConfig[$key])
                    && $variantConfig[$key] !== $controlConfig[$key]) {
                    $delta[$key] = $variantConfig[$key];
                } elseif (isset($variantConfig[$key]) && !isset($controlConfig[$key])) {
                    $delta[$key] = $variantConfig[$key];
                }
            }

            $configs[] = [
                'id' => (int) $v['id'],
                'key' => $v['variant_key'],
                'w' => (int) $v['weight'],
                'cfg' => !empty($delta) ? $delta : null,
            ];
        }

        return $configs;
    }
}
