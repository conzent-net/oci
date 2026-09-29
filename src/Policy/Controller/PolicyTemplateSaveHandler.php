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
 * POST /app/policies/templates — Save current policy as a reusable template.
 */
final class PolicyTemplateSaveHandler implements RequestHandlerInterface
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
        $templateName = trim($body['template_name'] ?? '');
        $siteId = (int) ($body['site_id'] ?? 0);
        $languageId = (int) ($body['language_id'] ?? 1);

        if (!\in_array($type, ['cookie', 'privacy'], true)) {
            return ApiResponse::error('Invalid type', 400);
        }
        if ($templateName === '') {
            return ApiResponse::error('Template name is required', 400);
        }
        if ($siteId <= 0) {
            return ApiResponse::error('Missing site_id', 400);
        }

        try {
            $id = $this->policyService->saveAsTemplate((int) $user['id'], $type, $templateName, $siteId, $languageId);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 400);
        }

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'create',
            entityType: $type === 'cookie' ? 'CookiePolicyTemplate' : 'PrivacyPolicyTemplate',
            entityId: $id,
            newValues: ['name' => $templateName, 'site_id' => $siteId, 'language_id' => $languageId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['id' => $id]);
    }
}
