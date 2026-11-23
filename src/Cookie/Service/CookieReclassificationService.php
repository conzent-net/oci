<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use OCI\Admin\Service\AuditLogService;
use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Cookie\Repository\CookieReclassRequestRepositoryInterface;
use OCI\Cookie\Repository\CookieRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

/**
 * Handles user requests to move a Conzent-classified cookie to a different
 * category, and the staff review of those requests.
 *
 * A request never changes the requesting site's own classification — it is a
 * suggestion. Approving one updates the global cookie database for every site.
 */
final class CookieReclassificationService
{
    public function __construct(
        private readonly CookieReclassRequestRepositoryInterface $requestRepo,
        private readonly CookieRepositoryInterface $cookieRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly AuditLogService $auditLog,
    ) {}

    /**
     * Submit a reclassification request for a classified cookie.
     *
     * @return array{success: bool, error?: string}
     */
    public function submitRequest(
        int $userId,
        int $siteId,
        string $cookieName,
        ?string $cookieDomain,
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

        if (trim($cookieName) === '') {
            return ['success' => false, 'error' => 'Cookie name is required'];
        }

        $category = $this->categoryRepo->findCategory($requestedCategoryId);
        if ($category === null || ($category['slug'] ?? '') === 'unclassified') {
            return ['success' => false, 'error' => 'Please choose a valid target category'];
        }

        if ($this->requestRepo->existsPending($siteId, $cookieName)) {
            return ['success' => false, 'error' => 'A request for this cookie is already pending review'];
        }

        $requestId = $this->requestRepo->create([
            'site_id' => $siteId,
            'user_id' => $userId,
            'cookie_name' => $cookieName,
            'cookie_domain' => $cookieDomain,
            'current_category_slug' => $currentCategorySlug,
            'requested_category_id' => $requestedCategoryId,
            'reason' => $reason,
        ]);

        $this->auditLog->log(
            userId: $userId,
            action: 'submit',
            entityType: 'CookieReclassRequest',
            entityId: $requestId,
            newValues: [
                'cookie_name' => $cookieName,
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
     * Approve a request: apply the requested category to the global cookie DB.
     *
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

        $this->cookieRepo->applyGlobalClassification(
            (string) $req['cookie_name'],
            $req['cookie_domain'] ?? null,
            (int) $req['requested_category_id'],
        );

        $this->requestRepo->updateStatus($requestId, 'approved', $adminUserId);

        $this->auditLog->log(
            userId: $adminUserId,
            action: 'approve',
            entityType: 'CookieReclassRequest',
            entityId: $requestId,
            oldValues: ['current_category_slug' => $req['current_category_slug'] ?? null],
            newValues: [
                'cookie_name' => $req['cookie_name'],
                'requested_category_id' => (int) $req['requested_category_id'],
            ],
            ipAddress: $ip,
            userAgent: $ua,
        );

        return ['success' => true];
    }

    /**
     * Reject a request.
     *
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
            entityType: 'CookieReclassRequest',
            entityId: $requestId,
            newValues: ['cookie_name' => $req['cookie_name'], 'note' => $note],
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
    public function getPendingNamesForSite(int $siteId): array
    {
        return $this->requestRepo->getPendingNamesForSite($siteId);
    }
}
