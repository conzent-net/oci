<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Redirects authenticated users away from guest-only pages (login, register).
 *
 * Must run after SessionMiddleware.
 */
final class GuestOnlyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if ($user !== null) {
            return ApiResponse::redirect('/');
        }

        return $next($request);
    }
}
