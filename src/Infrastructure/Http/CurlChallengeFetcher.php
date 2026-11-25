<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Http;

use Psr\Log\LoggerInterface;

use const CURLE_COULDNT_CONNECT;
use const CURLE_COULDNT_RESOLVE_HOST;
use const CURLE_OPERATION_TIMEDOUT;
use const CURLE_PEER_FAILED_VERIFICATION;
use const CURLE_SSL_CACERT;
use const CURLE_SSL_CONNECT_ERROR;
use const CURLE_SSL_PEER_CERTIFICATE;
use const CURLE_WRITE_ERROR;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_REDIR_PROTOCOLS;
use const CURLOPT_RESOLVE;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLOPT_WRITEFUNCTION;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;

use function count;
use function in_array;
use function strlen;

/**
 * cURL implementation of the challenge fetch. The options mirror the
 * diagnostics HomepageFetcher with three deliberate differences: no redirect
 * handling at all, a 4 KB body cap (the responder answers with 64 characters),
 * and no retry without TLS verification. A site whose certificate does not
 * verify is reported as unreachable; nothing here ever talks to a host it
 * cannot verify.
 */
final class CurlChallengeFetcher implements ChallengeFetcherInterface
{
    public const CONNECT_TIMEOUT = 4;
    public const TOTAL_TIMEOUT = 8;
    public const MAX_BODY_BYTES = 4096;
    public const USER_AGENT = 'ConzentClaim/1.0 (+https://getconzent.com/)';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function fetch(string $url, array $pin): array
    {
        $headers = [];
        $body = '';
        $truncated = false;

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => ['Accept: text/plain, */*;q=0.1', 'Cache-Control: no-cache'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, &$truncated): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $body .= substr($chunk, 0, self::MAX_BODY_BYTES - strlen($body));
                    $truncated = true;
                    return -1; // abort the transfer, keep what we have
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];

        // Pin the validated IP so the connection goes where validation looked (anti-rebinding).
        $pinIp = (string) ($pin['pin_ip'] ?? '');
        if ($pinIp !== '') {
            $address = str_contains($pinIp, ':') ? '[' . $pinIp . ']' : $pinIp;
            $options[CURLOPT_RESOLVE] = [$pin['host'] . ':' . $pin['port'] . ':' . $address];
        }

        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errorMessage = curl_error($ch);
        curl_close($ch);

        // A deliberate abort past the byte cap is not an error.
        if ($errno === CURLE_WRITE_ERROR && $truncated) {
            $errno = 0;
        }

        if ($errno !== 0) {
            $reason = $this->reason($errno, $errorMessage);
            $this->logger->info('Claim challenge fetch failed: ' . $url . ' (' . $reason . ')');

            return [
                'ok' => false,
                'error' => $reason,
                'status_code' => 0,
                'headers' => $headers,
                'body' => $body,
                'truncated' => $truncated,
            ];
        }

        return [
            'ok' => true,
            'error' => '',
            'status_code' => $statusCode,
            'headers' => $headers,
            'body' => $body,
            'truncated' => $truncated,
        ];
    }

    private function reason(int $errno, string $message): string
    {
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            return 'timed out after ' . self::TOTAL_TIMEOUT . ' s';
        }
        if ($errno === CURLE_COULDNT_CONNECT) {
            return 'connection refused';
        }
        if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
            return 'host could not be resolved';
        }
        if (in_array($errno, [CURLE_SSL_CACERT, CURLE_SSL_PEER_CERTIFICATE, CURLE_SSL_CONNECT_ERROR, CURLE_PEER_FAILED_VERIFICATION], true)) {
            return 'TLS certificate could not be verified';
        }

        return $message !== '' ? $message : 'curl error ' . $errno;
    }
}
