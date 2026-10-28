<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Banner\Service\ScriptGenerationService;
use OCI\Compliance\Repository\PrivacyFrameworkRepositoryInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Shared\Service\EditionService;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /app/sites/{id} — Update a site's settings.
 *
 * Accepts JSON body with: site_name, domain, privacy_policy_url, status, language_ids,
 * gtm_container_id, gtm_data_layer.
 * Mirrors legacy: action.php → update_website
 */
final class SiteUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepository,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly PrivacyFrameworkRepositoryInterface $frameworkRepo,
        private readonly EditionService $edition,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) $user['id'];
        $siteId = (int) $request->getAttribute('id');

        if ($siteId <= 0) {
            return ApiResponse::error('Invalid site ID', 400);
        }

        // Ownership check
        if (!$this->siteRepository->belongsToUser($siteId, $userId)) {
            return ApiResponse::error('Site not found', 404);
        }

        $body = (array) $request->getParsedBody();

        // Validate domain if provided — normalise to bare hostname (no protocol, path, etc.)
        $domain = isset($body['domain']) ? trim((string) $body['domain']) : null;
        if ($domain !== null) {
            // Strip protocol
            $domain = (string) preg_replace('#^https?://#i', '', $domain);
            // Strip path, query, fragment — keep only the host(:port)
            $domain = explode('/', $domain)[0];
            $domain = explode('?', $domain)[0];
            $domain = explode('#', $domain)[0];
            $domain = rtrim($domain, '.');
            $domain = strtolower($domain);
        }
        if ($domain !== null && $domain === '') {
            return ApiResponse::error('Domain cannot be empty', 422);
        }

        // Build update data — only include provided fields
        $data = [];

        if (isset($body['site_name'])) {
            $data['site_name'] = trim((string) $body['site_name']);
        }

        if ($domain !== null) {
            // Check domain uniqueness (excluding current site)
            $existingSite = $this->siteRepository->findById($siteId);
            if ($existingSite !== null && $existingSite['domain'] !== $domain && $this->siteRepository->domainExists($domain)) {
                return ApiResponse::error('Domain is already registered', 422);
            }
            $data['domain'] = $domain;
        }

        if (isset($body['privacy_policy_url'])) {
            $data['privacy_policy_url'] = trim((string) $body['privacy_policy_url']);
        }

        if (isset($body['status'])) {
            $allowedStatuses = ['active', 'disabled'];
            $status = (string) $body['status'];
            if (!\in_array($status, $allowedStatuses, true)) {
                return ApiResponse::error('Invalid status. Allowed: ' . implode(', ', $allowedStatuses), 422);
            }

            // Enforce plan limit when activating a site
            if ($status === 'active' && $this->edition->arePlanLimitsEnforced() && $this->subscriptionService !== null) {
                $maxDomains = $this->subscriptionService->getAllowedDomainCount($userId);
                if ($maxDomains > 0) {
                    $activeCount = $this->siteRepository->countActiveByUser($userId);
                    if ($activeCount >= $maxDomains) {
                        return ApiResponse::error(
                            'You have reached your plan limit of ' . $maxDomains . ' active site' . ($maxDomains !== 1 ? 's' : '') . '. Please upgrade your plan or disable another site first.',
                            422,
                        );
                    }
                }
            }

            $data['status'] = $status;

            // Clear suspended_reason when activating
            if ($status === 'active') {
                $data['suspended_reason'] = null;
            }
        }

        if (isset($body['banner_type'])) {
            $allowedTypes = ['gdpr', 'ccpa', 'both'];
            $bannerType = (string) $body['banner_type'];
            if (!\in_array($bannerType, $allowedTypes, true)) {
                return ApiResponse::error('Invalid banner type. Allowed: ' . implode(', ', $allowedTypes), 422);
            }
            $data['display_banner_type'] = $bannerType;
        }

        // Derive display_banner_type from frameworks if provided
        if (isset($body['frameworks']) && \is_array($body['frameworks'])) {
            $fwIds = array_filter(array_map('trim', $body['frameworks']));
            $hasGdpr = \in_array('gdpr', $fwIds, true) || \in_array('eprivacy_directive', $fwIds, true);
            $hasCcpa = \in_array('ccpa_cpra', $fwIds, true);
            if ($hasGdpr && $hasCcpa) {
                $data['display_banner_type'] = 'gdpr_ccpa';
            } elseif ($hasCcpa) {
                $data['display_banner_type'] = 'ccpa';
            } elseif ($hasGdpr) {
                $data['display_banner_type'] = 'gdpr';
            }
        }

        if (array_key_exists('gcm_config_status', $body)) {
            $data['gcm_config_status'] = $body['gcm_config_status'] !== null
                ? json_encode($body['gcm_config_status'], \JSON_THROW_ON_ERROR)
                : null;
        }

        // GTM auto-inject settings
        if (array_key_exists('gtm_container_id', $body)) {
            $val = trim((string) ($body['gtm_container_id'] ?? ''));
            $data['gtm_container_id'] = $val !== '' ? $val : null;
        }
        if (array_key_exists('gtm_data_layer', $body)) {
            $val = trim((string) ($body['gtm_data_layer'] ?? ''));
            $data['gtm_data_layer'] = $val !== '' ? $val : null;
        }

        if ($data !== []) {
            $this->siteRepository->updateSiteSettings($siteId, $data);
        }

        // Sync languages if provided
        if (isset($body['language_ids']) && \is_array($body['language_ids'])) {
            $this->syncLanguages($siteId, array_map('intval', $body['language_ids']));
        }

        // Sync frameworks if provided
        $frameworksChanged = false;
        if (isset($body['frameworks']) && \is_array($body['frameworks'])) {
            $fwIds = array_filter(array_map('trim', $body['frameworks']));
            $this->frameworkRepo->setFrameworksForSite($siteId, $fwIds);
            $frameworksChanged = true;
        }

        // Regenerate consent script when GTM settings, banner type, or frameworks change
        $scriptAffected = array_key_exists('gtm_container_id', $body)
            || array_key_exists('gtm_data_layer', $body)
            || isset($body['banner_type'])
            || $frameworksChanged;

        $scriptGenerated = null;
        if ($scriptAffected) {
            try {
                $scriptGenerated = $this->scriptService->generate($siteId);
            } catch (\Throwable) {
                $scriptGenerated = false;
            }
        }

        $response = ['message' => 'Site updated'];
        if ($scriptGenerated === false) {
            $response['warning'] = 'Site saved but script regeneration failed. Try saving banner settings to regenerate.';
        }

        return ApiResponse::success($response);
    }

    /**
     * Sync site languages: add new, remove old, ensure a default exists.
     *
     * @param list<int> $newLangIds
     */
    private function syncLanguages(int $siteId, array $newLangIds): void
    {
        $currentLangs = $this->languageRepo->getSiteLanguages($siteId);
        $currentIds = array_map(static fn(array $l): int => (int) ($l['language_id'] ?? $l['id'] ?? 0), $currentLangs);

        // Determine current default
        $currentDefault = null;
        foreach ($currentLangs as $lang) {
            if (!empty($lang['is_default'])) {
                $currentDefault = (int) ($lang['language_id'] ?? $lang['id'] ?? 0);
                break;
            }
        }

        // Ensure at least one language
        if ($newLangIds === []) {
            $systemDefault = $this->languageRepo->getSystemDefaultLanguage();
            $newLangIds = [$systemDefault !== null ? (int) $systemDefault['id'] : 1];
        }

        // Remove languages no longer selected
        foreach ($currentIds as $existingId) {
            if (!\in_array($existingId, $newLangIds, true)) {
                $this->languageRepo->removeSiteLanguage($siteId, $existingId);
            }
        }

        // Add newly selected languages
        foreach ($newLangIds as $langId) {
            if (!\in_array($langId, $currentIds, true)) {
                $this->languageRepo->addSiteLanguage($siteId, $langId, false);
            }
        }

        // If current default was removed, set first new language as default
        if ($currentDefault !== null && !\in_array($currentDefault, $newLangIds, true)) {
            $this->languageRepo->setDefaultLanguage($siteId, $newLangIds[0]);
        }

        // If there was no default, set first as default
        if ($currentDefault === null && $newLangIds !== []) {
            $this->languageRepo->setDefaultLanguage($siteId, $newLangIds[0]);
        }
    }
}
