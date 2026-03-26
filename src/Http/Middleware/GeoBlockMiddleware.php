<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use OCI\Infrastructure\GeoIp\GeoIpService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Blocks access from sanctioned countries (currently Russia).
 *
 * Shows a static information page instead of the login/register forms.
 * Must run early in the middleware pipeline — before session/auth.
 */
final class GeoBlockMiddleware implements MiddlewareInterface
{
    private const BLOCKED_COUNTRIES = ['ru'];

    public function __construct(
        private readonly GeoIpService $geoIpService,
        private readonly Environment $twig,
    ) {}

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $ip = $this->resolveClientIp($request);
        $geo = $this->geoIpService->lookup($ip);

        if (\in_array($geo['country'], self::BLOCKED_COUNTRIES, true)) {
            $html = $this->twig->render('pages/auth/blocked.html.twig');
            return ApiResponse::html($html, 403);
        }

        return $next($request);
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
        if ($forwardedFor !== '') {
            $ips = array_map('trim', explode(',', $forwardedFor));
            return $ips[0];
        }

        $realIp = $request->getHeaderLine('X-Real-IP');
        if ($realIp !== '') {
            return $realIp;
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}
