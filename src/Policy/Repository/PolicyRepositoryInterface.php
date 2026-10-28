<?php

declare(strict_types=1);

namespace OCI\Policy\Repository;

interface PolicyRepositoryInterface
{
    // ── Cookie policies (per-site) ──────────────────────────
    public function getCookiePolicy(int $siteId, int $languageId): ?array;
    public function saveCookiePolicy(int $siteId, int $languageId, array $data): int;

    // ── Privacy policies (per-site) ─────────────────────────
    public function getPrivacyPolicy(int $siteId, int $languageId): ?array;
    public function savePrivacyPolicy(int $siteId, int $languageId, array $data): int;

    // ── Cookie policy templates ─────────────────────────────
    public function getCookiePolicyTemplates(int $userId): array;
    public function getCookiePolicyTemplate(int $id): ?array;
    public function saveCookiePolicyTemplate(int $userId, array $data): int;
    public function updateCookiePolicyTemplate(int $id, array $data): void;
    public function deleteCookiePolicyTemplate(int $id): void;

    // ── Privacy policy templates ────────────────────────────
    public function getPrivacyPolicyTemplates(int $userId): array;
    public function getPrivacyPolicyTemplate(int $id): ?array;
    public function savePrivacyPolicyTemplate(int $userId, array $data): int;
    public function updatePrivacyPolicyTemplate(int $id, array $data): void;
    public function deletePrivacyPolicyTemplate(int $id): void;

    // ── System defaults ─────────────────────────────────────
    public function getDefaultCookiePolicyTemplate(int $languageId): ?array;
    public function getDefaultPrivacyPolicyTemplate(int $languageId): ?array;
}
