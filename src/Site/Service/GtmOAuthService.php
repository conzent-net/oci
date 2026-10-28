<?php

declare(strict_types=1);

namespace OCI\Site\Service;

/**
 * Lightweight Google OAuth2 + Tag Manager API client.
 *
 * Uses raw cURL instead of the heavy google/apiclient package.
 * Supports both read-only operations (account/container listing) and
 * write operations (creating tags, triggers, variables, templates)
 * needed by the GTM Wizard.
 */
final class GtmOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const TAG_MANAGER_BASE = 'https://www.googleapis.com/tagmanager/v2';
    private const SCOPE = 'https://www.googleapis.com/auth/tagmanager.edit.containers';

    public function getClientId(): string
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
     * Build the Google OAuth2 consent URL.
     */
    public function getAuthUrl(string $redirectUri, string $state = ''): string
    {
        $params = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'online',
            'prompt' => 'consent',
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
        $response = $this->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if ($response === null || !isset($response['access_token'])) {
            return null;
        }

        return $response;
    }

    /**
     * List all GTM accounts the authenticated user has access to.
     *
     * @return list<array{accountId: string, name: string}>
     */
    public function listAccounts(string $accessToken): array
    {
        $data = $this->get(self::TAG_MANAGER_BASE . '/accounts', $accessToken);
        if ($data === null || !isset($data['account'])) {
            return [];
        }

        $accounts = [];
        foreach ($data['account'] as $account) {
            $accounts[] = [
                'accountId' => $account['accountId'] ?? '',
                'name' => $account['name'] ?? '',
            ];
        }

        return $accounts;
    }

    /**
     * List all containers in a GTM account.
     *
     * @return list<array{containerId: string, publicId: string, name: string}>
     */
    public function listContainers(string $accessToken, string $accountId): array
    {
        $url = self::TAG_MANAGER_BASE . '/accounts/' . urlencode($accountId) . '/containers';
        $data = $this->get($url, $accessToken);
        if ($data === null || !isset($data['container'])) {
            return [];
        }

        $containers = [];
        foreach ($data['container'] as $container) {
            $containers[] = [
                'containerId' => $container['containerId'] ?? '',
                'publicId' => $container['publicId'] ?? '', // GTM-XXXXXXX
                'name' => $container['name'] ?? '',
            ];
        }

        return $containers;
    }

    // ─── Workspace operations ────────────────────────────────

    /**
     * List all workspaces in a container.
     *
     * @return list<array{workspaceId: string, name: string}>
     */
    public function listWorkspaces(string $accessToken, string $accountId, string $containerId): array
    {
        $url = self::TAG_MANAGER_BASE . '/accounts/' . urlencode($accountId)
            . '/containers/' . urlencode($containerId) . '/workspaces';
        $data = $this->get($url, $accessToken);
        if ($data === null || !isset($data['workspace'])) {
            return [];
        }

        $workspaces = [];
        foreach ($data['workspace'] as $ws) {
            $workspaces[] = [
                'workspaceId' => $ws['workspaceId'] ?? '',
                'name' => $ws['name'] ?? '',
            ];
        }

        return $workspaces;
    }

    /**
     * Create a new workspace in a container.
     *
     * @return array{workspaceId: string, name: string}|null
     */
    public function createWorkspace(string $accessToken, string $accountId, string $containerId, string $name): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/accounts/' . urlencode($accountId)
            . '/containers/' . urlencode($containerId) . '/workspaces';
        $data = $this->postJson($url, ['name' => $name], $accessToken);
        if ($data === null) {
            return null;
        }

        return [
            'workspaceId' => $data['workspaceId'] ?? '',
            'name' => $data['name'] ?? '',
        ];
    }

    // ─── Tag Manager write operations ────────────────────────

    /**
     * Build the workspace path used by all write operations.
     */
    public function workspacePath(string $accountId, string $containerId, string $workspaceId): string
    {
        return 'accounts/' . $accountId . '/containers/' . $containerId . '/workspaces/' . $workspaceId;
    }

    /**
     * Create a built-in variable (e.g., clickClasses, clickElement).
     *
     * @return array<string, mixed>|null
     */
    public function createBuiltInVariable(string $accessToken, string $wsPath, string $type): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/built_in_variables?type=' . urlencode($type);
        return $this->postJson($url, [], $accessToken);
    }

    /**
     * Create a user-defined variable.
     *
     * @param array<string, mixed> $variable GTM variable resource
     * @return array<string, mixed>|null Null on failure (including 409 conflict)
     */
    public function createVariable(string $accessToken, string $wsPath, array $variable): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/variables';
        return $this->postJson($url, $variable, $accessToken);
    }

    /**
     * Create a trigger.
     *
     * @param array<string, mixed> $trigger GTM trigger resource
     * @return array<string, mixed>|null
     */
    public function createTrigger(string $accessToken, string $wsPath, array $trigger): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/triggers';
        return $this->postJson($url, $trigger, $accessToken);
    }

    /**
     * List all triggers in a workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function listTriggers(string $accessToken, string $wsPath): array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/triggers';
        $data = $this->get($url, $accessToken);
        if ($data === null || !isset($data['trigger'])) {
            return [];
        }

        return $data['trigger'];
    }

    /**
     * Create a tag.
     *
     * @param array<string, mixed> $tag GTM tag resource
     * @return array<string, mixed>|null
     */
    public function createTag(string $accessToken, string $wsPath, array $tag): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/tags';
        return $this->postJson($url, $tag, $accessToken);
    }

    /**
     * Create a custom template.
     *
     * @param array<string, mixed> $template GTM custom template resource
     * @return array<string, mixed>|null
     */
    public function createTemplate(string $accessToken, string $wsPath, array $template): ?array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/templates';
        return $this->postJson($url, $template, $accessToken);
    }

    /**
     * List all custom templates in a workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function listTemplates(string $accessToken, string $wsPath): array
    {
        $url = self::TAG_MANAGER_BASE . '/' . $wsPath . '/templates';
        $data = $this->get($url, $accessToken);
        if ($data === null || !isset($data['template'])) {
            return [];
        }

        return $data['template'];
    }

    // ─── HTTP helpers ────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function get(string $url, string $accessToken): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
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
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * POST with form-encoded body (used for OAuth token exchange).
     *
     * @param array<string, string> $fields
     * @return array<string, mixed>|null
     */
    private function post(string $url, array $fields): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300 || !\is_string($response)) {
            return null;
        }

        $decoded = json_decode($response, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * POST with JSON body and Bearer auth (used for GTM API write operations).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function postJson(string $url, array $body, string $accessToken): ?array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, \JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
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
        return \is_array($decoded) ? $decoded : null;
    }
}
