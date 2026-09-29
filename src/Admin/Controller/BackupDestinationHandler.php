<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Admin\Service\BackupDestinationConfig;
use OCI\Admin\Service\BackupDestinationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /admin/backup/destination — save offsite settings, or test one.
 *
 * The test is click-to-run, never automatic. Nothing else in this codebase
 * makes a live remote call while rendering a page, and a settings screen that
 * hangs on an unreachable SFTP host helps nobody. It also does a real WRITE
 * rather than just authenticating: a login that succeeds proves nothing about
 * quota or directory permissions, which is where offsite backups actually fail.
 */
final class BackupDestinationHandler implements RequestHandlerInterface
{
    private const ALLOWED = ['sftp', 's3'];

    public function __construct(
        private readonly BackupDestinationConfig $config,
        private readonly BackupDestinationService $destinations,
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

        $destination = (string) ($body['destination'] ?? '');
        if (!\in_array($destination, self::ALLOWED, true)) {
            return ApiResponse::error('Unknown destination.', 422, [], $fresh());
        }

        $action = ($body['action'] ?? 'save') === 'test' ? 'test' : 'save';

        if ($action === 'save') {
            /** @var array<string, string> $body */
            $this->config->save($destination, $body);

            $this->audit->log(
                userId: (int) $user['id'],
                action: 'update',
                entityType: 'BackupDestination',
                // Never the values. This is the one audit entry that would
                // otherwise record storage credentials in plain text.
                newValues: ['destination' => $destination, 'enabled' => ($body['enabled'] ?? '0') === '1'],
                ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );

            return ApiResponse::success([
                'saved' => true,
                'settings' => $this->config->forDisplay($destination),
                'note' => 'Saved. Nothing is verified until you run a connection test.',
            ] + $fresh());
        }

        $target = $this->destinations->get($destination);
        if ($target === null) {
            return ApiResponse::error('That destination is not available.', 422, [], $fresh());
        }

        $result = $target->test();

        $this->audit->log(
            userId: (int) $user['id'],
            action: 'backup.destination_test',
            entityType: 'BackupDestination',
            newValues: ['destination' => $destination, 'ok' => $result['ok']],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return ApiResponse::success(['test' => $result] + $fresh());
    }
}
