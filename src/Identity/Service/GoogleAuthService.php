<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

/**
 * Google OAuth2 service for user authentication (Sign in with Google).
 *
 * Uses raw cURL — no google/apiclient dependency.
 * Separate from GtmOAuthService which handles Tag Manager API access.
 */
final class GoogleAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const SCOPE = 'openid email profile';

    private function getClientId(): string
    {
        return $_ENV['GOOGLE_CLIENT_ID'] ?? '';
    }

    private function getClientSecret(): string
    {
        return $_ENV['GOOGLE_CLIENT_SECRET'] ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    /**
     * Build the Google OAuth2 consent URL for user login.
     */
    public function getAuthUrl(string $redirectUri, string $state = ''): string
    {
        $params = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        if ($state !== '') {
            $params['state'] = $state;
        }

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     *
     * @return array{access_token: string, expires_in: int, token_type: string}|null
     */
    public function exchangeCode(string $code, string $redirectUri): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::TOKEN_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !\is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded) || !isset($decoded['access_token'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * Fetch the authenticated user's profile from Google.
     *
     * @return array{id: string, email: string, name: string, given_name: string, family_name: string, picture: string}|null
     */
    public function getUserProfile(string $accessToken): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::USERINFO_URL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !\is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        if (!\is_array($decoded) || !isset($decoded['id'], $decoded['email'])) {
            return null;
        }

        return [
            'id' => (string) $decoded['id'],
            'email' => (string) $decoded['email'],
            'name' => (string) ($decoded['name'] ?? ''),
            'given_name' => (string) ($decoded['given_name'] ?? ''),
            'family_name' => (string) ($decoded['family_name'] ?? ''),
            'picture' => (string) ($decoded['picture'] ?? ''),
        ];
    }
}
