<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/sites/associated/remove — Remove an associated domain.
 *
 * Regenerates the site's script so the domain guard stops accepting the
 * removed hostname immediately.
 */
final class AssociatedDomainRemoveHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly ScriptGenerationService $scriptService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);
        $id = (int) ($body['id'] ?? 0);

        if ($siteId === 0 || $id === 0) {
            return ApiResponse::json(['success' => false, 'error' => 'Missing site_id or id'], 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        if (!$this->siteRepo->removeAssociatedDomain($id, $siteId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Associated domain not found'], 404);
        }

        $this->auditLogService->log(
            userId: $userId,
            action: 'remove',
            entityType: 'AssociatedDomain',
            entityId: $id,
            newValues: ['site_id' => $siteId],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $response = ['success' => true];

        try {
            if (!$this->scriptService->generate($siteId)) {
                $response['warning'] = 'Domain removed but script regeneration failed. Check server logs.';
            }
        } catch (\Throwable $e) {
            $response['warning'] = 'Domain removed but script regeneration failed: ' . $e->getMessage();
        }

        return ApiResponse::json($response);
    }
}
