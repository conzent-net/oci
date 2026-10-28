<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieReclassificationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies/reclassify-request — Request that a Conzent-classified
 * cookie be moved to a different category. Reviewed by staff; does not change
 * the requesting site's own classification.
 */
final class CookieReclassRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieReclassificationService $reclassService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $siteId = (int) ($body['site_id'] ?? 0);
        $cookieName = trim((string) ($body['cookie_name'] ?? ''));
        $cookieDomain = isset($body['cookie_domain']) ? trim((string) $body['cookie_domain']) : null;
        $currentSlug = isset($body['current_category_slug']) ? trim((string) $body['current_category_slug']) : null;
        $requestedCategoryId = (int) ($body['requested_category_id'] ?? 0);
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;

        if ($siteId === 0 || $cookieName === '' || $requestedCategoryId === 0) {
            return ApiResponse::error('Site, cookie name and target category are required');
        }

        $result = $this->reclassService->submitRequest(
            (int) $user['id'],
            $siteId,
            $cookieName,
            $cookieDomain !== '' ? $cookieDomain : null,
            $currentSlug !== '' ? $currentSlug : null,
            $requestedCategoryId,
            $reason !== '' ? $reason : null,
            $request->getServerParams()['REMOTE_ADDR'] ?? null,
            $request->getHeaderLine('User-Agent') ?: null,
        );

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success();
    }
}
