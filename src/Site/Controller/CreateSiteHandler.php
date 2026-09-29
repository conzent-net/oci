<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Compliance\Service\PrivacyFrameworkService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\DTO\CreateSiteInput;
use OCI\Site\Service\SiteCreationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * POST /sites/create — Process the "Create Site" form.
 *
 * On success: redirects to dashboard with the new site selected.
 * On failure: re-renders the form with validation errors.
 */
final class CreateSiteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteCreationService $siteCreationService,
        private readonly TwigEnvironment $twig,
        private readonly PrivacyFrameworkService $frameworkService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return $this->isJsonRequest($request)
                ? ApiResponse::json(['success' => false, 'error' => 'Unauthorized'], 401)
                : ApiResponse::redirect('/login');
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);

        $domain = trim((string) ($body['domain'] ?? ''));
        $siteName = trim((string) ($body['site_name'] ?? ''));
        $privacyPolicyUrl = trim((string) ($body['privacy_policy_url'] ?? ''));
        $bannerType = trim((string) ($body['banner_type'] ?? 'gdpr'));
        $languageIds = array_map('intval', (array) ($body['language_ids'] ?? []));
        $frameworkIds = array_filter(array_map('trim', (array) ($body['frameworks'] ?? [])));

        // Derive banner_type from selected frameworks for backward compatibility
        if ($frameworkIds !== []) {
            $hasGdpr = in_array('gdpr', $frameworkIds, true) || in_array('eprivacy_directive', $frameworkIds, true);
            $hasCcpa = in_array('ccpa_cpra', $frameworkIds, true);
            if ($hasGdpr && $hasCcpa) {
                $bannerType = 'gdpr_ccpa';
            } elseif ($hasCcpa) {
                $bannerType = 'ccpa';
            } else {
                $bannerType = 'gdpr';
            }
        }

        $input = new CreateSiteInput(
            domain: $domain,
            siteName: $siteName,
            privacyPolicyUrl: $privacyPolicyUrl,
            bannerType: $bannerType,
            languageIds: $languageIds,
            frameworkIds: $frameworkIds,
        );

        // Validate
        $errors = $this->siteCreationService->validateInput($user, $input);

        if ($errors !== []) {
            if ($this->isJsonRequest($request)) {
                return ApiResponse::json(['success' => false, 'errors' => $errors], 422);
            }
            return $this->renderFormWithErrors($user, $body, $errors);
        }

        // Attempt creation
        try {
            $result = $this->siteCreationService->createSite($user, $input);
        } catch (\InvalidArgumentException $e) {
            if ($this->isJsonRequest($request)) {
                return ApiResponse::json(['success' => false, 'errors' => ['domain' => $e->getMessage()]], 422);
            }
            return $this->renderFormWithErrors($user, $body, ['domain' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            $fieldKey = str_contains($e->getMessage(), 'plan') || str_contains($e->getMessage(), 'upgrade')
                ? 'plan'
                : 'domain';

            if ($this->isJsonRequest($request)) {
                return ApiResponse::json(['success' => false, 'errors' => [$fieldKey => $e->getMessage()]], 422);
            }
            return $this->renderFormWithErrors($user, $body, [$fieldKey => $e->getMessage()]);
        }

        if ($this->isJsonRequest($request)) {
            return ApiResponse::json([
                'success' => true,
                'site_id' => $result->siteId,
                'domain' => $result->domain,
                'website_key' => $result->websiteKey,
            ]);
        }

        // Success — redirect to dashboard with the new site selected
        $response = ApiResponse::redirect('/?site_id=' . $result->siteId);

        // Also set the site_id cookie
        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $result->siteId . '; Path=/; SameSite=Lax; HttpOnly',
        );
    }

    private function isJsonRequest(ServerRequestInterface $request): bool
    {
        $contentType = $request->getHeaderLine('Content-Type');
        $accept = $request->getHeaderLine('Accept');

        return str_contains($contentType, 'application/json')
            || str_contains($accept, 'application/json');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $old
     * @param array<string, string> $errors
     */
    private function renderFormWithErrors(array $user, array $old, array $errors): ResponseInterface
    {
        $userId = (int) $user['id'];
        $siteCount = $this->siteCreationService->getSiteCount($userId);
        $languages = $this->siteCreationService->getAvailableLanguages();
        $isFirstSite = $siteCount === 0;

        $groupedFrameworks = $this->frameworkService->getFrameworksGroupedByRegion();

        $html = $this->twig->render('pages/sites/create.html.twig', [
            'title' => $isFirstSite ? 'Welcome — Add Your First Site' : 'Add New Site',
            'user' => $user,
            'isFirstSite' => $isFirstSite,
            'siteCount' => $siteCount,
            'languages' => $languages,
            'groupedFrameworks' => $groupedFrameworks,
            'errors' => $errors,
            'old' => $old,
        ]);

        return ApiResponse::html($html, 422);
    }
}
