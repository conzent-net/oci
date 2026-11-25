<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/languages/remove — Remove a language from a site.
 */
final class LanguageRemoveHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);
        $languageId = (int) ($body['language_id'] ?? 0);

        if ($siteId === 0 || $languageId === 0) {
            return ApiResponse::json(['success' => false, 'error' => 'Missing site_id or language_id'], 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        // Cannot remove the only language
        if ($this->languageRepo->countSiteLanguages($siteId) <= 1) {
            return ApiResponse::json(['success' => false, 'error' => 'Cannot remove the last language'], 422);
        }

        // Check if removing the default — if so, reassign
        $defaultLang = $this->languageRepo->getDefaultLanguage($siteId);
        $wasDefault = $defaultLang !== null && $defaultLang['lang_id'] === $languageId;

        $this->languageRepo->removeSiteLanguage($siteId, $languageId);

        if ($wasDefault) {
            // Set the first remaining language as default
            $remaining = $this->languageRepo->getSiteLanguages($siteId);
            if ($remaining !== []) {
                $this->languageRepo->setDefaultLanguage($siteId, (int) $remaining[0]['id']);
            }
        }

        return ApiResponse::json(['success' => true]);
    }
}
