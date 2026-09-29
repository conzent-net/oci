<?php

declare(strict_types=1);

namespace OCI\Policy\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Policy\Service\PolicyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/policies/clear — Clear/remove a site's policy content so a different source can be used.
 */
final class PolicyClearHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PolicyService $policyService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $type = $body['type'] ?? '';
        $siteId = (int) ($body['site_id'] ?? 0);
        $languageId = (int) ($body['language_id'] ?? 1);

        if (!\in_array($type, ['cookie', 'privacy'], true) || $siteId <= 0) {
            return ApiResponse::error('Invalid request', 400);
        }

        $this->policyService->clearPolicy($type, $siteId, $languageId);

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'delete',
            entityType: $type === 'cookie' ? 'CookiePolicy' : 'PrivacyPolicy',
            oldValues: ['site_id' => $siteId, 'language_id' => $languageId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['cleared' => true]);
    }
}
