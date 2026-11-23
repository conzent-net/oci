<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Admin\Repository\BackupRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps the newest N successful backups and removes the rest.
 *
 * Two rules earned the hard way elsewhere in this estate:
 *
 * 1. **Only successful backups count toward the quota.** A run of failures must
 *    never be able to age out the last good archive. The legacy host's script
 *    pruned unconditionally before shipping, so a bad night deleted a good
 *    remote copy while adding nothing — seven of those would have emptied the
 *    whole window with no alarm at any point.
 *
 * 2. **Failed rows are kept as history** even once their file is gone. The
 *    point of the page is that failures stay visible; pruning them would hide
 *    exactly what someone needs to see.
 */
final class BackupRetentionService
{
    public function __construct(
        private readonly BackupRepositoryInterface $backups,
        private readonly BackupDestinationService $destinations,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return array{files_removed: int, rows_removed: int, remote: array<string, int>} */
    public function prune(int $keep): array
    {
        $keep = max(1, $keep);

        $successful = $this->backups->successfulOldestFirst();
        $surplus = \count($successful) - $keep;

        $filesRemoved = 0;
        $rowsRemoved = 0;

        if ($surplus > 0) {
            foreach (\array_slice($successful, 0, $surplus) as $row) {
                $path = (string) ($row['path'] ?? '');

                if ($path !== '' && is_file($path) && @unlink($path)) {
                    ++$filesRemoved;
                }

                // The row goes with the file. A history entry pointing at an
                // archive that no longer exists is worse than no entry: it
                // reads as a backup you still have.
                $this->backups->delete((int) $row['id']);
                ++$rowsRemoved;
            }
        }

        $remote = $this->destinations->prune($keep);

        if ($filesRemoved > 0 || array_sum($remote) > 0) {
            $this->logger->info('Backup retention pruned', [
                'keep' => $keep,
                'files_removed' => $filesRemoved,
                'remote' => $remote,
            ]);
        }

        return ['files_removed' => $filesRemoved, 'rows_removed' => $rowsRemoved, 'remote' => $remote];
    }
}
