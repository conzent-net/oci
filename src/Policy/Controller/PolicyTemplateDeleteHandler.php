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
 * POST /app/policies/templates/delete — Delete a policy template.
 */
final class PolicyTemplateDeleteHandler implements RequestHandlerInterface
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
        $templateId = (int) ($body['template_id'] ?? 0);
        $type = $body['type'] ?? '';

        if ($templateId <= 0 || !\in_array($type, ['cookie', 'privacy'], true)) {
            return ApiResponse::error('Invalid request', 400);
        }

        $this->policyService->deleteTemplate($templateId, $type, (int) $user['id']);

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'delete',
            entityType: $type === 'cookie' ? 'CookiePolicyTemplate' : 'PrivacyPolicyTemplate',
            entityId: $templateId,
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['deleted' => true]);
    }
}
