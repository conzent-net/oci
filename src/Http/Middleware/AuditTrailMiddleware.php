<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Admin\Service\AuditLogService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Records every state-changing authenticated request in the audit log:
 * who, what, when, from where. Runs after AuthMiddleware so the user is
 * known; logs only requests that succeeded (the handler's own validation
 * failures are not state changes).
 *
 * This is the coverage floor — domain handlers still write their own
 * granular entries (entity ids, old/new values) on top. Part of the open
 * core: both editions get the same audit trail.
 */
final class AuditTrailMiddleware implements MiddlewareInterface
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** Read-shaped POST endpoints that would only add noise. */
    private const EXCLUDED_PATHS = [
        '/app/consents/analytics',
    ];

    public function __construct(
        private readonly AuditLogService $auditLog,
    ) {}

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $response = $next($request);

        $method = strtoupper($request->getMethod());
        if (!\in_array($method, self::MUTATING_METHODS, true)) {
            return $response;
        }

        $user = $request->getAttribute('user');
        if ($user === null || $response->getStatusCode() >= 400) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        if (\in_array($path, self::EXCLUDED_PATHS, true)) {
            return $response;
        }

        $this->auditLog->log(
            userId: (int) $user['id'],
            action: strtolower($method),
            entityType: 'Request',
            newValues: ['path' => $path, 'status' => $response->getStatusCode()],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        return $response;
    }
}
