<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Cookie\Service\CookieService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/cookies/classify — Classify an unclassified cookie into a category.
 *
 * Per-site and immediate. Conzent-classified cookies are rejected here and must
 * use the reclassification-request flow instead.
 */
final class CookieClassifyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly CookieService $cookieService,
        private readonly AuditLogService $auditLog,
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
        $categoryId = (int) ($body['category_id'] ?? 0);

        if ($siteId === 0 || $cookieName === '' || $categoryId === 0) {
            return ApiResponse::error('Site, cookie name and category are required');
        }

        $result = $this->cookieService->classifyCookie(
            (int) $user['id'],
            $siteId,
            $cookieName,
            $cookieDomain !== '' ? $cookieDomain : null,
            $categoryId,
        );

        if (!$result['success']) {
            return ApiResponse::error($result['error']);
        }

        $this->auditLog->log(
            userId: (int) $user['id'],
            action: 'classify',
            entityType: 'SiteCookie',
            newValues: [
                'site_id' => $siteId,
                'cookie_name' => $cookieName,
                'category_id' => $categoryId,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success();
    }
}
