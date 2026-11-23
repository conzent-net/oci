<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\GtmOAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/gtm/accounts — List GTM accounts (JSON).
 * GET /app/gtm/containers?account_id=X — List containers in an account (JSON).
 *
 * Requires a valid GTM access token in the session.
 */
final class GtmAccountsHandler implements RequestHandlerInterface
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

        $accessToken = $_SESSION['gtm_access_token'] ?? '';
        $expires = (int) ($_SESSION['gtm_token_expires'] ?? 0);

        if ($accessToken === '' || $expires < time()) {
            return ApiResponse::error('Google session expired. Please sign in again.', 401);
        }

        $params = $request->getQueryParams();
        $accountId = $params['account_id'] ?? '';

        // If account_id provided → list containers; otherwise list accounts
        if ($accountId !== '') {
            $containers = $this->gtmService->listContainers($accessToken, $accountId);
            return ApiResponse::success(['containers' => $containers]);
        }

        $accounts = $this->gtmService->listAccounts($accessToken);
        return ApiResponse::success(['accounts' => $accounts]);
    }
}
