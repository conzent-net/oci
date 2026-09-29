<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\MatomoWizardService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/matomo/wizard/apply — Apply the Matomo TM wizard configuration.
 *
 * Creates all tags, triggers, and variables in the selected Matomo TM
 * container based on the pixel configuration from Step 2.
 * Also persists Matomo credentials to the site record.
 */
final class MatomoWizardApplyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly MatomoWizardService $wizardService,
        private readonly Connection $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $matomoUrl = $_SESSION['matomo_url'] ?? '';
        $matomoToken = $_SESSION['matomo_token'] ?? '';

        if ($matomoUrl === '' || $matomoToken === '') {
            return ApiResponse::error('Matomo session expired. Please re-validate credentials.', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $conzentSiteId = (int) ($body['site_id'] ?? 0);
        $matomoSiteId = (int) ($body['matomo_site_id'] ?? 0);
        $idContainer = (string) ($body['container_id'] ?? '');
        $websiteKey = (string) ($body['website_key'] ?? '');
        $serverUrl = (string) ($body['server_url'] ?? '');
        $pixels = (array) ($body['pixels'] ?? []);

        if ($matomoSiteId <= 0 || $idContainer === '') {
            return ApiResponse::error('matomo_site_id and container_id are required.', 422);
        }

        if ($websiteKey === '') {
            return ApiResponse::error('website_key is required.', 422);
        }

        $results = [];

        // 1. Create foundation (Conzent Trigger, Cookie variable, CMP tag)
        $foundation = $this->wizardService->createFoundation(
            $matomoUrl,
            $matomoToken,
            $matomoSiteId,
            $idContainer,
            $websiteKey,
            $serverUrl,
        );
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
            $results[] = $this->wizardService->createGoogleAnalytics($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $ga);
        }

        $fb = trim((string) ($pixels['facebook_pixel'] ?? ''));
        if ($fb !== '') {
            $results[] = $this->wizardService->createFacebookPixel($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $fb);
        }

        $clarity = trim((string) ($pixels['microsoft_clarity'] ?? ''));
        if ($clarity !== '') {
            $results[] = $this->wizardService->createMicrosoftClarity($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $clarity);
        }

        $linkedin = trim((string) ($pixels['linkedin_insights'] ?? ''));
        if ($linkedin !== '') {
            $results[] = $this->wizardService->createLinkedInInsight($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $linkedin);
        }

        $snapchat = trim((string) ($pixels['snapchat_pixel'] ?? ''));
        if ($snapchat !== '') {
            $results[] = $this->wizardService->createSnapchatPixel($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $snapchat);
        }

        $tiktok = trim((string) ($pixels['tiktok_pixel'] ?? ''));
        if ($tiktok !== '') {
            $results[] = $this->wizardService->createTiktokPixel($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $tiktok);
        }

        // Process custom tags
        $customTags = (array) ($pixels['custom_tags'] ?? []);
        foreach ($customTags as $custom) {
            $customName = trim((string) ($custom['name'] ?? ''));
            $customScript = trim((string) ($custom['script'] ?? ''));
            if ($customName !== '' && $customScript !== '') {
                $results[] = $this->wizardService->createCustomTag($matomoUrl, $matomoToken, $matomoSiteId, $idContainer, $customName, $customScript);
            }
        }

        // 3. Persist Matomo credentials to user (per-user) and site/container to site (per-site)
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $this->db->update('oci_users', [
                'matomo_url' => $matomoUrl,
                'matomo_token' => $matomoToken,
            ], ['id' => $userId]);
        }
        if ($conzentSiteId > 0) {
            $this->db->update('oci_sites', [
                'matomo_site_id' => $matomoSiteId,
                'matomo_container_id' => $idContainer,
            ], ['id' => $conzentSiteId]);
        }

        return ApiResponse::success([
            'results' => $results,
            'completed' => true,
        ]);
    }
}
