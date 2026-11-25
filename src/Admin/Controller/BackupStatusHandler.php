<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\BackupOverviewService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /admin/backup/status — JSON for the page's poller.
 *
 * Read-only, so no CSRF: it changes nothing and leaks nothing an admin
 * cannot already see on the page it serves.
 */
final class BackupStatusHandler implements RequestHandlerInterface
{
    public function __construct(private readonly BackupOverviewService $overview)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getAttribute('user') === null) {
            return ApiResponse::error('Not authenticated', 401);
        }

        $o = $this->overview->overview();
        $o['last_success_at'] = $o['last_success_at']?->format('c');
        $o['next_run'] = $o['next_run']?->format('c');

        return ApiResponse::success([
            'overview' => $o,
            'recent' => array_map(
                static fn (array $r): array => [
                    'id' => (int) $r['id'],
                    'filename' => $r['filename'],
                    'status' => $r['status'],
                    'trigger' => $r['trigger_type'],
                    'size_bytes' => $r['size_bytes'] === null ? null : (int) $r['size_bytes'],
                    'started_at' => $r['started_at'],
                    'finished_at' => $r['finished_at'],
                    'error' => $r['error'],
                    'destinations' => array_map(
                        static fn (array $d): array => [
                            'destination' => $d['destination'],
                            'status' => $d['status'],
                            'error' => $d['error'],
                        ],
                        $r['destinations'] ?? [],
                    ),
                ],
                $this->overview->history(5),
            ),
        ]);
    }
}
