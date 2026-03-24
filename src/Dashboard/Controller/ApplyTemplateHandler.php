<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * POST /app/dashboard/apply-template — Apply a compliance template.
 *
 * Sets banner general/content settings and site-level flags based on the
 * chosen template (basic, advanced, basic_tcf, advanced_tcf).
 *
 * Mirrors legacy: dashboard.php template wizard.
 */
final class ApplyTemplateHandler implements RequestHandlerInterface
{
    private const VALID_TEMPLATES = ['basic', 'advanced', 'basic_tcf', 'advanced_tcf'];

    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) $user['id'];
        $body = (array) $request->getParsedBody();

        $siteId = (int) ($body['site_id'] ?? 0);
        $template = (string) ($body['template'] ?? '');

        if ($siteId <= 0) {
            return ApiResponse::error('site_id is required', 422);
        }

        if (!\in_array($template, self::VALID_TEMPLATES, true)) {
            return ApiResponse::error('Invalid template. Must be one of: ' . implode(', ', self::VALID_TEMPLATES), 422);
        }

        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::error('Site not found', 404);
        }

        // Load site to get privacy_policy_url for cookie_policy_url field
        $site = $this->siteRepo->findById($siteId);

        // Get the site's GDPR banner record
        $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        if (empty($banners)) {
            return ApiResponse::error('No banner configured for this site', 422);
        }

        $banner = reset($banners);
        $bannerId = (int) $banner['id'];

        // Decode existing settings to merge (preserve any user customizations not covered by template)
        $existingGeneral = [];
        if (!empty($banner['general_setting'])) {
            $decoded = json_decode((string) $banner['general_setting'], true);
            if (\is_array($decoded)) {
                $existingGeneral = $decoded;
            }
        }

        $existingContent = [];
        if (!empty($banner['content_setting'])) {
            $decoded = json_decode((string) $banner['content_setting'], true);
            if (\is_array($decoded)) {
                $existingContent = $decoded;
            }
        }

        // Build template settings
        $isAdvanced = $template === 'advanced' || $template === 'advanced_tcf';
        $isTcf = $template === 'basic_tcf' || $template === 'advanced_tcf';

        // General settings
        $generalSettings = array_merge($existingGeneral, [
            'gcm_enabled' => true,
            'google_consent' => true,
            'iab_support' => $isTcf,
        ]);

        // Content settings — always store in nested format for script generation consistency.
        // Preserve user's existing google_privacy_url if set.
        $googlePrivacyUrl = $existingContent['google_privacy_url']
            ?? $existingContent['gdpr']['preference_center']['google_privacy_url']
            ?? 'https://business.safety.google/privacy';

        $contentSettings = [
            'accept_all_button' => true,
            'reject_all_button' => true,
            'customize_button' => true,
            'close_button' => false,
            'cookie_policy_link' => true,
            'floating_button' => true,
            'button_position' => $existingContent['button_position'] ?? 'right',
            'show_google_privacy_policy' => true,
            'google_privacy_url' => $googlePrivacyUrl,
            'gdpr' => [
                'cookie_notice' => [
                    'accept_all_button' => 1,
                    'reject_all_button' => 1,
                    'customize_button' => 1,
                    'cookie_policy_label' => 1,
                ],
                'revisit_consent_button' => [
                    'floating_button' => 1,
                ],
                'preference_center' => [
                    'show_google_privacy_policy' => 1,
                    'google_privacy_url' => $googlePrivacyUrl,
                ],
            ],
        ];

        // Update banner settings
        $bannerData = [
            'general_setting' => json_encode($generalSettings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'content_setting' => json_encode($contentSettings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        $this->bannerRepo->updateBannerSetting($bannerId, $bannerData);

        // Update site-level settings (include banner_delay_ms default)
        $siteData = [
            'template_applied' => $template,
            'gcm_enabled' => 1,
            'tag_fire_enabled' => $isAdvanced ? 1 : 0,
            'block_iframe' => 0, // 0 = Only YouTube
            'banner_delay_ms' => 100,
        ];
        $this->siteRepo->updateSiteSettings($siteId, $siteData);

        // Set cookie_policy_url in banner field translations if site has a privacy policy
        $privacyPolicyUrl = (string) ($site['privacy_policy_url'] ?? '');
        if ($privacyPolicyUrl !== '') {
            $this->bannerRepo->setCookiePolicyUrl($bannerId, $siteId, $privacyPolicyUrl);
        }

        // Regenerate consent script
        $scriptGenerated = false;
        $scriptError = null;
        try {
            $scriptGenerated = $this->scriptService->generate($siteId);
        } catch (\Throwable $e) {
            $scriptError = $e->getMessage();
            $this->logger->error('Script generation failed after template apply', [
                'site_id' => $siteId,
                'template' => $template,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        if (!$scriptGenerated && $scriptError === null) {
            $scriptError = 'Script generation returned false — check site status and banner config';
            $this->logger->warning('Script generation returned false after template apply', [
                'site_id' => $siteId,
                'template' => $template,
            ]);
        }

        $response = [
            'message' => 'Template "' . $template . '" applied successfully',
            'template' => $template,
            'script_generated' => $scriptGenerated,
        ];

        if ($scriptError !== null) {
            $response['warning'] = 'Template applied but script generation failed: ' . $scriptError;
        }

        return ApiResponse::success($response);
    }
}
