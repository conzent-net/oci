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
 * POST /app/policies/templates/apply — Apply a template to selected sites.
 */
final class PolicyTemplateApplyHandler implements RequestHandlerInterface
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
        $siteIds = $body['site_ids'] ?? [];
        $languageId = (int) ($body['language_id'] ?? 1);

        if ($templateId <= 0 || !\in_array($type, ['cookie', 'privacy'], true) || empty($siteIds)) {
            return ApiResponse::error('Invalid request', 400);
        }

        $this->policyService->applyTemplate($templateId, $type, $siteIds, $languageId);

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'update',
            entityType: $type === 'cookie' ? 'CookiePolicyTemplate' : 'PrivacyPolicyTemplate',
            entityId: $templateId,
            newValues: ['applied_to_sites' => $siteIds, 'language_id' => $languageId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['applied' => true, 'sites' => \count($siteIds)]);
    }
}
