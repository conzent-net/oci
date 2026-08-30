<?php

declare(strict_types=1);

namespace OCI\Site\Service;

/**
 * Lightweight HTTP client for the Matomo Reporting API.
 *
 * Used by the Matomo TM Wizard to list sites/containers and create
 * tags, triggers, and variables in a Matomo Tag Manager container.
 *
 * All calls use token_auth authentication (no OAuth).
 */
final class MatomoApiService
{
    /**
     * Validate Matomo credentials by attempting to list sites.
     */
    public function validateCredentials(string $matomoUrl, string $tokenAuth): bool
    {
        $result = $this->apiCall($matomoUrl, $tokenAuth, 'SitesManager.getAllSites');

        return $result !== null && \is_array($result) && !isset($result['result']);
    }

    /**
     * List all sites the token has access to.
     *
     * @return list<array{idsite: string, name: string, main_url: string}>
     */
    public function listSites(string $matomoUrl, string $tokenAuth): array
    {
        $data = $this->apiCall($matomoUrl, $tokenAuth, 'SitesManager.getAllSites');
        if ($data === null || !is_array($data)) {
            return [];
        }

        $sites = [];
        foreach ($data as $site) {
            if (!is_array($site)) {
                continue;
            }
            $sites[] = [
                'idsite' => (string) ($site['idsite'] ?? ''),
                'name' => (string) ($site['name'] ?? ''),
                'main_url' => (string) ($site['main_url'] ?? ''),
            ];
        }

        return $sites;
    }

    /**
     * List Tag Manager containers for a Matomo site.
     *
     * @return list<array{idcontainer: string, name: string, status: string}>
     */
    public function listContainers(string $matomoUrl, string $tokenAuth, int $idSite): array
    {
        $data = $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.getContainers', [
            'idSite' => $idSite,
        ]);
        if ($data === null || !is_array($data)) {
            return [];
        }

        $containers = [];
        foreach ($data as $container) {
            if (!is_array($container)) {
                continue;
            }
            $containers[] = [
                'idcontainer' => (string) ($container['idcontainer'] ?? ''),
                'name' => (string) ($container['name'] ?? ''),
                'status' => (string) ($container['status'] ?? ''),
            ];
        }

        return $containers;
    }

    /**
     * List tags in a container's draft version.
     *
     * @return list<array<string, mixed>>
     */
    public function listTags(string $matomoUrl, string $tokenAuth, int $idSite, string $idContainer, int $idContainerVersion = 0): array
    {
        $data = $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.getContainerTags', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => $idContainerVersion,
        ]);
        if ($data === null || !is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * List triggers in a container's draft version.
     *
     * @return list<array<string, mixed>>
     */
    public function listTriggers(string $matomoUrl, string $tokenAuth, int $idSite, string $idContainer, int $idContainerVersion = 0): array
    {
        $data = $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.getContainerTriggers', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => $idContainerVersion,
        ]);
        if ($data === null || !is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * List variables in a container's draft version.
     *
     * @return list<array<string, mixed>>
     */
    public function listVariables(string $matomoUrl, string $tokenAuth, int $idSite, string $idContainer, int $idContainerVersion = 0): array
    {
        $data = $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.getContainerVariables', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => $idContainerVersion,
        ]);
        if ($data === null || !is_array($data)) {
            return [];
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Create a tag in a container's draft version.
     *
     * @param array<string, mixed> $params Tag parameters
     * @return array<string, mixed>|null
     */
    public function createTag(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $type,
        string $name,
        array $params,
        array $fireTriggerIds = [],
        array $blockTriggerIds = [],
        int $priority = 999,
    ): ?array {
        return $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.addContainerTag', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => 0,
            'type' => $type,
            'name' => $name,
            'parameters' => json_encode($params, \JSON_THROW_ON_ERROR),
            'fireTriggerIds' => json_encode($fireTriggerIds, \JSON_THROW_ON_ERROR),
            'blockTriggerIds' => json_encode($blockTriggerIds, \JSON_THROW_ON_ERROR),
            'priority' => $priority,
        ], 'POST');
    }

    /**
     * Create a trigger in a container's draft version.
     *
     * @param array<string, mixed> $params Trigger parameters
     * @param array<string, mixed> $conditions Trigger conditions
     * @return array<string, mixed>|null
     */
    public function createTrigger(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $type,
        string $name,
        array $params = [],
        array $conditions = [],
    ): ?array {
        return $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.addContainerTrigger', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => 0,
            'type' => $type,
            'name' => $name,
            'parameters' => json_encode($params, \JSON_THROW_ON_ERROR),
            'conditions' => json_encode($conditions, \JSON_THROW_ON_ERROR),
        ], 'POST');
    }

    /**
     * Create a variable in a container's draft version.
     *
     * @param array<string, mixed> $params Variable parameters
     * @return array<string, mixed>|null
     */
    public function createVariable(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $type,
        string $name,
        array $params = [],
    ): ?array {
        return $this->apiCall($matomoUrl, $tokenAuth, 'TagManager.addContainerVariable', [
            'idSite' => $idSite,
            'idContainer' => $idContainer,
            'idContainerVersion' => 0,
            'type' => $type,
            'name' => $name,
            'parameters' => json_encode($params, \JSON_THROW_ON_ERROR),
        ], 'POST');
    }

    // ─── HTTP helper ─────────────────────────────────────────

    /**
     * Make an API call to a Matomo instance.
     *
     * @param array<string, mixed> $extraParams Additional query/POST parameters
     * @return array<string, mixed>|null
     */
    private function apiCall(
        string $matomoUrl,
        string $tokenAuth,
        string $method,
        array $extraParams = [],
        string $httpMethod = 'POST',
    ): ?array {
        $matomoUrl = rtrim($matomoUrl, '/');

        // SSRF protection: block private IPs unless explicitly allowed
        if (!$this->isUrlAllowed($matomoUrl)) {
            return null;
        }

        $baseParams = [
            'module' => 'API',
            'method' => $method,
            'format' => 'json',
            'token_auth' => $tokenAuth,
        ];

        $allParams = array_merge($baseParams, $extraParams);

        // Always use POST to keep token_auth in the request body.
        // Matomo's "force secure requests" rejects tokens in GET query strings.
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $matomoUrl . '/index.php',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($allParams),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
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
     * Check if a Matomo URL is allowed (SSRF protection).
     *
     * Blocks private/internal IPs unless MATOMO_ALLOW_LOCAL=1 is set.
     */
    private function isUrlAllowed(string $url): bool
    {
        if (($_ENV['MATOMO_ALLOW_LOCAL'] ?? '0') === '1') {
            return true;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return false;
        }

        $host = $parsed['host'];
        $ip = gethostbyname($host);

        // If DNS resolution failed, gethostbyname returns the hostname
        if ($ip === $host && !filter_var($host, \FILTER_VALIDATE_IP)) {
            // Could not resolve — allow (may be internal DNS)
            return true;
        }

        // Block private/reserved ranges
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }
}
