<?php

declare(strict_types=1);

namespace OCI\Compliance\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Compliance\Service\ChecklistService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/compliance/toggle — Toggle a checklist item (AJAX).
 */
final class ComplianceChecklistToggleHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ChecklistService $checklistService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $regulationId = (string) ($body['regulation_id'] ?? '');
        $itemId = (string) ($body['item_id'] ?? '');

        if ($regulationId === '' || $itemId === '') {
            return ApiResponse::error('regulation_id and item_id are required');
        }

        $result = $this->checklistService->toggleItem((int) $user['id'], $regulationId, $itemId);

        if (isset($result['error'])) {
            return ApiResponse::error($result['error']);
        }

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'update',
            entityType: 'ComplianceChecklist',
            newValues: [
                'regulation_id' => $regulationId,
                'item_id' => $itemId,
                'checked' => $result['checked'] ?? null,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success($result);
    }
}
