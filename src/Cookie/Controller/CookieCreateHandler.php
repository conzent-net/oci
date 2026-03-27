<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies — Create a new cookie for a site.
 */
final class CookieCreateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieService $cookieService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $siteId = (int) ($body['site_id'] ?? 0);

        if ($siteId === 0) {
            return ApiResponse::error('Site ID is required');
        }

        $result = $this->cookieService->createCookie((int) $user['id'], $siteId, $body);

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success(['id' => $result['id']]);
    }
}
