<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use Doctrine\DBAL\Connection;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CmpValidationService;
use OCI\Identity\Service\MailerService;
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
        private readonly MailerService $mailer,
        private readonly Connection $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $role = (string) ($user['role'] ?? 'customer');

        // All users (including agency) get the standard customer dashboard.
        // Agency-specific management lives at /agency.
        return $this->renderCustomerDashboard($request, $user);
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

        // Send 80% pageview warning email (once per month)
        $this->checkPageviewWarning($user, $data);

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

    /**
     * Send a warning email when pageview usage reaches 80% of the monthly limit.
     * Only sent once per calendar month per user.
     *
     * @param array<string, mixed> $user
     */
    private function checkPageviewWarning(array $user, \OCI\Dashboard\DTO\CustomerDashboardData $data): void
    {
        if ($data->pageviewsLimit <= 0) {
            return; // Unlimited plan
        }

        $pct = ($data->pageviewsUsed / $data->pageviewsLimit) * 100;
        if ($pct < 80) {
            return;
        }

        $userId = (int) $user['id'];
        $email = (string) ($user['email'] ?? '');
        $monthStart = date('Y-m-01');

        // Check if already sent this month
        $alreadySent = $this->db->fetchOne(
            "SELECT id FROM oci_mail_log
             WHERE user_id = :uid AND template = 'pageview_warning_80' AND created_at >= :month
             LIMIT 1",
            ['uid' => $userId, 'month' => $monthStart . ' 00:00:00'],
        );

        if ($alreadySent !== false) {
            return;
        }

        $used = number_format($data->pageviewsUsed);
        $limit = number_format($data->pageviewsLimit);
        $pctRound = (int) round($pct);

        $subject = "Conzent: You've used {$pctRound}% of your monthly pageviews";
        $html = <<<HTML
        <div style="font-family:sans-serif;max-width:560px;margin:0 auto;padding:2rem">
            <h2 style="color:#334155">Pageview Limit Warning</h2>
            <p>You've used <strong>{$used}</strong> of your <strong>{$limit}</strong> monthly pageviews ({$pctRound}%).</p>
            <p>When the limit is reached, your consent banner will stop showing until the next billing period.</p>
            <p><a href="https://app.getconzent.com/billing" style="display:inline-block;padding:0.6rem 1.5rem;background:#2e9e5e;color:#fff;text-decoration:none;border-radius:6px">Upgrade Your Plan</a></p>
            <p style="color:#94a3b8;font-size:0.85rem">— The Conzent Team</p>
        </div>
        HTML;

        $this->mailer->send($email, $subject, $html);

        // Log it so we don't send again this month
        $this->db->insert('oci_mail_log', [
            'user_id' => $userId,
            'to_email' => $email,
            'subject' => $subject,
            'template' => 'pageview_warning_80',
            'status' => 'sent',
        ]);
    }
}
