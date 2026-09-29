<?php

declare(strict_types=1);

namespace OCI\Http\Middleware;

use OCI\Agency\Service\AgencyAccessService;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Requires an approved, active agency, and puts its scope on the request.
 *
 * Today every agency controller repeats its own inline role check, and only two
 * of ten verify that the thing being asked for actually belongs to the caller.
 * That is ten chances to get it wrong and no structural reason anyone would
 * notice the eleventh. This collapses it to one place.
 *
 * Two things happen here, and the second is the useful one:
 *
 * 1. The request is refused unless the account is an **approved, active**
 *    agency. Role alone is not enough — a role survives a suspension, and the
 *    suspension is the whole point of having a revoke path.
 * 2. The verified {@see \OCI\Agency\Service\AgencyScope} is attached as the
 *    `agency_scope` attribute. Handlers take it from there and pass it to the
 *    scope repository, which is the only way to obtain cross-account data. So
 *    a handler cannot query wider than its scope even by accident: there is no
 *    method that would let it.
 *
 * **This class lives in public `src/Http/Middleware/` on purpose.**
 * `config/middleware.php` ships in the community edition and must never name a
 * class from the cloud-only module namespace, or the open-source build fatals
 * on boot. The community edition therefore carries an agency middleware group
 * it can never route to, which costs nothing and keeps the config
 * byte-identical across editions.
 *
 * (That namespace is deliberately not spelled out anywhere in this file. The
 * publish tooling's safety net is a grep for it across `src`, `bin` and
 * `config` that has to come back empty, and a mention in prose would show up
 * as a leak that somebody then has to triage by hand.)
 *
 * Must run after SessionMiddleware and AuthMiddleware.
 */
final class AgencyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AgencyAccessService $access,
    ) {
    }

    public function process(ServerRequestInterface $request, callable $next): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $scope = $this->access->resolve((int) $user['id']);

        if ($scope !== null) {
            return $next($request->withAttribute('agency_scope', $scope));
        }

        // Not approved. Distinguish "applied and waiting" from "never applied",
        // because sending a pending applicant to a 403 tells them they did
        // something wrong when they are simply in a queue.
        $row = $this->access->findAgencyRow((int) $user['id']);
        $status = $row['status'] ?? null;

        if (\in_array($status, ['pending', 'rejected', 'suspended'], true)) {
            return $this->wantsJson($request)
                ? ApiResponse::error($this->statusMessage((string) $status), 403)
                : ApiResponse::redirect('/agency/status');
        }

        return $this->wantsJson($request)
            ? ApiResponse::error('This account is not an agency.', 403)
            : ApiResponse::redirect('/agency/apply');
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            'pending' => 'Your partnership request is still under review.',
            'rejected' => 'This account is not an approved agency.',
            'suspended' => 'This agency account is suspended.',
            default => 'This account is not an approved agency.',
        };
    }

    /**
     * A redirect is the right answer for a page and useless for a fetch(), which
     * would follow it and render a login form into a JSON parser.
     */
    private function wantsJson(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true'
            || str_contains($request->getHeaderLine('Accept'), 'application/json')
            || str_contains($request->getHeaderLine('Content-Type'), 'application/json');
    }
}
