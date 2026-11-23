<?php

declare(strict_types=1);

namespace OCI\Http\Handler;

use OCI\Admin\Repository\InstallEventRepositoryInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /ping — Anonymous installer telemetry endpoint.
 *
 * Called by the one-line installer (install.sh) to record
 * install and update events. No authentication required.
 * Returns a 1x1 transparent GIF to keep the response tiny.
 */
final class InstallPingHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly InstallEventRepositoryInterface $repo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $event = $params['e'] ?? '';

        if ($event !== 'install' && $event !== 'update') {
            return ApiResponse::noContent();
        }

        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? '';
        $ipHash = $ip !== '' ? hash('sha256', $ip) : null;

        $this->repo->insert([
            'event' => $event,
            'ip_hash' => $ipHash,
            'country' => null,
            'version' => $params['v'] ?? null,
        ]);

        return ApiResponse::noContent();
    }
}
