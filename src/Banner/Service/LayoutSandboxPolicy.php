<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Twig\Sandbox\SecurityPolicy;

/**
 * What a banner layout is allowed to do.
 *
 * Layout source is authored by customers and compiled by Twig. Without a
 * sandbox that is arbitrary PHP execution, not a templating feature: Twig's
 * `map`, `filter`, `reduce` and `sort` filters accept a callable by NAME, so
 * `{{ [2,7]|reduce('max') }}` calls PHP's max() — and the same shape calls
 * system(). Verified on 2026-09-04, which is why this class exists.
 *
 * The allowlist is derived from what the shipped layouts actually use — six
 * tags and two filters — then widened only with constructs that are safe by
 * construction and plausibly useful to someone theming a banner.
 *
 * ── What is deliberately excluded, and why ─────────────────────────────────
 *
 * `map`, `filter`, `reduce`, `sort`  — take a callable by name. The hole.
 * `include`, `embed`                 — compile a template chosen at runtime.
 *                                      Confirmed rejected by the policy.
 * `do`                               — evaluates an expression for effect only.
 * Every method and property          — layout data is plain arrays. Allowing
 *                                      method calls would re-open object
 *                                      traversal into the application.
 *
 * ── One honest limitation ──────────────────────────────────────────────────
 *
 * `use`, `import` and `from` are resolved by Twig's parser and are NOT checked
 * by SecurityPolicy, so leaving them out of the tag list does not reject them.
 * Measured, not assumed. They are bounded instead by the loaders: preview runs
 * on an ArrayLoader holding only the submitted template, so nothing resolves at
 * all, and script generation adds a FilesystemLoader rooted at the layouts
 * directory, so the worst case is one layout importing blocks from another
 * shipped layout. Neither reaches code. The template-name injection that made
 * this genuinely dangerous (CVE-2026-46633) is fixed in Twig 3.28.
 *
 * `extends` IS allowed: layouts legitimately extend the shipped base, and the
 * only other loader in the chain is a FilesystemLoader rooted at the layouts
 * directory, so a name chosen at runtime can still only reach a real layout
 * file — not arbitrary code.
 */
final class LayoutSandboxPolicy
{
    /** Structural tags. Nothing here compiles a runtime-chosen template. */
    private const TAGS = [
        'if', 'else', 'elseif', 'endif',
        'for', 'endfor',
        'block', 'endblock',
        'extends',
        'set', 'endset',
        'apply', 'endapply',
        'verbatim', 'endverbatim',
        'with', 'endwith',
    ];

    /**
     * Value transformations only. Every filter that accepts a callable is
     * absent by design — that is the whole point of the list.
     */
    private const FILTERS = [
        'abs', 'batch', 'capitalize', 'column', 'date', 'date_modify', 'default',
        'e', 'escape', 'first', 'format', 'join', 'json_encode', 'keys', 'last',
        'length', 'lower', 'merge', 'nl2br', 'number_format', 'raw', 'replace',
        'reverse', 'round', 'slice', 'split', 'striptags', 'title', 'trim',
        'upper', 'url_encode', 'spaceless',
    ];

    /**
     * No object traversal. Banner data is arrays, so nothing legitimate needs
     * to call a method, and allowing any would put application services back
     * within reach of template source.
     *
     * @var array<class-string, list<string>>
     */
    private const METHODS = [];

    /** @var array<class-string, list<string>> */
    private const PROPERTIES = [];

    /** `range` is bounded and pure; `max`/`min` are NOT here — see the class note. */
    private const FUNCTIONS = ['range', 'cycle', 'date'];

    public static function create(): SecurityPolicy
    {
        return new SecurityPolicy(
            self::TAGS,
            self::FILTERS,
            self::METHODS,
            self::PROPERTIES,
            self::FUNCTIONS,
        );
    }

    /** @return list<string> */
    public static function allowedTags(): array
    {
        return self::TAGS;
    }

    /** @return list<string> */
    public static function allowedFilters(): array
    {
        return self::FILTERS;
    }
}
