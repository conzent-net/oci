<?php

declare(strict_types=1);

namespace OCI\Identity\Service;

use Psr\Log\LoggerInterface;

/**
 * Validates the configured CMP ID against the IAB CMP registry.
 *
 * Results are cached in the PHP session so the external API is hit
 * at most once per login session.
 */
final class CmpValidationService
{
    private const REGISTRY_URL = 'https://cmp-list.consensu.org/v2/cmp-list.json';
    private const SESSION_KEY  = '_cmp_validation';
    private const HTTP_TIMEOUT = 5;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Get the CMP validation result from the session, or validate now.
     *
     * @return array{valid: bool, cmp_id: string, cmp_name: string}
     */
    public function getValidation(): array
    {
        $cmpId = trim($_ENV['CMP_ID'] ?? '');

        // No CMP ID configured — Community Edition
        if ($cmpId === '' || $cmpId === '0') {
            return ['valid' => false, 'cmp_id' => '', 'cmp_name' => ''];
        }

        // Check session cache (keyed by CMP ID so a config change triggers re-validation)
        $cached = $_SESSION[self::SESSION_KEY] ?? null;
        if ($cached !== null && ($cached['cmp_id'] ?? '') === $cmpId) {
            return $cached;
        }

        // Validate against IAB registry
        $result = $this->validateAgainstRegistry($cmpId);

        // Cache in session
        $_SESSION[self::SESSION_KEY] = $result;

        return $result;
    }

    /**
     * Clear the cached validation (e.g. to force re-check).
     */
    public function clearCache(): void
    {
        unset($_SESSION[self::SESSION_KEY]);
    }

    /**
     * @return array{valid: bool, cmp_id: string, cmp_name: string}
     */
    private function validateAgainstRegistry(string $cmpId): array
    {
        $invalid = ['valid' => false, 'cmp_id' => $cmpId, 'cmp_name' => ''];

        try {
            $context = stream_context_create([
                'http' => [
                    'timeout' => self::HTTP_TIMEOUT,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                ],
            ]);

            $json = @file_get_contents(self::REGISTRY_URL, false, $context);

            if ($json === false) {
                $this->logger->warning('CMP validation: failed to fetch IAB registry');
                // On network error, treat as invalid — don't silently enable TCF
                return $invalid;
            }

            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            if (isset($data['cmps'][$cmpId])) {
                $name = (string) ($data['cmps'][$cmpId]['name'] ?? '');
                $this->logger->info("CMP validation: ID {$cmpId} verified as \"{$name}\"");

                return ['valid' => true, 'cmp_id' => $cmpId, 'cmp_name' => $name];
            }

            $this->logger->warning("CMP validation: ID {$cmpId} not found in IAB registry");

            return $invalid;
        } catch (\Throwable $e) {
            $this->logger->error('CMP validation error: ' . $e->getMessage());

            return $invalid;
        }
    }
}
