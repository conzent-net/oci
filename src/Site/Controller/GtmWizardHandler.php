<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /app/gtm/wizard — Render the 3-step GTM Wizard page.
 *
 * Step 1: Authenticate with Google & select account/container/workspace
 * Step 2: Configure pixel IDs (GA4, Ads, Facebook, etc.)
 * Step 3: Apply — creates tags/triggers/variables in the GTM container
 */
final class GtmWizardHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly GtmOAuthService $gtmOAuth,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $cookies = $request->getCookieParams();

        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);
        if ($resolved['redirect'] !== null) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];

        // Check if GTM OAuth is configured
        $gtmConfigured = $this->gtmOAuth->isConfigured();

        // Check if we have a valid access token in the session
        $gtmConnected = false;
        $gtmAccessToken = $_SESSION['gtm_access_token'] ?? null;
        $gtmTokenExpires = $_SESSION['gtm_token_expires'] ?? 0;
        if ($gtmAccessToken !== null && $gtmTokenExpires > time()) {
            $gtmConnected = true;
        }

        // Find the current site's website key for the Conzent CMP tag
        $currentSite = null;
        foreach ($resolved['sites'] as $site) {
            if ((int) $site['id'] === $siteId) {
                $currentSite = $site;
                break;
            }
        }
        $websiteKey = (string) ($currentSite['website_key'] ?? '');

        $html = $this->twig->render('pages/gtm/wizard.html.twig', [
            'title' => 'GTM Wizard',
            'active_page' => 'gtm-wizard',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $resolved['sites'],
            'websiteKey' => $websiteKey,
            'gtmConfigured' => $gtmConfigured,
            'gtmConnected' => $gtmConnected,
            'gtmClientId' => $this->gtmOAuth->getClientId(),
        ]);

        $response = ApiResponse::html($html);

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }
}
