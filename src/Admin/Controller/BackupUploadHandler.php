<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupService;
use OCI\Admin\Service\RestoreService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * POST /admin/backup/upload — accept an archive taken on another server.
 *
 * This is the half that makes a backup portable: take one here, upload it
 * there, restore. The upload is stored and inspected but NOT restored — the
 * response describes what the archive contains so the operator can look before
 * committing, and a second, separately-confirmed call starts the restore.
 *
 * Restoring an uploaded archive is arbitrary SQL execution by definition, which
 * is why it is admin-only, audit-logged, and never automatic.
 */
final class BackupUploadHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BackupService $backupService,
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

        $files = $request->getUploadedFiles();
        $file = $files['archive'] ?? null;

        if (!$file instanceof UploadedFileInterface) {
            return ApiResponse::error('Choose a .tar.gz archive to upload.', 422, [], $fresh());
        }

        if ($file->getError() !== \UPLOAD_ERR_OK) {
            // A silent truncation at the web-server limit is the classic way an
            // upload "succeeds" and then fails to restore, so name it.
            return ApiResponse::error($this->uploadErrorMessage($file->getError()), 422, [], $fresh());
        }

        $name = basename((string) $file->getClientFilename());
        if (!str_ends_with(strtolower($name), '.tar.gz')) {
            return ApiResponse::error('That is not a .tar.gz archive.', 422, [], $fresh());
        }

        $dir = $this->backupService->backupDir() . '/uploaded';
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            return ApiResponse::error('Could not create the upload directory.', 500, [], $fresh());
        }

        // Never trust the client filename as a path. Keep it only as a label.
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'upload.tar.gz';
        $target = $dir . '/' . gmdate('Ymd-His') . '-' . $safe;

        try {
            $file->moveTo($target);
        } catch (\Throwable $e) {
            return ApiResponse::error('Upload failed: ' . $e->getMessage(), 500, [], $fresh());
        }

        @chmod($target, 0o600);

        $info = $this->restore->inspect($target);

        if ($info['ok'] !== true) {
            @unlink($target);

            return ApiResponse::error(
                (string) ($info['error'] ?? 'That archive could not be read.'),
                422,
                [],
                $fresh(),
            );
        }

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.upload',
            entityType: 'Backup',
            newValues: [
                'filename' => basename($target),
                'bytes' => (int) filesize($target),
                'databases' => $info['databases'],
                'format' => $info['format'],
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success([
            'token' => basename($target),
            'size_bytes' => (int) filesize($target),
            'sha256' => hash_file('sha256', $target),
            'inspect' => $info,
        ] + $fresh());
    }

    private function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'The archive is larger than this server accepts. '
                . 'Raise upload_max_filesize and post_max_size in docker/php/php.ini, or restore from the command line.',
            \UPLOAD_ERR_PARTIAL => 'The upload was cut off before it finished — the archive would be truncated.',
            \UPLOAD_ERR_NO_FILE => 'No file was received.',
            \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE => 'The server could not write the upload to disk.',
            default => 'The upload failed (code ' . $code . ').',
        };
    }
}
