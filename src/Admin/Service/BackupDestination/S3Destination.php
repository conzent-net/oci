<?php

declare(strict_types=1);

namespace OCI\Admin\Service\BackupDestination;

use OCI\Admin\Service\ConfigSecretCipher;
use Psr\Log\LoggerInterface;

/**
 * Ships archives to any S3-compatible object store.
 *
 * Signs requests with SigV4 by hand rather than pulling in aws/aws-sdk-php.
 * The SDK drags in Guzzle — a dependency nothing else here uses — and a large
 * autoload surface for what amounts to PUT, GET, LIST and DELETE against one
 * bucket, in a codebase that is deliberately framework-free and built by every
 * self-hoster. It is also tuned for AWS proper, while the targets that actually
 * matter here are Hetzner Object Storage, Cloudflare R2, Backblaze B2, Wasabi
 * and MinIO. A minimal signer is more portable precisely because it carries no
 * AWS-specific endpoint assumptions.
 *
 * Two deliberate limits:
 *
 * - **path_style is a setting, not a guess.** MinIO and several others require
 *   path-style addressing; AWS prefers virtual-hosted. Silently choosing one
 *   produces a 403 against half the providers, which is a miserable thing to
 *   debug from a backup log.
 * - **Single PUT only, capped at 5 GB** — S3's limit for a non-multipart
 *   upload. Multipart is deferred until an archive actually approaches it,
 *   rather than written speculatively; the cap fails loudly instead.
 */
final class S3Destination implements DestinationInterface
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const SERVICE = 's3';
    private const MAX_SINGLE_PUT = 5_000_000_000;
    private const TIMEOUT = 600;

    /**
     * @param array<string, string> $config enabled, endpoint, region, bucket,
     *                                      prefix, access_key, secret_key,
     *                                      path_style
     */
    public function __construct(
        private readonly array $config,
        private readonly ConfigSecretCipher $cipher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function name(): string
    {
        return 's3';
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? '0') === '1'
            && ($this->config['bucket'] ?? '') !== ''
            && ($this->config['endpoint'] ?? '') !== '';
    }

    public function put(string $localPath, string $remoteName): array
    {
        $size = (int) filesize($localPath);

        if ($size > self::MAX_SINGLE_PUT) {
            return $this->failure(sprintf(
                'This archive is %.1f GB, past the %.0f GB single-upload limit. Multipart upload is not implemented; '
                . 'use SFTP for archives this large.',
                $size / 1_000_000_000,
                self::MAX_SINGLE_PUT / 1_000_000_000,
            ));
        }

        $key = $this->key($remoteName);
        $body = file_get_contents($localPath);

        if ($body === false) {
            return $this->failure('Could not read the archive from disk.');
        }

        try {
            [$code, $response] = $this->signedRequest('PUT', $key, $body);
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        }

        if ($code < 200 || $code >= 300) {
            return $this->failure('Upload rejected (HTTP ' . $code . '): ' . $this->errorMessage($response));
        }

        return [
            'ok' => true,
            'remote_path' => $this->config['bucket'] . '/' . $key,
            'bytes' => $size,
            'error' => null,
        ];
    }

    public function test(): array
    {
        $key = $this->key('.conzent-write-probe');

        try {
            [$code, $response] = $this->signedRequest('PUT', $key, 'probe');

            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'message' => 'Write refused (HTTP ' . $code . '): ' . $this->errorMessage($response)];
            }

            $this->signedRequest('DELETE', $key, '');

            return [
                'ok' => true,
                'message' => 'Wrote and removed a test object in ' . $this->config['bucket']
                    . ($this->pathStyle() ? ' (path-style)' : ' (virtual-hosted)'),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * The stored size of one object, or null if it cannot be established.
     *
     * Read from the listing rather than a HEAD: curl waits for a body on a
     * CUSTOMREQUEST HEAD and stalls until the timeout, and the listing is
     * already implemented and exercised by the page.
     */
    private function remoteSize(string $name): ?int
    {
        foreach ($this->list() as $item) {
            if ($item['name'] === $name) {
                return $item['bytes'] === null ? null : (int) $item['bytes'];
            }
        }

        return null;
    }

    /**
     * Throws rather than returning [] when the listing cannot be read — see the
     * same note on SftpDestination::list(). "Unreachable" and "holds nothing"
     * must not collapse into the same answer.
     *
     * Paginated deliberately: ListObjectsV2 caps a response at 1,000 keys, and
     * stopping at the first page would report every archive past that point as
     * missing from storage. A bucket shared with other backups reaches that
     * sooner than the retention count suggests.
     */
    public function list(): array
    {
        $out = [];
        $token = null;
        $pages = 0;

        do {
            $query = ['list-type' => '2', 'prefix' => $this->prefix()];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }

            [$code, $body] = $this->signedRequest('GET', '', '', $query);

            if ($code < 200 || $code >= 300) {
                throw new \RuntimeException('Listing refused (HTTP ' . $code . '): ' . $this->errorMessage($body));
            }

            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                throw new \RuntimeException('The bucket listing could not be parsed.');
            }

            foreach ($xml->Contents ?? [] as $item) {
                $key = (string) $item->Key;
                if (!str_contains($key, 'conzent-') || !str_ends_with($key, '.tar.gz')) {
                    continue;
                }

                $out[] = [
                    'name' => basename($key),
                    'bytes' => (int) $item->Size,
                    'modified' => (string) $item->LastModified,
                ];
            }

            $token = ((string) ($xml->IsTruncated ?? 'false')) === 'true'
                ? (string) ($xml->NextContinuationToken ?? '')
                : null;
        } while ($token !== null && $token !== '' && ++$pages < 50);

        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['name'], (string) $a['name']));

        return $out;
    }

    public function fetch(string $remoteName, string $localPath): array
    {
        try {
            // Streamed to disk, never buffered: a self-hoster's archive can be
            // gigabytes, and holding one in a PHP string would exhaust the
            // memory limit long before the download finished.
            [$code, $body] = $this->signedRequest('GET', $this->key(basename($remoteName)), '', [], $localPath);

            if ($code < 200 || $code >= 300) {
                return ['ok' => false, 'bytes' => null, 'error' => 'Download refused (HTTP ' . $code . '): ' . $this->errorMessage($body)];
            }

            $local = (int) @filesize($localPath);
            $remoteSize = $this->remoteSize(basename($remoteName));

            // A truncated download restores a corrupt database, so refuse it
            // here rather than discovering it mid-import.
            if ($remoteSize !== null && $remoteSize !== $local) {
                @unlink($localPath);

                return ['ok' => false, 'bytes' => null, 'error' => sprintf(
                    'Download is %d bytes but the remote object is %d — incomplete, discarded.',
                    $local,
                    $remoteSize,
                )];
            }

            return ['ok' => true, 'bytes' => $local, 'error' => null];
        } catch (\Throwable $e) {
            @unlink($localPath);

            return ['ok' => false, 'bytes' => null, 'error' => $e->getMessage()];
        }
    }

    public function prune(int $keep): int
    {
        try {
            $prefix = $this->prefix();
            [$code, $body] = $this->signedRequest('GET', '', '', ['list-type' => '2', 'prefix' => $prefix]);

            if ($code < 200 || $code >= 300) {
                return 0;
            }

            $xml = @simplexml_load_string($body);
            if ($xml === false) {
                return 0;
            }

            $keys = [];
            foreach ($xml->Contents ?? [] as $item) {
                $k = (string) $item->Key;
                if (str_contains($k, 'conzent-') && str_ends_with($k, '.tar.gz')) {
                    $keys[] = $k;
                }
            }

            if (\count($keys) <= $keep) {
                return 0;
            }

            sort($keys);
            $removed = 0;

            foreach (\array_slice($keys, 0, \count($keys) - $keep) as $old) {
                [$c] = $this->signedRequest('DELETE', $old, '');
                if ($c >= 200 && $c < 300) {
                    ++$removed;
                }
            }

            return $removed;
        } catch (\Throwable $e) {
            $this->logger->warning('S3 prune failed: ' . $e->getMessage());

            return 0;
        }
    }

    // ── SigV4 ────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $query
     *
     * @return array{0: int, 1: string}
     */
    /**
     * @param string|null $sink when set, the response body is streamed to this
     *                          path instead of being held in memory — an
     *                          archive is far too big to buffer. The returned
     *                          body is then empty on success, and the (small)
     *                          error document on failure.
     *
     * @return array{0: int, 1: string}
     */
    private function signedRequest(string $method, string $key, string $payload, array $query = [], ?string $sink = null): array
    {
        $access = $this->secret('access_key');
        $secret = $this->secret('secret_key');

        if ($access === '' || $secret === '') {
            throw new \RuntimeException('No access key is stored for this destination.');
        }

        $endpoint = rtrim((string) $this->config['endpoint'], '/');
        $parts = parse_url($endpoint);
        if ($parts === false || !isset($parts['host'])) {
            throw new \RuntimeException('The endpoint URL could not be parsed: ' . $endpoint);
        }

        $scheme = $parts['scheme'] ?? 'https';
        $bucket = (string) $this->config['bucket'];
        $region = (string) ($this->config['region'] ?? 'us-east-1');

        if ($this->pathStyle()) {
            $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $path = '/' . $bucket . ($key === '' ? '' : '/' . $this->encodePath($key));
        } else {
            $host = $bucket . '.' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            $path = $key === '' ? '/' : '/' . $this->encodePath($key);
        }

        ksort($query);
        $canonicalQuery = http_build_query($query, '', '&', \PHP_QUERY_RFC3986);

        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $payloadHash = hash('sha256', $payload);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $now,
        ];
        ksort($headers);

        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= $k . ':' . trim($v) . "\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $path,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $date . '/' . $region . '/' . self::SERVICE . '/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $now,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $access,
            $scope,
            $signedHeaders,
            $signature,
        );

        $url = $scheme . '://' . $host . $path . ($canonicalQuery === '' ? '' : '?' . $canonicalQuery);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialise the S3 request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: ' . $authorization,
                'x-amz-content-sha256: ' . $payloadHash,
                'x-amz-date: ' . $now,
                'Content-Type: application/gzip',
            ],
        ]);

        if ($payload !== '' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $fh = null;
        if ($sink !== null) {
            $fh = @fopen($sink, 'wb');
            if ($fh === false) {
                curl_close($ch);

                throw new \RuntimeException('Could not open the download target: ' . $sink);
            }

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_FILE, $fh);
        }

        $response = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($fh !== null) {
            fclose($fh);

            if ($response === false) {
                @unlink($sink);

                throw new \RuntimeException('S3 request failed: ' . $error);
            }

            // A refusal streams an error document to the file rather than the
            // object. Read it back as the message and drop the file, so a
            // failed GET never leaves an XML fragment posing as an archive.
            if ($code < 200 || $code >= 300) {
                $body = (string) @file_get_contents($sink, false, null, 0, 8192);
                @unlink($sink);

                return [$code, $body];
            }

            return [$code, ''];
        }

        if ($response === false) {
            throw new \RuntimeException('S3 request failed: ' . $error);
        }

        return [$code, (string) $response];
    }

    /** Each path segment is encoded, but the separators are not. */
    private function encodePath(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    private function pathStyle(): bool
    {
        return ($this->config['path_style'] ?? '0') === '1';
    }

    private function prefix(): string
    {
        $p = trim((string) ($this->config['prefix'] ?? ''), '/');

        return $p === '' ? '' : $p . '/';
    }

    private function key(string $name): string
    {
        return $this->prefix() . $name;
    }

    private function secret(string $key): string
    {
        $stored = (string) ($this->config[$key] ?? '');
        if ($stored === '') {
            return '';
        }

        $plain = $this->cipher->decrypt($stored);

        if ($plain === null) {
            throw new \RuntimeException(
                'The stored ' . str_replace('_', ' ', $key) . ' cannot be decrypted on this host. '
                . 'Expected after restoring onto a new server — the key comes from APP_SECRET, which archives '
                . 'do not carry. Re-enter the credential to fix it.',
            );
        }

        return $plain;
    }

    private function errorMessage(string $xmlBody): string
    {
        $xml = @simplexml_load_string($xmlBody);

        if ($xml !== false && isset($xml->Message)) {
            return (string) $xml->Message;
        }

        return mb_substr(strip_tags($xmlBody), 0, 200) ?: 'no detail returned';
    }

    /** @return array{ok: bool, remote_path: null, bytes: null, error: string} */
    private function failure(string $message): array
    {
        return ['ok' => false, 'remote_path' => null, 'bytes' => null, 'error' => $message];
    }
}
