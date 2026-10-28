<?php

declare(strict_types=1);

namespace OCI\Compliance\Service;

use Psr\Log\LoggerInterface;

/**
 * Default GvlHttpClientInterface implementation using PHP streams.
 *
 * Mirrors the stream_context_create approach already used by
 * {@see \OCI\Identity\Service\CmpValidationService} so the GVL updater adds
 * no new HTTP dependency.
 */
final class StreamGvlHttpClient implements GvlHttpClientInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function get(string $url, int $timeoutSeconds = 20): ?string
    {
        $context = stream_context_create([
            'http' => [
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
                'user_agent'    => 'Conzent-CMP/GVL-Updater',
                'header'        => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            $this->logger->warning("GVL fetch failed (network error): {$url}");

            return null;
        }

        // $http_response_header is populated by the stream wrapper in the local scope.
        $status = $this->statusFrom($http_response_header ?? []);

        if ($status !== null && ($status < 200 || $status >= 300)) {
            $this->logger->warning("GVL fetch failed (HTTP {$status}): {$url}");

            return null;
        }

        return $body;
    }

    /**
     * @param list<string> $headers
     */
    private function statusFrom(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                // Keep scanning — redirects mean several status lines, and the
                // last one is the response we actually received.
                $status = (int) $m[1];
            }
        }

        return $status ?? null;
    }
}
