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
 * GET /policies/privacy — 5-step privacy policy wizard.
 *
 * Supports ?template_id=X to edit a template's fields using the same wizard.
 */
final class PrivacyPolicyWizardHandler implements RequestHandlerInterface
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

        // Template editing mode?
        $templateId = (int) ($queryParams['template_id'] ?? 0);
        if ($templateId > 0) {
            try {
                $policy = $this->policyService->getTemplateForEditing($templateId, 'privacy', (int) $user['id']);
            } catch (\RuntimeException) {
                return ApiResponse::redirect('/policies');
            }
        } else {
            $policy = $this->policyService->getOrCreatePrivacyPolicy($siteId, $languageId);
        }

        $response = ApiResponse::html($this->twig->render('pages/policies/privacy_wizard.html.twig', [
            'title' => $templateId > 0 ? 'Edit Privacy Template' : 'Privacy Policy',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $sites,
            'languageId' => $languageId,
            'policy' => $policy,
            'templateId' => $templateId,
            'templateName' => $policy['template_name'] ?? '',
        ]));

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
