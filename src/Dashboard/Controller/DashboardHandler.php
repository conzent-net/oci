<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CmpValidationService;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET / — Main dashboard page.
 *
 * Routes to the correct dashboard view based on user role:
 * - admin: admin dashboard
 * - agency/reseller: agency dashboard
 * - customer (default): customer dashboard with site selector
 */
final class DashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
        private readonly CmpValidationService $cmpValidation,
        private readonly GtmOAuthService $gtmOAuth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $role = (string) ($user['role'] ?? 'customer');

        return match ($role) {
            'agency', 'reseller' => $this->renderAgencyDashboard($user),
            default => $this->renderCustomerDashboard($request, $user),
        };
    }

    /**
     * @param array<string, mixed> $user
     */
    private function renderAdminDashboard(array $user): ResponseInterface
    {
        $html = $this->twig->render('pages/dashboard/admin.html.twig', [
            'title' => 'Admin Dashboard',
            'user' => $user,
        ]);

        return ApiResponse::html($html);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function renderAgencyDashboard(array $user): ResponseInterface
    {
        $data = $this->dashboardService->getAgencyDashboard($user);

        $html = $this->twig->render('pages/dashboard/agency.html.twig', [
            'title' => 'Agency Dashboard',
            'user' => $user,
            'dashboard' => $data,
        ]);

        return ApiResponse::html($html);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function renderCustomerDashboard(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $cookies = $request->getCookieParams();

        // Determine selected site
        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);

        if ($resolved['redirect'] !== null) {
            // If the redirect is to /sites/create (no sites), honour it
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];

        // No subscription check — show dashboard even for free/unsubscribed
        // (legacy redirects to choose_plan if not subscribed, but OCI handles this in the template)
        $data = $this->dashboardService->getCustomerDashboard($user, $siteId);

        $cmpInfo = $this->cmpValidation->getValidation();

        // GTM OAuth: check if Google credentials are configured and if user has an active session
        // Only flag as "connected" when the user has a live OAuth token but hasn't saved
        // a container yet — once a container ID is persisted, the step is complete.
        $params = $request->getQueryParams();
        $hasGtmContainer = !empty($data->siteData['gtm_container_id']);
        $gtmConnected = !$hasGtmContainer
            && isset($_SESSION['gtm_access_token'])
            && $_SESSION['gtm_access_token'] !== ''
            && ($_SESSION['gtm_token_expires'] ?? 0) > time();

        $html = $this->twig->render('pages/dashboard/customer.html.twig', [
            'title' => 'Dashboard',
            'user' => $user,
            'dashboard' => $data,
            'tcf_enabled' => $cmpInfo['valid'],
            'gtm_oauth_configured' => $this->gtmOAuth->isConfigured(),
            'gtm_connected' => $gtmConnected || (!$hasGtmContainer && !empty($params['gtm_connected'])),
        ]);

        // Set site_id cookie for future visits (not HttpOnly — site selector sets it client-side too)
        $response = ApiResponse::html($html);

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }
}
