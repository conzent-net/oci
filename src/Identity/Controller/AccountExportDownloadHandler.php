<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use Nyholm\Psr7\Response;
use OCI\Admin\Service\AuditLogService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AccountExportJobService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /account/export/download — stream the caller's finished export zip.
 *
 * Strictly self-scoped: the path comes from the caller's own status record,
 * never from request input. The download itself is an auditable data-access
 * event and is logged as one.
 */
final class AccountExportDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AccountExportJobService $jobs,
        private readonly AuditLogService $auditLog,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $path = $this->jobs->readyPath($userId);

        if ($path === null) {
            return ApiResponse::redirect('/account');
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return ApiResponse::error('Export file unavailable', 500);
        }

        $this->auditLog->log(
            userId: $userId,
            action: 'export_download',
            entityType: 'Account',
            entityId: $userId,
            newValues: ['size' => filesize($path)],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return new Response(200, [
            'Content-Type' => 'application/zip',
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Cache-Control' => 'no-store',
        ], $stream);
    }
}
