<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use Nyholm\Psr7\Response;
use OCI\Http\Handler\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /status.json — public service-status feed for the getconzent.com
 * status page. No auth, CORS-open by design: the page fetches it
 * cross-origin, and "the feed is unreachable" is itself the signal the
 * status page renders as a platform outage.
 *
 * Serves what the scheduler last wrote (var/status.json); freshness is
 * judged by the consumer from generated_at.
 */
final class StatusFeedHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $this->basePath . '/var/status.json';
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        if ($body === '') {
            // The scheduler has not produced a feed yet — answer honestly
            // rather than 404: the page can tell "no data yet" from "down".
            $body = json_encode([
                'schema_version' => 1,
                'generated_at' => null,
                'status' => 'unknown',
                'components' => [],
            ], JSON_THROW_ON_ERROR);
        }

        return new Response(200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'no-store, max-age=0',
        ], $body);
    }
}
