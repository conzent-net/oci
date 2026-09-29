<?php

declare(strict_types=1);

namespace OCI\Admin\Service\BackupDestination;

/**
 * The archive's own directory.
 *
 * Always configured, because the archive is written here before anything else
 * sees it. It still gets a row in oci_backup_destinations so the UI reads the
 * same way for every target, and so "local" can be shown honestly as what it
 * is: a copy on the same disk as the thing it protects. That is a rollback
 * point, not disaster recovery, and the UI says so.
 */
final class LocalDestination implements DestinationInterface
{
    public function __construct(private readonly string $dir)
    {
    }

    public function name(): string
    {
        return 'local';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function put(string $localPath, string $remoteName): array
    {
        if (!is_file($localPath)) {
            return ['ok' => false, 'remote_path' => null, 'bytes' => null, 'error' => 'Archive missing at ' . $localPath];
        }

        return [
            'ok' => true,
            'remote_path' => $localPath,
            'bytes' => (int) filesize($localPath),
            'error' => null,
        ];
    }

    public function test(): array
    {
        if (!is_dir($this->dir)) {
            return ['ok' => false, 'message' => 'Backup directory does not exist: ' . $this->dir];
        }

        $probe = $this->dir . '/.write-probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return ['ok' => false, 'message' => 'Backup directory is not writable: ' . $this->dir];
        }
        @unlink($probe);

        $free = @disk_free_space($this->dir);

        return [
            'ok' => true,
            'message' => $free === false
                ? 'Writable.'
                : sprintf('Writable. %.1f GB free.', $free / 1073741824),
        ];
    }

    public function list(): array
    {
        $out = [];

        foreach (glob($this->dir . '/conzent-*.tar.gz') ?: [] as $path) {
            $out[] = [
                'name' => basename($path),
                'bytes' => (int) filesize($path),
                'modified' => gmdate('c', (int) filemtime($path)),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp((string) $b['name'], (string) $a['name']));

        return $out;
    }

    public function fetch(string $remoteName, string $localPath): array
    {
        $source = $this->dir . '/' . basename($remoteName);

        if (!is_file($source)) {
            return ['ok' => false, 'bytes' => null, 'error' => 'Not present locally: ' . basename($remoteName)];
        }

        // Already on this disk; a copy would only waste the space.
        if (realpath($source) === realpath($localPath)) {
            return ['ok' => true, 'bytes' => (int) filesize($source), 'error' => null];
        }

        if (!@copy($source, $localPath)) {
            // A copy that runs out of disk leaves a partial file behind.
            @unlink($localPath);

            return ['ok' => false, 'bytes' => null, 'error' => 'Could not copy the archive.'];
        }

        return ['ok' => true, 'bytes' => (int) filesize($localPath), 'error' => null];
    }

    public function prune(int $keep): int
    {
        $files = glob($this->dir . '/conzent-*.tar.gz') ?: [];
        if (\count($files) <= $keep) {
            return 0;
        }

        // Newest first, then drop the tail.
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $removed = 0;
        foreach (\array_slice($files, $keep) as $old) {
            if (@unlink($old)) {
                ++$removed;
            }
        }

        return $removed;
    }
}
