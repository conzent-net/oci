<?php

declare(strict_types=1);

namespace OCI\Policy\Controller;

use Doctrine\DBAL\Connection;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Policy\Service\PolicyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /policies — Overview of cookie & privacy policy status + templates.
 */
final class PolicyListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PolicyService $policyService,
        private readonly DashboardService $dashboardService,
        private readonly Connection $db,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];
        $sites = $resolved['sites'];
        $languageId = $this->getDefaultLanguageId($siteId);

        $cookiePolicy = $this->policyService->getOrCreateCookiePolicy($siteId, $languageId);
        $privacyPolicy = $this->policyService->getOrCreatePrivacyPolicy($siteId, $languageId);
        $templates = $this->policyService->getAllTemplates((int) $user['id']);
        $templateSites = $this->policyService->getTemplateSites($templates);

        // Get policy status for all user's sites (for apply modal indicators)
        $allSiteIds = array_column($sites, 'id');
        $sitePolicyStatus = $this->policyService->getSitePolicyStatus($allSiteIds, $languageId);

        // Current site domain for "Promote to Template" naming
        $currentDomain = '';
        foreach ($sites as $s) {
            if ((int) $s['id'] === $siteId) {
                $currentDomain = $s['domain'];
                break;
            }
        }

        // Build template name lookup (type:id → name) for display in apply modal
        // Keyed by type:id because cookie and privacy templates are in separate tables
        $templateNames = [];
        foreach ($templates as $t) {
            $key = $t['type'] . ':' . $t['id'];
            $templateNames[$key] = $t['template_name'];
        }

        $templateData = [
            'title' => 'Policies',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'languageId' => $languageId,
            'cookiePolicy' => $cookiePolicy,
            'privacyPolicy' => $privacyPolicy,
            'templates' => $templates,
            'templateSites' => $templateSites,
            'sitePolicyStatus' => $sitePolicyStatus,
            'templateNames' => $templateNames,
            'currentDomain' => $currentDomain,
        ];

        $response = ApiResponse::html($this->twig->render('pages/policies/index.html.twig', $templateData));

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }

    private function getDefaultLanguageId(int $siteId): int
    {
        $langId = $this->db->fetchOne(
            'SELECT sl.language_id FROM oci_site_languages sl WHERE sl.site_id = :sid AND sl.is_default = 1 LIMIT 1',
            ['sid' => $siteId],
        );

        return $langId !== false ? (int) $langId : 1;
    }
}
