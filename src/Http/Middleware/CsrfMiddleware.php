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
 * Accepts the token from a `_csrf_token` body field or an `X-CSRF-Token`
 * header. The header matters: a `fetch()` sending `application/json` has no
 * parsed body at all, so a body-only check rejects every JSON request with a
 * 403 that looks exactly like a genuine CSRF failure and sends whoever hits it
 * hunting for a session bug. Accepting the header costs nothing in strength —
 * a cross-origin page still cannot set a custom header without the CORS
 * preflight succeeding first, which is the property CSRF protection relies on.
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
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        if (!$this->csrf->validate($this->token($request))) {
            return ApiResponse::error('Invalid CSRF token. Please refresh and try again.', 403)
                ->withHeader('X-CSRF-Token', $this->csrf->generate());
        }

        // Tokens are single-use, and validate() has just spent this one. A page
        // that performs two actions without reloading — send an invite, then
        // send another — would fail the second with "invalid CSRF token", which
        // reads like a session problem and is nothing of the sort. Handing the
        // replacement back on every response means client code can rotate it
        // without each endpoint having to remember to include one in its body.
        return $next($request)->withHeader('X-CSRF-Token', $this->csrf->generate());
    }

    private function token(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body['_csrf_token']) && is_string($body['_csrf_token'])) {
            return $body['_csrf_token'];
        }

        return $request->getHeaderLine('X-CSRF-Token');
    }
}
