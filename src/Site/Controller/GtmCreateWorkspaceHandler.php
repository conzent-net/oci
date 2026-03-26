<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/gtm/workspaces/create — AJAX: create a "Conzent Workspace".
 *
 * Body: account_id, container_id
 * Returns the updated list of workspaces.
 */
final class GtmCreateWorkspaceHandler implements RequestHandlerInterface
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

        $body = (array) ($request->getParsedBody() ?? []);
        $accountId = (string) ($body['account_id'] ?? '');
        $containerId = (string) ($body['container_id'] ?? '');

        if ($accountId === '' || $containerId === '') {
            return ApiResponse::error('account_id and container_id are required', 422);
        }

        $result = $this->gtmService->createWorkspace($token, $accountId, $containerId, 'Conzent Workspace');
        if ($result === null) {
            return ApiResponse::error('Failed to create workspace', 500);
        }

        // Return fresh workspace list
        $workspaces = $this->gtmService->listWorkspaces($token, $accountId, $containerId);

        return ApiResponse::success([
            'workspaces' => $workspaces,
            'created' => $result,
        ]);
    }
}
