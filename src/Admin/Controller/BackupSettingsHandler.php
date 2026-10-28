<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupOverviewService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /admin/backup/settings — schedule, retention, and the config toggle.
 *
 * Saving a schedule is what arms BackupFreshnessService, so this handler is
 * also the moment an install starts being told when its backups stop.
 */
final class BackupSettingsHandler implements RequestHandlerInterface
{
    public function __construct(
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
        $fresh = fn (): array => ['csrf_token' => $this->csrf->generate('admin_backup')];

        if (!$this->csrf->validate((string) ($body['_csrf_token'] ?? ''), 'admin_backup')) {
            return ApiResponse::error('Session expired. Please refresh and try again.', 403, [], $fresh());
        }

        $enabled = ($body['schedule_enabled'] ?? '') === '1';
        $hourRaw = $body['schedule_hour'] ?? null;

        if ($enabled && ($hourRaw === null || $hourRaw === '' || !is_numeric($hourRaw))) {
            return ApiResponse::error('Choose an hour for the daily backup.', 422, [
                'schedule_hour' => 'Required when the schedule is on.',
            ], $fresh());
        }

        $retention = (int) ($body['retention'] ?? 7);
        if ($retention < 1 || $retention > 365) {
            return ApiResponse::error('Keep between 1 and 365 backups.', 422, [
                'retention' => 'Must be between 1 and 365.',
            ], $fresh());
        }

        $before = [
            'schedule_hour' => $this->overview->scheduleHour(),
            'retention' => $this->overview->retention(),
            'include_config' => $this->overview->includeConfig(),
        ];

        $includeConfig = ($body['include_config'] ?? '') === '1';

        $this->overview->saveSettings($enabled ? (int) $hourRaw : null, $retention, $includeConfig);

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'update',
            entityType: 'BackupSettings',
            oldValues: $before,
            newValues: [
                'schedule_hour' => $enabled ? (int) $hourRaw : null,
                'retention' => $retention,
                'include_config' => $includeConfig,
            ],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $o = $this->overview->overview();
        $o['last_success_at'] = $o['last_success_at']?->format('c');
        $o['next_run'] = $o['next_run']?->format('c');

        return ApiResponse::success(['overview' => $o] + $fresh());
    }
}
