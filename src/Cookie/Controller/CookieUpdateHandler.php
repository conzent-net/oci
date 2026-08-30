<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /app/cookies/{id} — Update a cookie.
 */
final class CookieUpdateHandler implements RequestHandlerInterface
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

        $id = (int) ($request->getAttribute('route_params')['id'] ?? 0);
        if ($id === 0) {
            return ApiResponse::error('Cookie ID is required');
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];

        $result = $this->cookieService->updateCookie((int) $user['id'], $id, $body);

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success();
    }
}
