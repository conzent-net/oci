<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Service\SiteClaimService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_array;

/**
 * POST /api/v1/plugin/claim — a CMS plugin asks for its website key by proving
 * it runs on the site's domain. Server-to-server, so no CORS header; every
 * answer is `Cache-Control: no-store`. The logic lives in SiteClaimService.
 *
 * The client IP is REMOTE_ADDR only. Behind the production proxy that is the
 * real client (nginx sets real_ip_header); a forwarded header from the
 * request itself would let a caller pick its own rate-limit bucket.
 */
final class SiteClaimHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteClaimService $claims,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $clientIp = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $userAgent = trim($request->getHeaderLine('User-Agent'));

        $result = $this->claims->claim($payload, $clientIp, $userAgent !== '' ? substr($userAgent, 0, 255) : null);

        $response = $result->ok
            ? ApiResponse::success($result->data)
            : ApiResponse::error($result->message, $result->status, $result->errors, ['code' => $result->code] + $result->extra);

        $response = $response->withHeader('Cache-Control', 'no-store');
        if ($result->code === SiteClaimService::CODE_RATE_LIMITED) {
            $response = $response->withHeader('Retry-After', (string) ($result->extra['retry_after'] ?? 60));
        }

        return $response;
    }
}
