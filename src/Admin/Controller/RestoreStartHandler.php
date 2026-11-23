<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Repository\BackupRepositoryInterface;
use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupDestinationService;
use OCI\Admin\Service\RestoreJobService;
use OCI\Admin\Service\RestoreService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /admin/backup/{id}/restore — restore from an archive this install holds.
 *
 * Enqueues only. The worker does the work behind a maintenance lock, because
 * this request's own database is the one being replaced.
 */
final class RestoreStartHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BackupRepositoryInterface $backups,
        private readonly RestoreJobService $jobs,
        private readonly BackupDestinationService $destinations,
        private readonly RestoreService $restore,
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

        $id = (int) ($request->getAttribute('id') ?? 0);
        $row = $id > 0 ? $this->backups->find($id) : null;

        if ($row === null || $row['status'] !== 'success') {
            return ApiResponse::error('Backup not found.', 404, [], $fresh());
        }

        $path = (string) $row['path'];

        // Confirm BEFORE fetching. Retrieval can pull tens of megabytes over
        // the network, and doing that on the way to rejecting a typo would be
        // a slow, expensive no.
        //
        // Typing the filename is the confirmation. A styled modal alone is too
        // easy to click through for something that replaces every database.
        $typed = trim((string) ($body['confirm'] ?? ''));
        if ($typed !== (string) $row['filename']) {
            return ApiResponse::error(
                'Type the archive filename exactly to confirm.',
                422,
                ['confirm' => 'Expected: ' . $row['filename']],
                $fresh(),
            );
        }

        // The local copy is the one that dies with the container; the offsite
        // copy is the one that survives the event you are recovering from. So a
        // missing local file is not the end of the road — pull it back from
        // whichever destination still holds it and carry on.
        if (!is_file($path)) {
            $dir = \dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                return ApiResponse::error('Cannot create the backup directory to retrieve into.', 500, [], $fresh());
            }

            $got = $this->destinations->retrieve((string) $row['filename'], $path);

            if ($got['ok'] !== true) {
                return ApiResponse::error(
                    'That archive is not on disk and could not be retrieved from any destination. '
                    . ($got['error'] ?? ''),
                    410,
                    [],
                    $fresh(),
                );
            }
        }

        $preBackup = ($body['pre_backup'] ?? '1') !== '0';

        $r = $this->jobs->request($path, (int) $user['id'], $preBackup);
        if ($r['ok'] !== true) {
            return ApiResponse::error((string) ($r['error'] ?? 'Could not start the restore.'), 409, [], $fresh());
        }

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.restore',
            entityType: 'Backup',
            entityId: $id,
            newValues: [
                'filename' => $row['filename'],
                'job' => $r['job'] ?? '',
                'pre_backup' => $preBackup,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success([
            'job' => $r['job'] ?? '',
            'inspect' => $this->restore->inspect($path),
        ] + $fresh());
    }
}
