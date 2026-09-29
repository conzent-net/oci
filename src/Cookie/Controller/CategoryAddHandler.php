<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Service\CookieService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/categories — Add a category to a site.
 */
final class CategoryAddHandler implements RequestHandlerInterface
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
        $categoryId = (int) ($body['category_id'] ?? 0);
        $name = trim($body['name'] ?? '');
        $description = trim($body['description'] ?? '');

        if ($siteId === 0 || $categoryId === 0) {
            return ApiResponse::error('Site ID and Category ID are required');
        }

        $result = $this->cookieService->addCategoryToSite(
            (int) $user['id'],
            $siteId,
            $categoryId,
            $name,
            $description,
        );

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        return ApiResponse::success();
    }
}
