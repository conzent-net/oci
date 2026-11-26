<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Admin\Service\AuditLogService;
use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Scanning\Repository\BeaconClassificationRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * Lets a site owner classify an unclassified third-party beacon/script into a
 * category (per-site, immediate). Beacons already classified in Conzent's global
 * database are locked and must go through the reclassification-request flow.
 */
final class BeaconClassificationService
{
    public function __construct(
        private readonly BeaconClassificationRepositoryInterface $beaconRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @return array{success: bool, error?: string}
     */
    public function classifyBeacon(int $userId, int $beaconId, int $categoryId, ?string $ip = null, ?string $ua = null): array
    {
        $beacon = $this->beaconRepo->findBeacon($beaconId);
        if ($beacon === null) {
            return ['success' => false, 'error' => 'Beacon not found'];
        }

        $site = $this->siteRepo->findById((int) $beacon['site_id']);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Beacon not found'];
        }

        // Guard: a beacon classified by Conzent's global DB must go through a request.
        $global = $this->beaconRepo->matchGlobal((string) $beacon['beacon_url']);
        if ($global !== null && !empty($global['category_id'])) {
            return [
                'success' => false,
                'error' => 'This beacon is classified by Conzent. Submit a reclassification request instead.',
            ];
        }

        $category = $this->categoryRepo->findCategory($categoryId);
        if ($category === null || ($category['slug'] ?? '') === 'unclassified') {
            return ['success' => false, 'error' => 'Please choose a valid category'];
        }

        $this->beaconRepo->classifyBeacon($beaconId, (string) $category['slug']);

        $this->auditLog->log(
            userId: $userId,
            action: 'classify',
            entityType: 'Beacon',
            entityId: $beaconId,
            newValues: [
                'beacon_url' => $beacon['beacon_url'],
                'category_id' => $categoryId,
                'category_slug' => $category['slug'] ?? null,
            ],
            ipAddress: $ip,
            userAgent: $ua,
        );

        return ['success' => true];
    }
}
