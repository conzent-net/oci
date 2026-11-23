<?php

declare(strict_types=1);

namespace OCI\Admin\Service\BackupDestination;

/**
 * One place a finished archive can be shipped to.
 *
 * Implementations must never throw out of put() or test() for an expected
 * remote failure — a quota, a refused login, a missing bucket. Those are
 * results to record and show, not exceptions. A destination that fails must
 * not be able to turn a good local backup into a failed one.
 */
interface DestinationInterface
{
    /** Matches the oci_backup_destinations enum: local, sftp, s3. */
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Ship the archive.
     *
     * @return array{ok: bool, remote_path: ?string, bytes: ?int, error: ?string}
     */
    public function put(string $localPath, string $remoteName): array;

    /**
     * Cheap round-trip proving credentials work AND that we can write —
     * reachability alone is not the question anyone is actually asking.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(): array;

    /**
     * Prune remote copies beyond the retention count, newest kept.
     *
     * @return int number removed
     */
    public function prune(int $keep): int;

    /**
     * Archives this destination currently holds, newest first.
     *
     * Deliberately independent of oci_backups. After a total loss there are no
     * rows to select from — the whole point of an offsite copy is that it
     * outlives the database that recorded it, so recovery has to start from
     * what the storage actually contains.
     *
     * Implementations MUST throw when the listing cannot be read, and MUST NOT
     * report an unreachable destination as an empty one. An empty array is a
     * positive claim — "this storage holds no archives" — and the UI acts on it
     * by telling somebody their offsite copy is gone. That answer has to be
     * earned by an actual successful listing.
     *
     * @return list<array{name: string, bytes: ?int, modified: ?string}>
     */
    public function list(): array;

    /**
     * Retrieve one archive to a local path.
     *
     * @return array{ok: bool, bytes: ?int, error: ?string}
     */
    public function fetch(string $remoteName, string $localPath): array;
}
