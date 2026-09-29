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
 * POST /app/policies/templates/rename — Rename a template.
 */
final class PolicyTemplateRenameHandler implements RequestHandlerInterface
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
        $newName = trim($body['template_name'] ?? '');

        if ($templateId <= 0 || !\in_array($type, ['cookie', 'privacy'], true)) {
            return ApiResponse::error('Invalid request', 400);
        }
        if ($newName === '') {
            return ApiResponse::error('Template name is required', 400);
        }

        try {
            $this->policyService->renameTemplate($templateId, $type, (int) $user['id'], $newName);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'update',
            entityType: $type === 'cookie' ? 'CookiePolicyTemplate' : 'PrivacyPolicyTemplate',
            entityId: $templateId,
            newValues: ['name' => $newName],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['renamed' => true]);
    }
}
