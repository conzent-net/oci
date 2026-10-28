<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

/**
 * Storage for agency-owned settings templates and where they were applied.
 *
 * Every read that can return an agency's template takes an agency id and
 * filters on it. There is deliberately no `findById(int $id)` that ignores
 * ownership: a template is an instrument for writing to other people's sites,
 * so "fetch template 42" without saying whose it is has no safe meaning.
 */
interface SettingTemplateRepositoryInterface
{
    /**
     * An agency's own templates, plus the built-in system ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listForAgency(int $agencyId): array;

    /**
     * One template, only if this agency owns it or it is a system template.
     *
     * @return array<string, mixed>|null
     */
    public function findForAgency(int $templateId, int $agencyId): ?array;

    /** @param array<string, mixed> $payload */
    public function create(int $agencyId, string $name, ?string $description, array $payload, int $createdBy): int;

    /** @param array<string, mixed> $payload */
    public function update(int $templateId, int $agencyId, string $name, ?string $description, array $payload): bool;

    /** Only an agency's own template can be deleted; system templates never. */
    public function delete(int $templateId, int $agencyId): bool;

    /**
     * Record that a template was applied to a site.
     *
     * @param array<string, mixed> $previousSettings snapshot for revert
     */
    public function recordApplication(
        int $siteId,
        int $templateId,
        string $fingerprint,
        array $previousSettings,
        int $appliedBy,
    ): void;

    /** @return array<string, mixed>|null */
    public function findLink(int $siteId): ?array;

    /**
     * Links for a set of sites, keyed by site id.
     *
     * @param array<int, int> $siteIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function findLinks(array $siteIds): array;

    /**
     * Forget that a site is on a template. Used after a revert: the site is
     * back to its own settings and no longer represents any template.
     */
    public function deleteLink(int $siteId): void;

    /**
     * How many sites currently sit on each of an agency's templates.
     *
     * @return array<int, int> template id => site count
     */
    public function siteCountsForAgency(int $agencyId): array;
}
