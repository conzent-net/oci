<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

use OCI\Cookie\Repository\CookieCategoryRepositoryInterface;
use OCI\Cookie\Repository\CookieRepositoryInterface;

/**
 * bin/oci cookies:show and cookies:classify — the global cookie database
 * (oci_cookies_global), the classification every site falls back to.
 *
 * cookies:sync fills it from the Open Cookie Database; the Admin module's
 * Cookie Database screen, which the open-source edition does not include,
 * also edits single entries. cookies:classify is that edit: a thin wrapper
 * over CookieRepository::applyGlobalClassification(), the same call that
 * approving a reclassification request makes. A name with * is stored as a
 * pattern, as there.
 */
final class GlobalCookieCommands
{
    public function __construct(
        private readonly CookieRepositoryInterface $cookies,
        private readonly CookieCategoryRepositoryInterface $categories,
        private readonly OperatorContext $context,
    ) {}

    public function run(OperatorConsole $io): int
    {
        return match ($io->command()) {
            'cookies:show' => $this->show($io),
            'cookies:classify' => $this->classify($io),
            default => 1,
        };
    }

    /** @return list<array{0: string, 1: string}> */
    public static function help(): array
    {
        return [
            ['cookies:show --name=NAME', 'Show how the global cookie database classifies a cookie'],
            ['cookies:classify --name=NAME --category=SLUG', 'Set its global category [--domain= --description= --platform=]'],
        ];
    }

    private function show(OperatorConsole $io): int
    {
        $name = (string) $io->option('name');
        if ($name === '') {
            $io->error('Give the cookie name with --name=NAME.');

            return 1;
        }

        $row = $this->cookies->matchGlobal($name, $io->option('domain'));
        if ($row === null) {
            $io->line("{$name} is not in the global cookie database.");

            return 0;
        }

        $category = $this->categories->findCategory((int) $row['category_id']);
        $io->line('Entry:          ' . $row['cookie_name'] . ((int) ($row['wildcard_match'] ?? 0) === 1 ? ' (pattern)' : ''));
        $io->line('Category:       ' . ($category['slug'] ?? ('#' . $row['category_id'])));
        $io->line('Platform:       ' . ($row['platform'] ?? '-'));
        $io->line('Description:    ' . ($row['description'] ?? '-'));
        $io->line('Classified by:  ' . ($row['classified_by'] ?? '-'));

        return 0;
    }

    private function classify(OperatorConsole $io): int
    {
        $name = (string) $io->option('name');
        $slug = (string) $io->option('category');
        if ($name === '' || $slug === '') {
            $io->error('Give the cookie with --name=NAME and its category with --category=SLUG.');

            return 1;
        }

        $category = null;
        $slugs = [];
        foreach ($this->categories->getAllGlobalCategories() as $c) {
            $slugs[] = (string) $c['slug'];
            if ($c['slug'] === $slug) {
                $category = $c;
            }
        }
        if ($category === null) {
            $io->error("Unknown category: {$slug}. Use one of: " . implode(', ', $slugs) . '.');

            return 1;
        }

        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        // The entry this writes is the one stored under exactly this name;
        // a pattern that merely matches it is left alone and a new entry made.
        $before = $this->cookies->matchGlobal($name);
        if ($before !== null && (string) $before['cookie_name'] !== $name) {
            $before = null;
        }
        $this->cookies->applyGlobalClassification(
            $name,
            $io->option('domain'),
            (int) $category['id'],
            $io->option('platform'),
            $io->option('description'),
        );

        $this->context->record(
            $io,
            $actor['id'],
            $before === null ? 'create' : 'update',
            'GlobalCookie',
            $before !== null ? (int) $before['id'] : null,
            $before !== null ? ['category_id' => (int) $before['category_id']] : null,
            ['cookie_name' => $name, 'category' => $slug],
        );
        $io->line("{$name} is classified as {$slug} in the global cookie database.");

        return 0;
    }
}
