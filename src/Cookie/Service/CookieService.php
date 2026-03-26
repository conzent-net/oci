<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Cookie\Repository\CookieRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;

final class CookieService
{
    public function __construct(
        private readonly CookieRepositoryInterface $cookieRepo,
        private readonly CookieCategoryRepositoryInterface $categoryRepo,
        private readonly SiteRepositoryInterface $siteRepo,
    ) {}

    /**
     * Get paginated cookies for a site with category breakdown.
     *
     * @return array<string, mixed>
     */
    public function getCookieList(int $siteId, int $page = 1, ?string $category = null, ?string $search = null): array
    {
        $result = $this->cookieRepo->findBySite($siteId, $page, 50, $category, $search);
        $categoryCounts = $this->cookieRepo->countByCategory($siteId);

        $totalCookies = 0;
        foreach ($categoryCounts as $cnt) {
            $totalCookies += $cnt;
        }

        return [
            'cookies' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => 50,
            'totalPages' => $result['total'] > 0 ? (int) ceil($result['total'] / 50) : 0,
            'categoryCounts' => $categoryCounts,
            'totalCookies' => $totalCookies,
        ];
    }

    /**
     * Get all cookies for a site grouped by category (for tabbed view).
     *
     * @return array{categories: list<array>, cookiesByCategory: array<string, list<array>>, totalCookies: int}
     */
    public function getCookiesGroupedByCategory(int $siteId, ?string $search = null): array
    {
        $scanResult = $this->cookieRepo->getLatestScanCookies($siteId);
        $allCookies = $scanResult['cookies'];
        $scanId = $scanResult['scan_id'];

        // Tag scan cookies with source
        foreach ($allCookies as &$c) {
            $c['source'] = $c['source'] ?? 'server';
        }
        unset($c);

        // Merge client-side observed cookies from beacon ingestion
        $observed = $this->cookieRepo->getObservedCookies($siteId);
        $existingNames = [];
        foreach ($allCookies as $c) {
            $existingNames[mb_strtolower($c['cookie_name'])] = true;
        }
        foreach ($observed as $obs) {
            $key = mb_strtolower($obs['cookie_name']);
            if (isset($existingNames[$key])) {
                // Enrich existing scan cookie with observation counts
                foreach ($allCookies as &$c) {
                    if (mb_strtolower($c['cookie_name']) === $key) {
                        $c['pre_consent_count'] = (int) $obs['pre_consent_count'];
                        $c['post_consent_count'] = (int) $obs['post_consent_count'];
                        $c['observation_count'] = (int) $obs['total_count'];
                        $c['last_seen_at'] = $obs['last_seen_at'];
                        break;
                    }
                }
                unset($c);
            } else {
                // New cookie only seen via client-side beacons
                $allCookies[] = [
                    'cookie_name' => $obs['cookie_name'],
                    'cookie_domain' => $obs['cookie_domain'],
                    'category_slug' => $obs['category_slug'] ?? 'unclassified',
                    'category_name' => $obs['category_name'],
                    'default_duration' => null,
                    'http_only' => null,
                    'secure' => null,
                    'same_site' => null,
                    'found_on_url' => null,
                    'source' => 'client',
                    'pre_consent_count' => (int) $obs['pre_consent_count'],
                    'post_consent_count' => (int) $obs['post_consent_count'],
                    'observation_count' => (int) $obs['total_count'],
                    'first_seen_at' => $obs['first_seen_at'],
                    'last_seen_at' => $obs['last_seen_at'],
                    'global_platform' => $obs['global_platform'] ?? null,
                    'global_description' => $obs['global_description'] ?? null,
                ];
                $existingNames[$key] = true;
            }
        }

        // Filter by search if provided
        if ($search !== null && $search !== '') {
            $term = mb_strtolower($search);
            $allCookies = array_values(array_filter($allCookies, static fn(array $c): bool =>
                str_contains(mb_strtolower($c['cookie_name'] ?? ''), $term)
                || str_contains(mb_strtolower($c['cookie_domain'] ?? ''), $term)
            ));
        }

        // Group cookies by category slug
        $grouped = [];
        foreach ($allCookies as $cookie) {
            $slug = $cookie['category_slug'] ?? 'unclassified';
            $grouped[$slug][] = $cookie;
        }

        // Always show all standard categories as tabs
        $standardCategories = [
            ['slug' => 'necessary', 'name' => 'Necessary'],
            ['slug' => 'functional', 'name' => 'Functional'],
            ['slug' => 'analytics', 'name' => 'Analytics'],
            ['slug' => 'marketing', 'name' => 'Marketing'],
        ];

        // Resolve display names from DB translations where available
        $orderedCategories = [];
        $seenSlugs = [];
        foreach ($standardCategories as $std) {
            $slug = $std['slug'];
            $seenSlugs[$slug] = true;
            $name = $std['name'];
            if (isset($grouped[$slug]) && !empty($grouped[$slug][0]['category_name'])) {
                $name = $grouped[$slug][0]['category_name'];
            }
            $orderedCategories[] = [
                'slug' => $slug,
                'name' => $name,
                'cookie_count' => count($grouped[$slug] ?? []),
            ];
        }

        // Add any extra categories found in scan that aren't standard
        foreach ($grouped as $slug => $cookies) {
            if ($slug === 'unclassified' || isset($seenSlugs[$slug])) {
                continue;
            }
            $orderedCategories[] = [
                'slug' => $slug,
                'name' => $cookies[0]['category_name'] ?? ucfirst($slug),
                'cookie_count' => count($cookies),
            ];
        }

        // Always show unclassified tab last
        $orderedCategories[] = [
            'slug' => 'unclassified',
            'name' => 'Unclassified',
            'cookie_count' => count($grouped['unclassified'] ?? []),
        ];

        return [
            'categories' => $orderedCategories,
            'cookiesByCategory' => $grouped,
            'totalCookies' => count($allCookies),
            'scanId' => $scanId,
        ];
    }

    /**
     * Create a cookie for a site.
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    public function createCookie(int $userId, int $siteId, array $data): array
    {
        // Verify site ownership
        $site = $this->siteRepo->findById($siteId);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Site not found'];
        }

        if (empty($data['cookie_name'])) {
            return ['success' => false, 'error' => 'Cookie name is required'];
        }

        $id = $this->cookieRepo->create($siteId, $data);

        return ['success' => true, 'id' => $id];
    }

    /**
     * Update a cookie.
     *
     * @return array{success: bool, error?: string}
     */
    public function updateCookie(int $userId, int $cookieId, array $data): array
    {
        $cookie = $this->cookieRepo->findById($cookieId);
        if ($cookie === null) {
            return ['success' => false, 'error' => 'Cookie not found'];
        }

        // Verify ownership
        $site = $this->siteRepo->findById((int) $cookie['site_id']);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Cookie not found'];
        }

        $this->cookieRepo->update($cookieId, $data);

        return ['success' => true];
    }

    /**
     * Delete a cookie.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteCookie(int $userId, int $cookieId): array
    {
        $cookie = $this->cookieRepo->findById($cookieId);
        if ($cookie === null) {
            return ['success' => false, 'error' => 'Cookie not found'];
        }

        $site = $this->siteRepo->findById((int) $cookie['site_id']);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Cookie not found'];
        }

        $this->cookieRepo->delete($cookieId);

        return ['success' => true];
    }

    /**
     * Import cookies from a completed scan.
     *
     * @return array{success: bool, imported?: int, error?: string}
     */
    public function importFromScan(int $userId, int $siteId, int $scanId): array
    {
        $site = $this->siteRepo->findById($siteId);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Site not found'];
        }

        $imported = $this->cookieRepo->importFromScan($siteId, $scanId);

        return ['success' => true, 'imported' => $imported];
    }

    /**
     * Get category list for a site, including cookie counts.
     *
     * @return array<string, mixed>
     */
    public function getCategoryList(int $siteId): array
    {
        $categories = $this->categoryRepo->getSiteCategories($siteId);
        $cookieCounts = $this->categoryRepo->countCookiesPerCategory($siteId);

        foreach ($categories as &$cat) {
            $catId = (int) $cat['category_id'];
            $cat['cookie_count'] = $cookieCounts[$catId] ?? 0;
        }
        unset($cat);

        return [
            'categories' => $categories,
            'globalCategories' => $this->categoryRepo->getAllGlobalCategories(),
        ];
    }

    /**
     * Add a global category to a site.
     *
     * @return array{success: bool, error?: string}
     */
    public function addCategoryToSite(int $userId, int $siteId, int $categoryId, string $name, string $description): array
    {
        $site = $this->siteRepo->findById($siteId);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Site not found'];
        }

        if (empty($name)) {
            return ['success' => false, 'error' => 'Category name is required'];
        }

        $this->categoryRepo->copyCategoryToSite($siteId, $categoryId, 1, $name, $description);

        return ['success' => true];
    }

    /**
     * Update a site category's translation and settings.
     *
     * @return array{success: bool, error?: string}
     */
    public function updateSiteCategory(int $userId, int $siteCategoryId, array $data): array
    {
        $cat = $this->categoryRepo->findSiteCategory($siteCategoryId);
        if ($cat === null) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        $site = $this->siteRepo->findById((int) $cat['site_id']);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        // Update settings
        $settings = [];
        if (array_key_exists('sort_order', $data)) {
            $settings['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('default_consent', $data)) {
            $settings['default_consent'] = $data['default_consent'] ?: null;
        }
        if ($settings !== []) {
            $this->categoryRepo->updateSiteCategory($siteCategoryId, $settings);
        }

        // Update translation
        if (isset($data['name'])) {
            $this->categoryRepo->upsertSiteCategoryTranslation(
                $siteCategoryId,
                1,
                $data['name'],
                $data['description'] ?? '',
            );
        }

        return ['success' => true];
    }

    /**
     * Remove a category from a site.
     *
     * @return array{success: bool, error?: string}
     */
    public function deleteSiteCategory(int $userId, int $siteCategoryId): array
    {
        $cat = $this->categoryRepo->findSiteCategory($siteCategoryId);
        if ($cat === null) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        // Don't allow deleting "necessary" category
        if (($cat['slug'] ?? '') === 'necessary') {
            return ['success' => false, 'error' => 'The "Necessary" category cannot be removed'];
        }

        $site = $this->siteRepo->findById((int) $cat['site_id']);
        if ($site === null || (int) $site['user_id'] !== $userId) {
            return ['success' => false, 'error' => 'Category not found'];
        }

        $this->categoryRepo->deleteSiteCategory($siteCategoryId);

        return ['success' => true];
    }
}
