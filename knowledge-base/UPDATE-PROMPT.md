# Knowledge base update prompt

Paste the block below into Claude Code (from the repo root) whenever the UI changes. It refreshes
only the articles whose source files moved, so it is cheap to run often.

---

## Prompt — incremental refresh (the normal case)

````text
Refresh the Conzent knowledge base in knowledge-base/ against the current codebase.

RULES
- knowledge-base/README.md defines the article contract. Read it first and follow it exactly:
  required frontmatter keys, required body sections, the Fields table format, writing rules.
- Do not rewrite articles whose sources have not changed. This is an incremental pass.
- Never invent behaviour. If the code does not state it, do not write it. If something is
  genuinely ambiguous, add `<!-- TODO: verify -->` inline rather than guessing.
- Keep `id` values stable — they are the Elasticsearch document IDs. Renaming one orphans the
  old document. If an article must be split, keep the original id on the larger half.
- EACH FOLDER UNDER articles/ IS A SEPARATE KNOWLEDGE BASE. Never write a relative markdown
  link to another .md file — it does not survive the split. Cross-reference documents as
  `Knowledgebase: <Name> - Document: <file.md>`, in the body and in `## Related` alike, whether
  the target is in the same folder or another one. Folder → name mapping is in README.md.
- Every article carries `knowledgebase:` in its frontmatter and it MUST equal the folder's
  knowledge base name. Note `area:` is a different, finer-grained facet and may legitimately
  differ (e.g. dashboard-customer.md is in the Account KB with `area: Dashboard`).

STEPS
1. Collect every path listed under `source_files:` across knowledge-base/articles/**/*.md.
2. Run `git log --since="<date of last KB update>" --name-only --pretty=format:` and intersect
   with that list. If you do not know the last update date, use the newest `updatedAt` in the
   articles, or default to the last 30 days.
3. For each article with at least one changed source file:
   a. Read the current source files in full.
   b. Update the Fields table so every control that exists in the UI today has a row, and
      every row still corresponds to a control that exists.
   c. Update "Where to find it" if the route or menu entry moved (check config/routes.php and
      templates/components/sidebar.html.twig plus src/Modules/*/module.php for menu entries).
   d. Update `edition`, `plan`, `audience` if the gating changed (grep for `EditionService`,
      `checkFeature`, `canRemoveBranding`, `canDuplicate`, `maxDomains`, `maxLanguages`,
      `maxFrameworks`, and the `edition` key in module.php menu entries).
   e. Add any new anticipated `questions:` the change creates. Keep existing ones.
4. Detect entirely NEW user-facing routes: diff config/routes.php and every
   src/Modules/*/config/routes.php against the `url:` values across all articles. For each new
   route with middleware `web` (not `admin`, not `webhook`, not `guest`), write a new article in
   the right numbered folder.
5. Detect REMOVED routes the same way. Do not delete those articles — mark them
   `status: removed` in the frontmatter and add a note at the top of the body saying which
   release removed the feature and what replaced it. Support agents still get asked about
   features that no longer exist.
6. Update knowledge-base/INDEX.md so it lists every article with its id, title, URL and edition,
   grouped by knowledge base.
7. Report: which articles changed, which were added, which were marked removed, and any
   `<!-- TODO: verify -->` markers you left behind.
8. Run `php knowledge-base/elasticsearch/import-kb.php --dry-run`. It fails the build on a
   relative markdown link, a `knowledgebase:` that disagrees with the folder, or a reference to
   a knowledge base or document that does not exist. Fix anything it reports.

DO NOT re-index Elasticsearch — that is a separate command the operator runs.
````

---

## Prompt — full rebuild (rare; after a large refactor)

````text
Rebuild the Conzent knowledge base in knowledge-base/articles/ from scratch.

Read knowledge-base/README.md for the article contract, then work through the entire
user-facing surface of the app:

- config/routes.php and every src/Modules/*/config/routes.php — every route with `web`
  middleware is a page a user can reach.
- templates/components/sidebar.html.twig plus the `menu` array in every src/Modules/*/module.php
  — this is the authoritative menu structure; `menu_path` must match it.
- templates/pages/**/*.twig and src/Modules/*/templates/**/*.twig — every field, tab, modal,
  toggle, dropdown and table column.
- The handler behind each route (src/*/Controller/, src/Modules/*/Controller/) for validation
  rules, defaults, and gating.
- src/Shared/Service/EditionService.php for cloud vs self-hosted differences.
- config/pricing.json and config/pricing.oci.json for plan limits and feature keys.
- config/privacy-frameworks.json and config/conzent-compliance-checklists.json for the
  framework and checklist catalogues.
- plugins/*/README.md and plugins/*/readme.txt for the CMS plugins and the browser extension.
- README.md and .env.example for self-hosting, install and configuration.

One article per page or per major settings section. Cover every field. Follow the writing rules
in knowledge-base/README.md — 100–200 lines each, no essays.

Each folder is a separate knowledge base: stamp `knowledgebase:` to match the folder, and
cross-reference documents as `Knowledgebase: <Name> - Document: <file.md>` — never a relative
markdown link.

Finish by regenerating knowledge-base/INDEX.md and running
`php knowledge-base/elasticsearch/import-kb.php --dry-run` until it reports no errors.
````

---

## Prompt — verify only (no writes)

````text
Audit knowledge-base/articles/ against the codebase without editing anything.

Report, as a table:
1. Articles whose `url:` no longer matches any route in config/routes.php or any module's
   routes.php.
2. Articles whose `menu_path:` no longer matches sidebar.html.twig or a module.php menu entry.
3. Fields tables that are missing a control present in the referenced Twig template.
4. Fields tables listing a control that no longer exists in the template.
5. Routes with `web` middleware that no article documents.
6. Frontmatter that violates the contract in knowledge-base/README.md (missing required key,
   duplicate `id`, `related:` pointing at an id that does not exist, `knowledgebase:` that
   disagrees with the folder).
7. Any relative markdown link to a .md file, or any
   `Knowledgebase: X - Document: Y.md` reference naming a knowledge base or document that does
   not exist.
8. Any `<!-- TODO: verify -->` markers still in the articles.

Do not modify any files.
````

---

## After updating

```bash
# validate frontmatter without touching Elasticsearch
php knowledge-base/elasticsearch/import-kb.php --dry-run

# push the changes (only re-embeds articles whose body hash changed)
php knowledge-base/elasticsearch/import-kb.php
```

Set `KB_EMBED_URL`, `KB_EMBED_MODEL` and `KB_EMBED_KEY` first if you want vectors; without them
the import succeeds with BM25-only retrieval.

---

## Update triggers

Run the incremental prompt whenever any of these land:

| Change | Why |
|---|---|
| New route in `config/routes.php` or a module's `routes.php` | New page needs an article |
| New menu entry in `sidebar.html.twig` or `module.php` | `menu_path` in existing articles drifts |
| New field, toggle or tab in any `templates/pages/**` file | Fields table goes stale |
| Change to `config/pricing.json` limits or `feature_keys` | `plan:` gating is wrong |
| Change to `EditionService` | `edition:` gating is wrong |
| New or renamed privacy framework in `config/privacy-frameworks.json` | Frameworks article stale |
| Plugin release (`plugins/*/README.md`, `readme.txt`) | Version numbers and install steps stale |
| Change to `.env.example` | Self-hosting configuration article stale |
