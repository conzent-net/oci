<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use OCI\Identity\Exception\EndpointrException;
use Psr\Log\LoggerInterface;

/**
 * cURL client for Endpointr's email-verification API.
 *
 * Caches the bearer token in-process until 60s before expiry. Each verifyEmail
 * call returns the decoded `data` envelope from the API response.
 */
final class EndpointrClient
{
    private const BASE_URL = 'https://api.endpointr.com';
    private const TOKEN_TTL_SAFETY_SECONDS = 60;

    private ?string $token = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {}

    /**
     * Verify a single email address.
     *
     * @return array<string, mixed> Reoon-shaped record with at least a `status` field.
     */
    public function verifyEmail(string $email): array
    {
        $payload = $this->request(
            'POST',
            '/v1/verifications',
            ['kind' => 'email', 'value' => $email],
            $this->getToken(),
        );

        if (!isset($payload['data']) || !\is_array($payload['data'])) {
            throw new EndpointrException('Endpointr verifications response missing data envelope');
        }

        return $payload['data'];
    }

    /**
     * Fetch a vault entry by name and return the inner secrets dict.
     *
     * The response is double-wrapped:
     *   {data: {name: "...", data: {<secrets>}}}
     * We unwrap the outer envelope and the inner vault-entry, returning the
     * raw secret map (e.g. smtp_host/smtp_user/smtp_pass for amazon_ses).
     *
     * @return array<string, mixed>
     */
    public function getVaultEntry(string $name): array
    {
        $path = '/v1/vault/' . rawurlencode($name);
        $payload = $this->request('GET', $path, null, $this->getToken());

        $entry = $payload['data'] ?? null;
        if (!\is_array($entry)) {
            throw new EndpointrException("Endpointr {$path} response missing data envelope");
        }

        $secrets = $entry['data'] ?? null;
        if (!\is_array($secrets)) {
            throw new EndpointrException("Endpointr {$path} vault entry missing inner data field");
        }

        return $secrets;
    }

    private function getToken(): string
    {
        if ($this->token !== null && time() < ($this->tokenExpiresAt - self::TOKEN_TTL_SAFETY_SECONDS)) {
            return $this->token;
        }

        if ($this->apiKey === '') {
            throw new EndpointrException('ENDPOINTR_API_KEY is not configured');
        }

        $payload = $this->request('POST', '/v1/token', ['api_key' => $this->apiKey], null);

        $data = $payload['data'] ?? null;
        if (!\is_array($data) || !isset($data['token'], $data['expires_in'])) {
            throw new EndpointrException('Endpointr token response missing token/expires_in');
        }

        $this->token = (string) $data['token'];
        $this->tokenExpiresAt = time() + (int) $data['expires_in'];

        return $this->token;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, ?string $bearer): array
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }
        if ($bearer !== null) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $opts = [
            CURLOPT_URL => self::BASE_URL . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if (!\is_string($response)) {
            $this->logger->error('Endpointr request failed (transport)', ['path' => $path, 'curl_error' => $curlError]);
            throw new EndpointrException("Endpointr {$path} transport failure: {$curlError}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('Endpointr request failed (http)', ['path' => $path, 'status' => $httpCode, 'body' => mb_substr($response, 0, 500)]);
            throw new EndpointrException("Endpointr {$path} returned HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded)) {
            throw new EndpointrException("Endpointr {$path} returned non-JSON body");
        }

        return $decoded;
    }
}
