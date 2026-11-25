<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupJobService;
use OCI\Admin\Service\BackupOverviewService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /admin/backup/run — enqueue a backup now.
 *
 * The "prove it works" button. Enqueues rather than running inline: a real
 * install's dump outlasts max_execution_time, and a button that times out
 * teaches you to distrust the feature.
 */
final class BackupRunHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly BackupJobService $jobs,
        private readonly BackupOverviewService $overview,
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

        // Tokens are single-use, so every response hands back a fresh one or
        // the second click on this page dies with "session expired".
        $fresh = fn (): array => ['csrf_token' => $this->csrf->generate('admin_backup')];

        if (!$this->csrf->validate((string) ($body['_csrf_token'] ?? ''), 'admin_backup')) {
            return ApiResponse::error('Session expired. Please refresh and try again.', 403, [], $fresh());
        }

        $includeConfig = ($body['include_config'] ?? '') === '1';

        if (!$this->jobs->request('manual', $includeConfig)) {
            return ApiResponse::error('A backup is already running.', 409, [], $fresh());
        }

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.run',
            entityType: 'Backup',
            newValues: ['trigger' => 'manual', 'include_config' => $includeConfig],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success([
            'queued' => true,
            'overview' => $this->presentableOverview(),
        ] + $fresh());
    }

    /** @return array<string, mixed> */
    private function presentableOverview(): array
    {
        $o = $this->overview->overview();
        $o['last_success_at'] = $o['last_success_at']?->format('c');
        $o['next_run'] = $o['next_run']?->format('c');

        return $o;
    }
}
