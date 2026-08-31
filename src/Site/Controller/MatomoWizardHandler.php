<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use Doctrine\DBAL\Connection;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /matomo/wizard — Render the 3-step Matomo Tag Manager Wizard page.
 *
 * Step 1: Connect to Matomo & select site/container
 * Step 2: Configure pixel scripts
 * Step 3: Apply — creates tags/triggers/variables in the Matomo TM container
 */
final class MatomoWizardHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
        private readonly Connection $db,
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

        // Find the current site's website key and saved Matomo config
        $currentSite = null;
        foreach ($resolved['sites'] as $site) {
            if ((int) $site['id'] === $siteId) {
                $currentSite = $site;
                break;
            }
        }

        $websiteKey = (string) ($currentSite['website_key'] ?? '');

        // Matomo credentials are per-user, not per-site
        $savedMatomoUrl = (string) ($user['matomo_url'] ?? '');
        $savedMatomoToken = (string) ($user['matomo_token'] ?? '');
        $maskedToken = $savedMatomoToken !== '' ? '••••' . substr($savedMatomoToken, -4) : '';

        // If we have saved credentials in the DB, seed the session so the wizard auto-connects
        if ($savedMatomoUrl !== '' && $savedMatomoToken !== '') {
            if (empty($_SESSION['matomo_url']) || empty($_SESSION['matomo_token'])) {
                $_SESSION['matomo_url'] = $savedMatomoUrl;
                $_SESSION['matomo_token'] = $savedMatomoToken;
            }
        }

        // Check if we have valid Matomo credentials in session
        $matomoConnected = isset($_SESSION['matomo_url'], $_SESSION['matomo_token'])
            && $_SESSION['matomo_url'] !== ''
            && $_SESSION['matomo_token'] !== '';

        $savedMatomoSiteId = (string) ($currentSite['matomo_site_id'] ?? '');
        $savedContainerId = (string) ($currentSite['matomo_container_id'] ?? '');

        $html = $this->twig->render('pages/matomo/wizard.html.twig', [
            'title' => 'Matomo TM Wizard',
            'active_page' => 'matomo-wizard',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $resolved['sites'],
            'websiteKey' => $websiteKey,
            'matomoConnected' => $matomoConnected,
            'savedMatomoUrl' => $savedMatomoUrl,
            'maskedToken' => $maskedToken,
            'savedMatomoSiteId' => $savedMatomoSiteId,
            'savedContainerId' => $savedContainerId,
        ]);

        $response = ApiResponse::html($html);

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }
}
