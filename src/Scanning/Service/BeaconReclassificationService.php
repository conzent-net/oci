<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Admin\Service\AuditLogService;
use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Scanning\Repository\BeaconClassificationRepositoryInterface;
use OCI\Scanning\Repository\BeaconReclassRequestRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * User requests to move a Conzent-classified beacon to a different category, and
 * the staff review of those requests. A request never changes the requesting
 * site — approving one updates the global beacon database for every site.
 */
final class BeaconReclassificationService
{
    public function __construct(
        private readonly BeaconReclassRequestRepositoryInterface $requestRepo,
        private readonly BeaconClassificationRepositoryInterface $beaconRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * @return array{success: bool, error?: string}
     */
    public function submitRequest(
        int $userId,
        int $siteId,
        string $beaconDomain,
        ?string $currentCategorySlug,
        int $requestedCategoryId,
        ?string $reason,
        ?string $ip = null,
        ?string $ua = null,
    ): array {
        $site = $this->siteRepo->findById($siteId);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Site not found'];
        }

        $beaconDomain = $this->beaconRepo->normalizeDomain($beaconDomain);
        if ($beaconDomain === '') {
            return ['success' => false, 'error' => 'Beacon domain is required'];
        }

        $category = $this->categoryRepo->findCategory($requestedCategoryId);
        if ($category === null || ($category['slug'] ?? '') === 'unclassified') {
            return ['success' => false, 'error' => 'Please choose a valid target category'];
        }

        if ($this->requestRepo->existsPending($siteId, $beaconDomain)) {
            return ['success' => false, 'error' => 'A request for this beacon is already pending review'];
        }

        $requestId = $this->requestRepo->create([
            'site_id' => $siteId,
            'user_id' => $userId,
            'beacon_domain' => $beaconDomain,
            'current_category_slug' => $currentCategorySlug,
            'requested_category_id' => $requestedCategoryId,
            'reason' => $reason,
        ]);

        $this->auditLog->log(
            userId: $userId,
            action: 'submit',
            entityType: 'BeaconReclassRequest',
            entityId: $requestId,
            newValues: [
                'beacon_domain' => $beaconDomain,
                'current_category_slug' => $currentCategorySlug,
                'requested_category_id' => $requestedCategoryId,
            ],
            ipAddress: $ip,
            userAgent: $ua,
        );

        return ['success' => true];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function listRequests(?string $status, int $page = 1, int $perPage = 50): array
    {
        return $this->requestRepo->findByStatus($status, $page, $perPage);
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function approve(int $adminUserId, int $requestId, ?string $ip = null, ?string $ua = null): array
    {
        $req = $this->requestRepo->findById($requestId);
        if ($req === null) {
            return ['success' => false, 'error' => 'Request not found'];
        }
        if (($req['status'] ?? '') !== 'pending') {
            return ['success' => false, 'error' => 'Request has already been reviewed'];
        }

        $this->beaconRepo->applyGlobalClassification(
            (string) $req['beacon_domain'],
            (int) $req['requested_category_id'],
        );

        $this->requestRepo->updateStatus($requestId, 'approved', $adminUserId);

        $this->auditLog->log(
            userId: $adminUserId,
            action: 'approve',
            entityType: 'BeaconReclassRequest',
            entityId: $requestId,
            oldValues: ['current_category_slug' => $req['current_category_slug'] ?? null],
            newValues: [
                'beacon_domain' => $req['beacon_domain'],
                'requested_category_id' => (int) $req['requested_category_id'],
            ],
            ipAddress: $ip,
            userAgent: $ua,
        );

        return ['success' => true];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function reject(int $adminUserId, int $requestId, ?string $note = null, ?string $ip = null, ?string $ua = null): array
    {
        $req = $this->requestRepo->findById($requestId);
        if ($req === null) {
            return ['success' => false, 'error' => 'Request not found'];
        }
        if (($req['status'] ?? '') !== 'pending') {
            return ['success' => false, 'error' => 'Request has already been reviewed'];
        }

        $this->requestRepo->updateStatus($requestId, 'rejected', $adminUserId, $note);

        $this->auditLog->log(
            userId: $adminUserId,
            action: 'reject',
            entityType: 'BeaconReclassRequest',
            entityId: $requestId,
            newValues: ['beacon_domain' => $req['beacon_domain'], 'note' => $note],
            ipAddress: $ip,
            userAgent: $ua,
        );

        return ['success' => true];
    }

    public function countPending(): int
    {
        return $this->requestRepo->countPending();
    }

    /**
     * @return list<string>
     */
    public function getPendingDomainsForSite(int $siteId): array
    {
        return $this->requestRepo->getPendingDomainsForSite($siteId);
    }
}
