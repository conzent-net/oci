<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\BannerContent;
use OCI\Banner\Service\BannerImageUrl;
use OCI\Banner\Service\BrandingPolicy;
use OCI\Banner\Service\ColorValue;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /app/banners/{id} — Update banner settings.
 *
 * Accepts JSON body with any combination of:
 *   general_setting, layout_setting, content_setting, color_setting
 * Each is stored as a JSON-encoded string in the oci_site_banners table.
 *
 * Mirrors legacy: action.php → update_banner_setting
 */
final class BannerUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly AuditLogService $auditLogService,
        private readonly BrandingPolicy $brandingPolicy,
        private readonly ?PricingService $pricingService = null,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) $user['id'];
        $bannerId = (int) $request->getAttribute('id');

        if ($bannerId <= 0) {
            return ApiResponse::error('Invalid banner ID', 400);
        }

        $body = (array) $request->getParsedBody();

        $siteId = (int) ($body['site_id'] ?? 0);
        if ($siteId <= 0) {
            return ApiResponse::error('site_id is required', 422);
        }

        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::error('Site not found', 404);
        }

        // Build update data from JSON setting sections
        $data = [];
        $settingSections = ['general_setting', 'layout_setting', 'content_setting', 'color_setting'];

        // Strip disable_branding unless this account is entitled to remove it —
        // by plan, or by being inside an approved agency's book. One predicate,
        // shared with the banner list page and script generation, so the toggle
        // a customer is shown and the branding they actually get can never
        // disagree.
        if (isset($body['content_setting']) && is_array($body['content_setting'])) {
            if (!$this->brandingPolicy->canRemoveBranding($userId)) {
                unset($body['content_setting']['disable_branding']);
            }
        }

        // The logo and revisit icon links end up in <img src='…'>; one that
        // could break out of it is refused, so the page can say why instead of
        // the link silently disappearing.
        if (isset($body['content_setting']) && is_array($body['content_setting'])) {
            foreach ([BannerImageUrl::LOGO => 'Logo URL', BannerImageUrl::REVISIT_ICON => 'Revisit icon URL'] as $key => $label) {
                if (!array_key_exists($key, $body['content_setting'])) {
                    continue;
                }
                $url = is_string($body['content_setting'][$key]) ? trim($body['content_setting'][$key]) : '';
                if (!BannerImageUrl::isSafe($url)) {
                    return ApiResponse::error($label . ' is not a usable image link', 422, [$key => 'Use a link to an image, without spaces, quotes or brackets.']);
                }
                $body['content_setting'][$key] = $url;
            }
        }

        // A site with two laws has one banner row per law. The page edits the
        // row in the URL, or every row of the site when it is set to both.
        $applyToAll = ($body['apply_to'] ?? 'row') === 'all';
        $targets = [];
        foreach ($this->bannerRepo->getSiteBannerSettings($siteId) as $row) {
            if ($applyToAll || (int) ($row['id'] ?? 0) === $bannerId) {
                $targets[] = $row;
            }
        }
        if ($targets === []) {
            $targets[] = ['id' => $bannerId, 'content_setting' => ''];
        }

        // Colours end up unescaped in the banner's markup and stylesheet; only
        // real colour values are stored.
        if (isset($body['color_setting']) && is_array($body['color_setting'])) {
            $body['color_setting'] = ColorValue::sanitizeTree($body['color_setting']);
        }

        foreach ($settingSections as $section) {
            if (isset($body[$section]) && is_array($body[$section])) {
                $data[$section] = json_encode($body[$section], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }

        // custom_css is stored as a plain string column, not JSON-encoded
        if (isset($body['custom_css']) && is_string($body['custom_css'])) {
            $data['custom_css'] = $body['custom_css'];
        }

        // Layout selection
        if (array_key_exists('layout_key', $body)) {
            $data['layout_key'] = $body['layout_key'];
        }
        if (array_key_exists('custom_layout_id', $body)) {
            $data['custom_layout_id'] = $body['custom_layout_id'];
        }

        // Save site-level settings (advanced section)
        if (isset($body['site_settings']) && is_array($body['site_settings'])) {
            $siteData = [];
            $allowedSiteFields = [
                'block_iframe', 'banner_delay_ms', 'include_all_languages',
                'tag_fire_enabled', 'gcm_enabled', 'meta_consent_enabled', 'uet_enabled',
                'clarity_enabled', 'amazon_consent_enabled',
                'gtm_container_id', 'gtm_data_layer', 'disable_on_pages', 'allowed_scripts',
                'cookie_samesite', 'advertiser_consent_mode',
            ];

            foreach ($allowedSiteFields as $field) {
                if (array_key_exists($field, $body['site_settings'])) {
                    $siteData[$field] = $body['site_settings'][$field];
                }
            }

            if (isset($siteData['cookie_samesite'])) {
                $sameSite = strtolower((string) $siteData['cookie_samesite']);
                $siteData['cookie_samesite'] = \in_array($sameSite, ['lax', 'strict', 'none'], true) ? $sameSite : 'lax';
            }

            // Handle renew_user_consent action
            if (!empty($body['site_settings']['renew_user_consent'])) {
                $siteData['renew_user_consent_at'] = date('Y-m-d H:i:s');
            }

            if ($siteData !== []) {
                $this->siteRepo->updateSiteSettings($siteId, $siteData);
            }
        }

        if ($data === [] && !isset($body['site_settings'])) {
            return ApiResponse::error('No settings to update', 422);
        }

        if ($data !== []) {
            foreach ($targets as $row) {
                $rowData = $data;

                // The page posts only the settings it shows, flat. A banner
                // imported from v1 stores far more than that, nested, so a bare
                // replace dropped everything the page does not show — including
                // whether Accept all and Reject all are on. Each row is merged
                // with its OWN stored content, so saving to both laws at once
                // cannot carry one banner's settings onto the other.
                if (isset($body['content_setting']) && is_array($body['content_setting'])) {
                    $stored = json_decode((string) ($row['content_setting'] ?? ''), true) ?: [];
                    $rowData['content_setting'] = json_encode(
                        $stored === [] ? $body['content_setting'] : BannerContent::merge($body['content_setting'], $stored),
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    );
                }

                $this->bannerRepo->updateBannerSetting((int) $row['id'], $rowData);
            }
        }

        // A template's badge is a compliance promise: if this save switched off
        // one of the settings the template guarantees, the site no longer runs
        // that template and its selection is cleared.
        $templateCleared = $this->clearTemplateIfNonCompliant($body, $siteId);

        // Regenerate the consent script after banner settings change
        $scriptError = '';
        try {
            $scriptGenerated = $this->scriptService->generate($siteId);
        } catch (\Throwable $e) {
            $scriptGenerated = false;
            $scriptError = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        }

        // Return versioned script URL so frontend can update embed code
        $websiteKey = $this->bannerRepo->getWebsiteKeyBySiteId($siteId);
        $scriptUrl = $websiteKey !== '' ? $this->scriptService->getScriptUrl($websiteKey) : '';

        // Audit log: banner settings updated
        $changedSections = array_keys($data);
        if (isset($body['site_settings'])) {
            $changedSections[] = 'site_settings';
        }
        $this->auditLogService->log(
            userId: $userId,
            action: 'update',
            entityType: 'Banner',
            entityId: $bannerId,
            newValues: [
                'sections' => $changedSections,
                'site_id' => $siteId,
                'banners' => array_map(static fn (array $row): int => (int) $row['id'], $targets),
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $response = [
            'message' => 'Banner settings saved',
            'script_url' => $scriptUrl,
        ];

        if ($templateCleared) {
            $response['template_cleared'] = true;
        }

        if (!$scriptGenerated) {
            $response['warning'] = 'Settings saved but script regeneration failed.' .
                ($scriptError !== '' ? ' Error: ' . $scriptError : ' Check server logs.');
        }

        return ApiResponse::success($response);
    }

    /**
     * Clear the applied template when this save removed one of its compliance
     * guarantees: accept all, reject all, the customize button, the cookie
     * policy link, Google Consent Mode — or, on a TCF template, TCF itself.
     *
     * Only settings PRESENT in the payload count (a partial save must never
     * invalidate), and cosmetic choices deliberately don't: branding, colors,
     * layout, and advanced integrations (Meta, UET, Clarity, ...) leave the
     * template's promise intact.
     *
     * @param array<string, mixed> $body
     */
    private function clearTemplateIfNonCompliant(array $body, int $siteId): bool
    {
        $site = $this->siteRepo->findById($siteId);
        $template = (string) ($site['template_applied'] ?? '');
        if ($template === '') {
            return false;
        }

        $content = \is_array($body['content_setting'] ?? null) ? $body['content_setting'] : [];
        $general = \is_array($body['general_setting'] ?? null) ? $body['general_setting'] : [];
        $siteSettings = \is_array($body['site_settings'] ?? null) ? $body['site_settings'] : [];

        $offInPayload = static function (array $section, string $key): bool {
            return \array_key_exists($key, $section) && !$section[$key];
        };

        $violated = $offInPayload($content, 'accept_all_button')
            || $offInPayload($content, 'reject_all_button')
            || $offInPayload($content, 'customize_button')
            || $offInPayload($content, 'cookie_policy_link')
            || $offInPayload($general, 'gcm_enabled')
            || $offInPayload($general, 'google_consent')
            || $offInPayload($siteSettings, 'gcm_enabled')
            || (str_contains($template, 'tcf') && $offInPayload($general, 'iab_support'));

        if (!$violated) {
            return false;
        }

        $this->siteRepo->updateSiteSettings($siteId, ['template_applied' => '']);

        return true;
    }
}
