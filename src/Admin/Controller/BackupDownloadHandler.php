<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use Nyholm\Psr7\Response;
use OCI\Admin\Repository\BackupRepositoryInterface;
use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupDestinationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin/backup/{id}/download — stream one archive.
 *
 * The path is resolved from the database row, never from request input: the
 * id is the only thing the caller controls, so no traversal is reachable.
 * Every download is audit-logged, because an archive is a full copy of the
 * database and taking one off the server is exactly the event you want a
 * record of afterwards.
 */
final class BackupDownloadHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BackupRepositoryInterface $backups,
        private readonly BackupDestinationService $destinations,
        private readonly AuditLogService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $id = (int) ($request->getAttribute('id') ?? 0);
        $row = $id > 0 ? $this->backups->find($id) : null;

        if ($row === null || $row['status'] !== 'success') {
            return ApiResponse::error('Backup not found.', 404);
        }

        $path = (string) $row['path'];
        $retrievedFrom = null;

        if (!is_file($path)) {
            // Missing locally is not missing. An earlier version stopped here
            // and told the operator the archive was gone while a perfectly good
            // copy sat on the Storage Box — the copy that exists precisely for
            // the situation where the local one does not. Pull it back and
            // serve it; the wait is the size of the archive, and the
            // alternative is fetching it by hand over SFTP.
            //
            // Deliberately retrieved to the archive's canonical path rather
            // than a temp file: two copies is the whole point of having an
            // offsite destination, so recovering one should end with both
            // again, not with the same single copy in a different place.
            // Retention prunes local and remote to the same count, so nothing
            // in normal operation leaves an archive offsite-only — if one is
            // here, something took the local file and it should come back.
            $dir = \dirname($path);

            if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
                return ApiResponse::error('Cannot create the backup directory to retrieve into.', 500);
            }

            $got = $this->destinations->retrieve((string) $row['filename'], $path);

            if ($got['ok'] !== true) {
                // Retention is the benign explanation. The other one is far
                // more common and far worse: the backup directory has no
                // persistent volume behind it, so the row survived in the
                // database and the archive died with the container on the last
                // deploy. Both are named, because a message that only mentions
                // pruning sends people looking at their retention setting for a
                // storage problem.
                return ApiResponse::error(
                    'This backup is recorded but the archive is not on disk and no destination could supply it. '
                    . ($got['error'] ?? '')
                    . ' Either retention pruned it everywhere, or the backup directory is not on a persistent '
                    . 'volume — in which case every archive is lost when the container restarts. Check that '
                    . 'BACKUP_DIR (or the app-backups volume) points at storage that survives a deploy, then '
                    . 'take a fresh backup.',
                    410,
                );
            }

            $retrievedFrom = $got['from'];
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            return ApiResponse::error('Could not open the archive.', 500);
        }

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.download',
            entityType: 'Backup',
            entityId: $id,
            newValues: [
                'filename' => $row['filename'],
                'includes_config' => (int) $row['includes_config'],
                // Worth recording: a download that had to reach offsite storage
                // is also evidence the local copy is gone.
                'retrieved_from' => $retrievedFrom,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return new Response(200, [
            'Content-Type' => 'application/gzip',
            'Content-Length' => (string) filesize($path),
            'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
            'Cache-Control' => 'no-store',
        ], $stream);
    }
}
