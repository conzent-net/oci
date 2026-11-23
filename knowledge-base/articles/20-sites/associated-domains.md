---
id: sites.associated-domains
title: Associated Domains — one banner across domain aliases
area: Sites
knowledgebase: Sites
url: /sites/associated
menu_path: Configuration > Associated Domains
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [associated-domains, alias, multi-domain, markets, subdomains, consent-sharing, domain-mismatch]
related: [sites.list, sites.install-script, banner.general]
source_files:
  - templates/pages/sites/associated.html.twig
  - src/Site/Controller/AssociatedDomainListHandler.php
  - src/Site/Controller/AssociatedDomainAddHandler.php
  - src/Site/Controller/AssociatedDomainRemoveHandler.php
questions:
  - How do I use one banner on two domains?
  - What is an associated domain?
  - The banner says my domain does not match the site
  - Do subdomains need to be added as associated domains?
  - Is consent shared between my domains?
  - Can my .se and .dk domains share one configuration?
---

# Associated Domains — one banner across domain aliases

## Where to find it

**Configuration → Associated Domains** in the sidebar. URL: `/sites/associated`. Scoped to the
site shown as a tag in the page header.

## What it does

An associated domain is an **alias** that shares this site's banner, cookie list and consent
configuration — for example a second market domain (`example.se`) serving the same site as
`example.dk`. Add the alias here and the same install snippet works on both domains without
creating a second site.

Two things it does **not** do:
- **Subdomains never need an entry.** `shop.example.com` is covered automatically by the
  registered `example.com` — consent is even shared across subdomains via the cookie domain.
- **Consent is not shared between different domains.** A visitor who consents on `example.dk`
  consents again on `example.se` — browsers do not allow a cookie to span unrelated domains,
  and no CMP can change that. Associated domains share the *configuration*, not the *consent*.

## Fields

### The table

| Column | What it shows |
|---|---|
| **Domain** | The alias hostname |
| **Privacy Policy URL** | The alias's own policy link, when one was set |
| **Added** | The date the alias was added |
| **Actions** | Remove (trash) — confirmation required |

### Add Domain modal

| Field | Required | Notes |
|---|---|---|
| **Domain** | Yes | Just the hostname — no `https://`, no path. Example: `example.se` |
| **Privacy Policy URL** | No | Shown in the banner when it loads on this domain, instead of the main site's policy link |

## How to

**Share one configuration across two market domains** — select the main site, **Add Domain**,
enter the second domain's hostname, optionally its own privacy policy URL, **Add Domain**. Then
install the same snippet (same Website Key) on the second domain.

**Fix "this domain does not match the site"** — the banner refuses to load on a hostname the
site does not own. Either the `data-key` belongs to a different site, or the domain needs to be
added here as an associated domain.

**Stop serving an alias** — remove it with the trash button. The banner stops loading on that
hostname on the next script regeneration.

## When to use a separate site instead

Use an associated domain when the domains are genuinely the same site: same cookies, same
banner text needs, same policy structure. Create a **separate site** when the domains differ in
cookies, languages or legal setup — separate sites get independent scan results, cookie lists
and consent statistics.

## Common questions

**Do I add `www.example.com` as an associated domain?**
No — it is a subdomain of `example.com` and covered automatically.

**Is consent shared between the main domain and an alias?**
No. Configuration is shared; consent is stored per domain, because browsers scope cookies that
way. Each domain's visitors consent on that domain, and each consent record shows which domain
it was given on (the Domain column in Consent Logs).

**Does the alias get its own cookie scan?**
Scans run against the main site's domain. If the alias serves meaningfully different content or
tags, consider a separate site instead.

**The console says the domain doesn't match even though I added it here.**
Regenerate the script — **Banners → Advanced Settings → Purge & Regenerate** — and hard-refresh.
The domain list is baked into the generated script.

## Related

- Knowledgebase: Sites - Document: sites-list.md — sites vs aliases
- Knowledgebase: Sites - Document: install-script.md — the snippet and the domain guard
- Knowledgebase: Consent - Document: consent-logs.md — the per-domain consent records
