<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use Psr\Log\LoggerInterface;

/**
 * The short-lived cache in front of the compliance overview, in one place.
 *
 * Pulled out of {@see ComplianceOverviewService} so the things that CHANGE an
 * agency's book can clear it without depending on the whole overview. Every
 * link, unlink, approval, suspension, template apply and revert changes what
 * the overview would show, and each of those used to leave the cached copy
 * standing for up to five minutes: an agency linked a client and the dashboard
 * said they had no clients. Acknowledging an issue was the only path that
 * cleared it.
 *
 * Absence of Redis is a deliberate state, not an error: without it every read
 * is live, which is slower and never wrong.
 */
final class AgencyHealthCache
{
    private const KEY_PREFIX = 'oci:agency:health:';
    private const TTL = 300;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?\Predis\Client $redis = null,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function read(int $agencyId): ?array
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $raw = $this->redis->get(self::KEY_PREFIX . $agencyId);

            if (!\is_string($raw) || $raw === '') {
                return null;
            }

            $decoded = json_decode($raw, true);

            return \is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            // A cache that cannot be read is not worth failing a page over —
            // it just means doing the work again.
            $this->logger->warning('Agency health cache read failed: ' . $e->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $data */
    public function write(int $agencyId, array $data): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->setex(self::KEY_PREFIX . $agencyId, self::TTL, (string) json_encode($data, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            $this->logger->warning('Agency health cache write failed: ' . $e->getMessage());
        }
    }

    /**
     * Drop the cached overview for one agency, by its ROW id.
     *
     * Takes the row id rather than an {@see AgencyScope} because the callers
     * that need this most — a suspension, a client revoking from their own
     * account — are precisely the ones acting on an agency that has no valid
     * scope at that moment.
     */
    public function forget(int $agencyId): void
    {
        if ($this->redis === null || $agencyId <= 0) {
            return;
        }

        try {
            $this->redis->del(self::KEY_PREFIX . $agencyId);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not clear the agency health cache: ' . $e->getMessage());
        }
    }
}
