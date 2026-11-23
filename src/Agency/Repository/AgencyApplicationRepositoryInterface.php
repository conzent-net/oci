<?php

declare(strict_types=1);

namespace OCI\Agency\Repository;

/**
 * Partnership requests: the queue between "wants to be an agency" and "is one".
 *
 * Kept separate from `oci_agencies` because an application is a document with a
 * decision attached, and it survives the decision. An account can apply, be
 * rejected, and apply again a year later with a better case — three rows, one
 * agency, and a reviewer who can see the history.
 */
interface AgencyApplicationRepositoryInterface
{
    /**
     * The applicant's open request, if any.
     *
     * Used to enforce one open application per account. That rule is not a
     * unique key because the real constraint is "at most one row with
     * status = pending per user", which MariaDB cannot express as a partial
     * unique index.
     *
     * @return array<string, mixed>|null
     */
    public function findPendingForUser(int $userId): ?array;

    /**
     * The applicant's most recent request whatever its outcome, so a rejected
     * applicant can be shown why rather than an empty page.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestForUser(int $userId): ?array;

    /** @param array<string, mixed> $data */
    public function create(array $data): int;

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array;

    /**
     * The review queue, newest last so the oldest request is answered first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listByStatus(string $status, int $limit = 50, int $offset = 0): array;

    public function countByStatus(string $status): int;

    /**
     * Record a decision. Never deletes: the audit trail is the point.
     */
    public function markReviewed(int $id, string $status, int $reviewerId, ?string $note): void;

    /**
     * Applicant-initiated withdrawal. Scoped by user id so one account cannot
     * withdraw another's request.
     */
    public function withdraw(int $id, int $userId): bool;
}
