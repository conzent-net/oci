<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AccountExportJobService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/account/export — queue a full export of the caller's account.
 * GET  /app/account/export — the caller's export status.
 */
final class AccountExportRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AccountExportJobService $jobs,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) $user['id'];

        $status = strtoupper($request->getMethod()) === 'POST'
            ? $this->jobs->request($userId)
            : $this->jobs->status($userId);

        unset($status['path']); // server filesystem detail, not for the client

        return ApiResponse::success($status);
    }
}
