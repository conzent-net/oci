<?php

declare(strict_types=1);

namespace OCI\Notification\Service;

use OCI\Notification\Repository\NotificationReadRepositoryInterface;

final class NotificationService
{
    /** @var array<string, array>|null In-memory cache for parsed files */
    private ?array $cache = null;

    public function __construct(
        private readonly NotificationReadRepositoryInterface $readRepo,
        private readonly string $notificationsPath,
    ) {}

    /**
     * Get all notifications with read state for a user.
     *
     * @return list<array{slug: string, title: string, date: string, is_read: bool, excerpt: string}>
     */
    public function getAll(int $userId): array
    {
        $all = $this->loadAll();
        $readSlugs = array_flip($this->readRepo->getReadSlugs($userId));

        $notifications = [];
        foreach ($all as $slug => $data) {
            $notifications[] = [
                'slug' => $slug,
                'title' => $data['title'],
                'date' => $data['date'],
                'is_read' => isset($readSlugs[$slug]),
                'excerpt' => $data['excerpt'],
            ];
        }

        // Sort by date descending, then slug descending for same-date ties
        usort($notifications, static function (array $a, array $b): int {
            return $b['date'] <=> $a['date'] ?: $b['slug'] <=> $a['slug'];
        });

        return $notifications;
    }

    /**
     * Get a single notification by slug with full markdown body.
     *
     * @return array{slug: string, title: string, date: string, is_read: bool, body: string}|null
     */
    public function getOne(int $userId, string $slug): ?array
    {
        $all = $this->loadAll();

        if (!isset($all[$slug])) {
            return null;
        }

        $data = $all[$slug];
        $readSlugs = $this->readRepo->getReadSlugs($userId);

        return [
            'slug' => $slug,
            'title' => $data['title'],
            'date' => $data['date'],
            'is_read' => in_array($slug, $readSlugs, true),
            'body' => $data['body'],
        ];
    }

    /**
     * Count unread notifications for a user.
     */
    public function getUnreadCount(int $userId): int
    {
        $all = $this->loadAll();
        $readSlugs = $this->readRepo->getReadSlugs($userId);

        return count($all) - count(array_intersect(array_keys($all), $readSlugs));
    }

    /**
     * Mark a single notification as read.
     */
    public function markRead(int $userId, string $slug): void
    {
        $this->readRepo->markRead($userId, $slug);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(int $userId): void
    {
        $slugs = array_keys($this->loadAll());
        $this->readRepo->markAllRead($userId, $slugs);
    }

    /**
     * Scan the notifications directory and parse all .md files.
     *
     * @return array<string, array{title: string, date: string, body: string, excerpt: string}>
     */
    private function loadAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = [];

        if (!is_dir($this->notificationsPath)) {
            return $this->cache;
        }

        $files = glob($this->notificationsPath . '/*.md');
        if ($files === false) {
            return $this->cache;
        }

        foreach ($files as $file) {
            $slug = basename($file, '.md');
            $parsed = $this->parseFile($file);
            if ($parsed !== null) {
                $this->cache[$slug] = $parsed;
            }
        }

        return $this->cache;
    }

    /**
     * Parse a .md file: split frontmatter from body.
     *
     * @return array{title: string, date: string, body: string, excerpt: string}|null
     */
    private function parseFile(string $filePath): ?array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $content = ltrim($content);

        // Must start with ---
        if (!str_starts_with($content, '---')) {
            return null;
        }

        // Find closing ---
        $endPos = strpos($content, '---', 3);
        if ($endPos === false) {
            return null;
        }

        $frontmatterRaw = substr($content, 3, $endPos - 3);
        $body = trim(substr($content, $endPos + 3));

        // Parse simple key: value frontmatter
        $meta = [];
        foreach (explode("\n", $frontmatterRaw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $colonPos = strpos($line, ':');
            if ($colonPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $colonPos));
            $value = trim(substr($line, $colonPos + 1));
            $meta[$key] = $value;
        }

        if (empty($meta['title']) || empty($meta['date'])) {
            return null;
        }

        // Generate excerpt: strip markdown formatting, take first ~120 chars
        $excerpt = preg_replace('/[#*_\[\]()>`~]/', '', $body);
        $excerpt = preg_replace('/\s+/', ' ', $excerpt);
        $excerpt = trim($excerpt);
        if (mb_strlen($excerpt) > 120) {
            $excerpt = mb_substr($excerpt, 0, 117) . '...';
        }

        return [
            'title' => $meta['title'],
            'date' => $meta['date'],
            'body' => $body,
            'excerpt' => $excerpt,
        ];
    }
}
