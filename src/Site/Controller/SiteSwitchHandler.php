<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/switch-site — Switch the active site context.
 *
 * Validates ownership server-side, sets the site_id cookie,
 * and redirects back to the referring page.
 */
final class SiteSwitchHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly DashboardService $dashboardService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $body = $request->getParsedBody();
        $requestedSiteId = (int) ($body['site_id'] ?? 0);

        // Validate ownership: resolveSiteId checks the site belongs to this user
        $cookies = $request->getCookieParams();
        // Temporarily inject the requested site_id as if it were the cookie value
        $cookies['site_id'] = (string) $requestedSiteId;

        $resolved = $this->dashboardService->resolveSiteId($user, $cookies);
        $siteId = (int) $resolved['siteId'];

        // Determine redirect target from Referer, falling back to /
        $referer = $request->getHeaderLine('Referer');
        $redirectTo = '/';

        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            if (\is_string($path) && $path !== '') {
                $query = parse_url($referer, PHP_URL_QUERY);
                // Strip any existing site_id from query string
                if (\is_string($query)) {
                    parse_str($query, $params);
                    unset($params['site_id']);
                    $query = http_build_query($params);
                }
                $redirectTo = $path . ($query ? '?' . $query : '');
            }
        }

        $response = ApiResponse::redirect($redirectTo);

        return $response->withAddedHeader(
            'Set-Cookie',
            'site_id=' . $siteId . '; Path=/; SameSite=Lax; Max-Age=31536000',
        );
    }
}
