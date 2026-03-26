<?php

declare(strict_types=1);

namespace OCI\Http\Handler;

use Doctrine\DBAL\Connection;
use OCI\Http\Response\ApiResponse;
use Predis\Client as RedisClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /health — Application health check.
 * Used by Docker healthcheck, load balancers, and monitoring.
 */
final class HealthCheckHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Connection $db,
        private readonly RedisClient $redis,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $dbOk = false;
        $redisOk = false;

        try {
            $this->db->fetchOne('SELECT 1');
            $dbOk = true;
        } catch (\Throwable) {
            // DB unreachable
        }

        try {
            $this->redis->ping();
            $redisOk = true;
        } catch (\Throwable) {
            // Redis unreachable
        }

        $healthy = $dbOk && $redisOk;

        return ApiResponse::success([
            'status' => $healthy ? 'ok' : 'degraded',
            'services' => [
                'database' => $dbOk,
                'redis' => $redisOk,
            ],
            'environment' => $_ENV['APP_ENV'] ?? 'unknown',
            'timestamp' => date('c'),
        ], $healthy ? 200 : 503);
    }
}
