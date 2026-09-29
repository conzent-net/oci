<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Filesystem;

/**
 * Files written by a root process, handed to whoever owns the folder they
 * live in.
 *
 * The web application runs as an unprivileged user; the workers, the
 * scheduler and any command run by hand may run as root. A folder created by
 * root is read-only to the web application, so the first time a worker wrote
 * a site's bundle, every later save from the dashboard failed with
 * "Permission denied". The rule here is simple: whatever owns the reference
 * directory owns everything written under it.
 *
 * Does nothing unless the process is root, because only root can give a file
 * away, and an unprivileged process already creates files it can rewrite.
 */
final class OwnedFile
{
    /**
     * Give $path, and every directory between it and $referenceDir, to the
     * owner of $referenceDir. With $recursive, everything below $path too.
     *
     * @return int How many files and directories changed owner
     */
    public static function adopt(string $path, string $referenceDir, bool $recursive = false): int
    {
        if (!self::canGiveAway()) {
            return 0;
        }

        $referenceDir = rtrim($referenceDir, '/\\');
        $owner = self::ownerOf($referenceDir);
        if ($owner === null || !self::isInside($path, $referenceDir)) {
            return 0;
        }

        return self::adoptAs($path, $referenceDir, $owner['uid'], $owner['gid'], $recursive);
    }

    /**
     * The same, with the owner given. Separate so the walk can be tested
     * without depending on who owns a temporary directory.
     *
     * @return int How many files and directories changed owner
     */
    public static function adoptAs(string $path, string $referenceDir, int $uid, int $gid, bool $recursive = false): int
    {
        if (!self::canGiveAway()) {
            return 0;
        }

        $referenceDir = rtrim($referenceDir, '/\\');
        if (!self::isInside($path, $referenceDir)) {
            return 0;
        }

        $changed = 0;
        foreach (self::chain($path, $referenceDir) as $step) {
            $changed += self::give($step, $uid, $gid);
        }

        if ($recursive && is_dir($path) && !is_link($path)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($items as $item) {
                $changed += self::give($item->getPathname(), $uid, $gid);
            }
        }

        return $changed;
    }

    /**
     * $path and each of its parent directories, stopping before
     * $referenceDir. Deepest last.
     *
     * @return list<string>
     */
    public static function chain(string $path, string $referenceDir): array
    {
        $referenceDir = rtrim(str_replace('\\', '/', $referenceDir), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (!self::isInside($path, $referenceDir)) {
            return [];
        }

        $chain = [];
        while ($path !== $referenceDir && $path !== '' && $path !== '.' && $path !== '/') {
            $chain[] = $path;
            $parent = \dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }

        return array_reverse($chain);
    }

    public static function isInside(string $path, string $referenceDir): bool
    {
        $referenceDir = rtrim(str_replace('\\', '/', $referenceDir), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return $referenceDir !== '' && $path !== $referenceDir && str_starts_with($path, $referenceDir . '/')
            && !str_contains(substr($path, \strlen($referenceDir)), '/../');
    }

    private static function canGiveAway(): bool
    {
        return \function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    /**
     * The owner of the first of these directories that exists and is not
     * root's. For a tree whose own top folder may be root's (a volume mount
     * point is), while a sibling the application writes to is not.
     *
     * @param list<string> $dirs
     *
     * @return array{uid: int, gid: int}|null
     */
    public static function firstOwner(array $dirs): ?array
    {
        foreach ($dirs as $dir) {
            $owner = self::ownerOf(rtrim($dir, '/\\'));
            if ($owner !== null) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * @return array{uid: int, gid: int}|null Null when the owner is root too, or unknown
     */
    private static function ownerOf(string $dir): ?array
    {
        $uid = @fileowner($dir);
        $gid = @filegroup($dir);
        if ($uid === false || $gid === false || $uid === 0) {
            return null;
        }

        return ['uid' => $uid, 'gid' => $gid];
    }

    private static function give(string $path, int $uid, int $gid): int
    {
        if (is_link($path) || !file_exists($path)) {
            return 0;
        }
        if (@fileowner($path) === $uid && @filegroup($path) === $gid) {
            return 0;
        }

        return @chown($path, $uid) && @chgrp($path, $gid) ? 1 : 0;
    }
}
