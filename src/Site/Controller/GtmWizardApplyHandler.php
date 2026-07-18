<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use OCI\Site\Service\GtmWizardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/gtm/wizard/apply — Apply the GTM wizard configuration.
 *
 * Creates all tags, triggers, variables, and templates in the selected
 * GTM workspace based on the pixel configuration from Step 2.
 */
final class GtmWizardApplyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GtmOAuthService $gtmOAuth,
        private readonly GtmWizardService $wizardService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $token = $_SESSION['gtm_access_token'] ?? null;
        if ($token === null || ($_SESSION['gtm_token_expires'] ?? 0) <= time()) {
            return ApiResponse::error('GTM session expired. Please re-authenticate.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $accountId = (string) ($body['account_id'] ?? '');
        $containerId = (string) ($body['container_id'] ?? '');
        $workspaceId = (string) ($body['workspace_id'] ?? '');
        $websiteKey = (string) ($body['website_key'] ?? '');
        $pixels = (array) ($body['pixels'] ?? []);

        if ($accountId === '' || $containerId === '' || $workspaceId === '') {
            return ApiResponse::error('account_id, container_id, and workspace_id are required', 422);
        }

        if ($websiteKey === '') {
            return ApiResponse::error('website_key is required', 422);
        }

        $wsPath = $this->gtmOAuth->workspacePath($accountId, $containerId, $workspaceId);
        $results = [];

        // 1. Create foundation (built-in vars, Conzent Cookie, Conzent Trigger, Conzent CMP)
        $foundation = $this->wizardService->createFoundation($token, $wsPath, $websiteKey);
        $results[] = [
            'name' => 'Conzent CMP Foundation',
            'success' => $foundation['success'],
            'error' => $foundation['error'] ?? null,
        ];

        if (!$foundation['success']) {
            return ApiResponse::success(['results' => $results, 'completed' => false]);
        }

        // 2. Process each pixel if provided
        $ga = trim((string) ($pixels['google_analytics'] ?? ''));
        if ($ga !== '') {
            $results[] = $this->wizardService->createGoogleAnalytics($token, $wsPath, $ga);
        }

        $matomo = trim((string) ($pixels['matomo_analytics'] ?? ''));
        if ($matomo !== '') {
            $results[] = $this->wizardService->createMatomo($token, $wsPath, $matomo);
        }

        $adsId = trim((string) ($pixels['google_ads_conversion_id'] ?? ''));
        $adsLabel = trim((string) ($pixels['google_ads_conversion_label'] ?? ''));
        if ($adsId !== '') {
            $results[] = $this->wizardService->createGoogleAds($token, $wsPath, $adsId, $adsLabel);
        }

        $fb = trim((string) ($pixels['facebook_pixel'] ?? ''));
        if ($fb !== '') {
            $results[] = $this->wizardService->createFacebookPixel($token, $wsPath, $fb);
        }

        $clarity = trim((string) ($pixels['microsoft_clarity'] ?? ''));
        if ($clarity !== '') {
            $results[] = $this->wizardService->createMicrosoftClarity($token, $wsPath, $clarity);
        }

        $linkedin = trim((string) ($pixels['linkedin_insights'] ?? ''));
        if ($linkedin !== '') {
            $results[] = $this->wizardService->createLinkedInInsight($token, $wsPath, $linkedin);
        }

        $pinterest = trim((string) ($pixels['pinterest_pixel'] ?? ''));
        if ($pinterest !== '') {
            $results[] = $this->wizardService->createPinterestPixel($token, $wsPath, $pinterest);
        }

        $snapchat = trim((string) ($pixels['snapchat_pixel'] ?? ''));
        if ($snapchat !== '') {
            $results[] = $this->wizardService->createSnapchatPixel($token, $wsPath, $snapchat);
        }

        $tiktok = trim((string) ($pixels['tiktok_pixel'] ?? ''));
        if ($tiktok !== '') {
            $results[] = $this->wizardService->createTiktokPixel($token, $wsPath, $tiktok);
        }

        return ApiResponse::success([
            'results' => $results,
            'completed' => true,
        ]);
    }
}
