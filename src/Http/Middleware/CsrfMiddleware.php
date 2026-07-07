<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CSRF protection for POST/PUT/DELETE requests.
 *
 * Expects a _csrf_token field in the request body.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CsrfService $csrf,
    ) {}

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        // Only check state-changing methods
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $body = $request->getParsedBody();
            $token = $body['_csrf_token'] ?? '';

            if (!$this->csrf->validate($token)) {
                return ApiResponse::error('Invalid CSRF token. Please refresh and try again.', 403);
            }
        }

        return $next($request);
    }
}
