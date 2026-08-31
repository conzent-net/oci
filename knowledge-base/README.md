# Conzent Knowledge Base

Machine-readable product documentation for the Conzent CMP, written for **AI retrieval first**
and human reading second.

Every regular-user area of the app is covered: every page, every tab, every field — plus the
CMS plugins, the browser extension, and self-hosting. Each article states where the feature
lives (URL + menu path), what each control does, and which edition/plan it needs.

> **Scope.** Articles describe what an end user (customer, agency, admin) sees in the app.
> Platform-admin-only screens under `/admin` are documented at a summary level only, marked
> `audience: admin`. Internal architecture belongs in `development/`, not here.

---

## Layout

```
knowledge-base/
├── README.md              ← you are here
├── INDEX.md               ← master list of every article (id → title, URL, edition)
├── UPDATE-PROMPT.md       ← paste into Claude Code to refresh this KB after code changes
├── elasticsearch/
│   ├── README.md              indexing + retrieval strategy
│   ├── mapping.conzent-kb.json  index mapping (BM25 + dense vector, hybrid RRF)
│   ├── ingest-pipeline.json     optional pipeline (normalisation + timestamp)
│   ├── queries.md               copy-paste query cookbook for the support agent
│   └── import-kb.php            importer: frontmatter Markdown → ES bulk index
└── articles/
    ├── 00-platform/       what Conzent is, editions, navigation, roles, glossary
    ├── 10-account/        signup, onboarding, profile, billing, notifications
    ├── 20-sites/          site list, create wizard, frameworks, languages, install
    ├── 30-banner/         every banner settings section, layouts, translations
    ├── 40-cookies/        cookie list, categories, scans
    ├── 50-consent/        consent logs, consent proof, reports
    ├── 60-compliance/     checklist, policies, TCF, Google Consent Mode
    ├── 70-integrations/   GTM wizard, Matomo TM wizard, ad-platform signals
    ├── 80-growth/         A/B tests, revenue impact, agency (Cloud only)
    ├── 90-plugins/        WordPress, Wix, Drupal, Joomla, TYPO3, Umbraco, Matomo, extension
    └── 95-self-hosting/   install, env reference, CLI, scanner
```

---

## One folder = one knowledge base

Each folder under `articles/` ships as its **own independent knowledge base**. That is why
articles never reference each other by relative path — a path like `../00-platform/glossary.md`
does not survive the split.

| Folder | Knowledge base |
|---|---|
| `00-platform/` | Platform |
| `10-account/` | Account |
| `20-sites/` | Sites |
| `30-banner/` | Banner |
| `40-cookies/` | Cookies |
| `50-consent/` | Consent |
| `60-compliance/` | Compliance |
| `70-integrations/` | Integrations |
| `80-growth/` | Growth |
| `90-plugins/` | Plugins |
| `95-self-hosting/` | Self-Hosting |

### Cross-references

Every reference to another document — in the body and in the `## Related` section — uses this
form, whether the target is in the same knowledge base or a different one:

```
Knowledgebase: <Name> - Document: <file.md>
```

For example:

```markdown
## Related

- Knowledgebase: Platform - Document: glossary.md — opt-in, opt-out, GPC, DNS
- Knowledgebase: Banner - Document: banner-general.md — geo targeting and consent expiry
```

Inline, mid-sentence:

```markdown
See Knowledgebase: Compliance - Document: iab-tcf.md.
```

`import-kb.php` enforces this: it rejects any relative markdown link to a `.md` file, any
reference to an unknown knowledge base, and any reference to a document that does not exist in
the named one.

---

## Article contract

Every article is a Markdown file with YAML frontmatter. The frontmatter is the retrieval
surface; the body is what the agent quotes back to the user.

```yaml
---
id: banner.general                      # stable, dot-separated, unique — also the ES _id
title: Banner Settings — General        # what a human would call it
area: Banner                            # fine-grained product area (facet)
knowledgebase: Banner                   # which KB it ships in — MUST match the folder
url: /banners                           # where it lives in the app
menu_path: Configuration > Banners > General Settings
edition: [cloud, self-hosted]           # which editions have it
audience: [customer, agency, admin]     # who can see it
plan: any                               # any | paid | business  (Cloud plan gate)
tags: [banner, geo-targeting, tcf]      # free-form retrieval keywords
related: [banner.layout, sites.frameworks]   # article ids, not paths
source_files:                           # provenance — used by UPDATE-PROMPT to detect drift
  - templates/pages/banners/index.html.twig
questions:                              # anticipated user phrasings (embedded separately)
  - How do I only show the banner in Europe?
  - Where do I change how long consent lasts?
---
```

`area` and `knowledgebase` are deliberately separate and do **not** always match: `dashboard.customer`
lives in the Account knowledge base but has `area: Dashboard`, and `agency.customers` ships in
Growth with `area: Agency`. `knowledgebase` is always derived from the folder; `area` is the
finer-grained facet. The importer fails the build if `knowledgebase` disagrees with the folder.

### Required body sections

| Section | Purpose |
|---|---|
| `## Where to find it` | URL + click-path, in one or two sentences. Always first. |
| `## What it does` | Plain-language purpose. No implementation detail. |
| `## Fields` | A table of every control: **Field / What it does / Default / Notes**. |
| `## How to …` | Numbered task walkthroughs for the common jobs. |
| `## Common questions` | Bold question, short answer. Mirrors `questions:` frontmatter. |
| `## Related` | Sibling articles, in `Knowledgebase: <Name> - Document: <file.md>` form. |

Optional: `## Gotchas`, `## Edition differences`, `## Plan limits`.

### Writing rules

1. **Answer, don't essay.** Target 100–200 lines. The agent needs enough to answer precisely,
   not a manual.
2. **Every field gets a row.** If a control exists in the UI it appears in the Fields table,
   even if the answer is "leave the default".
3. **Name things exactly as the UI does.** `Accept All Button`, not "the accept button".
   Users search with the label they see on screen.
4. **State the edition/plan gate inline** where a field is gated, not only in frontmatter.
5. **No invented behaviour.** If the code doesn't say it, don't write it. Mark genuinely
   unknown behaviour `<!-- TODO: verify -->` rather than guessing.
6. **URLs are app-relative** (`/banners`), because self-hosted installs sit on any domain.
7. **Never link by relative path.** Cross-references use
   `Knowledgebase: <Name> - Document: <file.md>`, per the section above.

---

## How the support agent should use this

1. Run a hybrid search (BM25 over `title`/`body`/`questions` + kNN over the question vector) —
   see [elasticsearch/queries.md](elasticsearch/queries.md).
2. Prefer the article whose `questions` best matches the user's phrasing; those are written as
   the literal things people ask.
3. **Always give the user the path**: quote `menu_path` and `url` so they can get there
   themselves. On self-hosted installs prefix the URL with the customer's own domain.
4. Check `edition` before answering. Telling a self-hosted user to open `/billing` is wrong —
   that page does not exist for them.
5. Check `plan` before promising a feature. `plan: business` means the control is visible but
   locked on the entry plan.
6. Follow references across knowledge bases. A `Knowledgebase: Sites - Document: install-script.md`
   in the body means exactly that: the `install-script.md` document in the **Sites** knowledge
   base. Retrieve it by `knowledgebase` + document name, not by path.

---

## Keeping it current

Run [UPDATE-PROMPT.md](UPDATE-PROMPT.md) after any UI change. It diffs the `source_files`
listed in each article against git and rewrites only the articles whose sources moved.

After editing articles, re-index:

```bash
php knowledge-base/elasticsearch/import-kb.php --recreate
```

Ingest is idempotent: `id` in the frontmatter is the ES document `_id`, so re-running updates
in place rather than duplicating.
