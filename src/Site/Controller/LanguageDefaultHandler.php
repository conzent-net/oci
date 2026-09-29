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
 * POST /app/languages/set-default — Set a language as the default for a site.
 */
final class LanguageDefaultHandler implements RequestHandlerInterface
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

        $this->languageRepo->setDefaultLanguage($siteId, $languageId);

        return ApiResponse::json(['success' => true]);
    }
}
