<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupService;
use OCI\Admin\Service\RestoreJobService;
use OCI\Admin\Service\RestoreService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /admin/backup/restore-uploaded — restore an archive that was uploaded.
 *
 * Deliberately a second call rather than part of the upload: the upload step
 * reports what the archive contains, and this step is where someone decides to
 * accept it. Restoring an uploaded archive executes SQL written elsewhere, so
 * the confirmation is typed, not clicked.
 */
final class RestoreUploadedHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RestoreService $restore,
        private readonly RestoreJobService $jobs,
        private readonly AuditLogService $audit,
        private readonly CsrfService $csrf,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Not authenticated', 401);
        }

        $body = $request->getParsedBody() ?? [];
        $fresh = fn (): array => ['csrf_token' => $this->csrf->generate('admin_backup')];

        if (!$this->csrf->validate((string) ($body['_csrf_token'] ?? ''), 'admin_backup')) {
            return ApiResponse::error('Session expired. Please refresh and try again.', 403, [], $fresh());
        }

        // The token is a bare filename produced by the upload handler. Strip any
        // path so a crafted value cannot escape the upload directory.
        $token = basename((string) ($body['token'] ?? ''));
        if ($token === '' || !str_ends_with(strtolower($token), '.tar.gz')) {
            return ApiResponse::error('Upload the archive first.', 422, [], $fresh());
        }

        $path = $this->backupService->backupDir() . '/uploaded/' . $token;
        if (!is_file($path)) {
            return ApiResponse::error('That upload is no longer available. Upload it again.', 410, [], $fresh());
        }

        if (trim((string) ($body['confirm'] ?? '')) !== $token) {
            return ApiResponse::error(
                'Type the uploaded filename exactly to confirm.',
                422,
                ['confirm' => 'Expected: ' . $token],
                $fresh(),
            );
        }

        $info = $this->restore->inspect($path);
        if ($info['ok'] !== true) {
            return ApiResponse::error((string) ($info['error'] ?? 'Archive could not be read.'), 422, [], $fresh());
        }

        $preBackup = ($body['pre_backup'] ?? '1') !== '0';

        $r = $this->jobs->request($path, (int) $user['id'], $preBackup);
        if ($r['ok'] !== true) {
            return ApiResponse::error((string) ($r['error'] ?? 'Could not start the restore.'), 409, [], $fresh());
        }

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.restore_uploaded',
            entityType: 'Backup',
            newValues: [
                'filename' => $token,
                'databases' => $info['databases'],
                'job' => $r['job'] ?? '',
                'pre_backup' => $preBackup,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['job' => $r['job'] ?? '', 'inspect' => $info] + $fresh());
    }
}
