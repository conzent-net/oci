<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\MaintenanceLockService;
use OCI\Admin\Service\RestoreJobService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin/backup/restore/status — the restore poller.
 *
 * The one route exempted from the maintenance gate in Application::handle(),
 * so an admin can watch the operation that put the app into maintenance. It
 * reads the status file and the lock file and nothing else — no repositories,
 * no queries — because the database is the thing being replaced.
 */
final class RestoreStatusHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly RestoreJobService $jobs,
        private readonly MaintenanceLockService $lock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('user') === null) {
            return ApiResponse::error('Not authenticated', 401);
        }

        return ApiResponse::success([
            'restore' => $this->jobs->status(),
            'maintenance' => $this->lock->read(),
            'held_for_seconds' => $this->lock->heldForSeconds(),
        ]);
    }
}
