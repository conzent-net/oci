<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use Doctrine\DBAL\Connection;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\MatomoApiService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/matomo/validate — Validate Matomo credentials and return sites list.
 *
 * Stores validated credentials in the session and persists to user record.
 */
final class MatomoValidateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly MatomoApiService $matomoApi,
        private readonly Connection $db,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $matomoUrl = trim((string) ($body['matomo_url'] ?? ''));
        $matomoToken = trim((string) ($body['matomo_token'] ?? ''));

        // On page reload, the frontend doesn't have the token — fall back to session
        if ($matomoToken === '' || $matomoToken === '(session)') {
            $matomoToken = (string) ($_SESSION['matomo_token'] ?? '');
        }
        if ($matomoUrl === '' && isset($_SESSION['matomo_url'])) {
            $matomoUrl = (string) $_SESSION['matomo_url'];
        }

        if ($matomoUrl === '' || $matomoToken === '') {
            return ApiResponse::error('Matomo URL and API Token are required.', 422);
        }

        // Validate URL format
        if (!filter_var($matomoUrl, \FILTER_VALIDATE_URL)) {
            return ApiResponse::error('Invalid Matomo URL format.', 422);
        }

        // Test credentials
        $valid = $this->matomoApi->validateCredentials($matomoUrl, $matomoToken);
        if (!$valid) {
            return ApiResponse::error('Could not connect to Matomo. Check your URL and API token.', 401);
        }

        // Store in session for subsequent wizard calls
        $_SESSION['matomo_url'] = $matomoUrl;
        $_SESSION['matomo_token'] = $matomoToken;

        // Persist to user record so credentials survive logout
        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $this->db->update('oci_users', [
                'matomo_url' => $matomoUrl,
                'matomo_token' => $matomoToken,
            ], ['id' => $userId]);
        }

        // Return sites list
        $sites = $this->matomoApi->listSites($matomoUrl, $matomoToken);

        return ApiResponse::success([
            'connected' => true,
            'sites' => $sites,
        ]);
    }
}
