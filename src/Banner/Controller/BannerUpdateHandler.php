<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
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
                'gtm_container_id', 'gtm_data_layer', 'disable_on_pages',
            ];

            foreach ($allowedSiteFields as $field) {
                if (array_key_exists($field, $body['site_settings'])) {
                    $siteData[$field] = $body['site_settings'][$field];
                }
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
            $this->bannerRepo->updateBannerSetting($bannerId, $data);
        }

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
            newValues: ['sections' => $changedSections, 'site_id' => $siteId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $response = [
            'message' => 'Banner settings saved',
            'script_url' => $scriptUrl,
        ];

        if (!$scriptGenerated) {
            $response['warning'] = 'Settings saved but script regeneration failed.' .
                ($scriptError !== '' ? ' Error: ' . $scriptError : ' Check server logs.');
        }

        return ApiResponse::success($response);
    }
}
