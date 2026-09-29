<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/gtm/workspaces — AJAX: list workspaces for account + container.
 *
 * Query params: account_id, container_id
 * Returns JSON array of workspaces.
 */
final class GtmWorkspacesHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly GtmOAuthService $gtmService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $token = $_SESSION['gtm_access_token'] ?? null;
        if ($token === null || ($_SESSION['gtm_token_expires'] ?? 0) <= time()) {
            return ApiResponse::error('GTM session expired. Please re-authenticate.', 401);
        }

        $params = $request->getQueryParams();
        $accountId = (string) ($params['account_id'] ?? '');
        $containerId = (string) ($params['container_id'] ?? '');

        if ($accountId === '' || $containerId === '') {
            return ApiResponse::error('account_id and container_id are required', 422);
        }

        $workspaces = $this->gtmService->listWorkspaces($token, $accountId, $containerId);

        // Check if a "Conzent Workspace" already exists
        $hasConzentWorkspace = false;
        foreach ($workspaces as $ws) {
            if ($ws['name'] === 'Conzent Workspace') {
                $hasConzentWorkspace = true;
                break;
            }
        }

        return ApiResponse::success([
            'workspaces' => $workspaces,
            'hasConzentWorkspace' => $hasConzentWorkspace,
        ]);
    }
}
