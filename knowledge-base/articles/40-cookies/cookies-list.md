---
id: cookies.list
title: Cookies — the detected cookie list
area: Cookies
knowledgebase: Cookies
url: /cookies
menu_path: Compliance > Cookies
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [cookies, classify, unclassified, categories, pre-consent, observations, beacon, reclassify, search]
related: [cookies.categories, cookies.scans, cookies.register-changes, policies.overview, banner.content]
source_files:
  - templates/pages/cookies/index.html.twig
  - templates/pages/cookies/_cookie_table.html.twig
  - src/Cookie/Controller/CookieListHandler.php
  - src/Cookie/Controller/CookieClassifyHandler.php
  - src/Cookie/Controller/CookieReclassRequestHandler.php
  - src/Cookie/Controller/CookieResetObservationsHandler.php
questions:
  - Where do I see the cookies on my site?
  - What does unclassified mean?
  - How do I categorise a cookie?
  - What does the pre-consent warning mean?
  - How do I request a category change for a cookie?
  - What does "Observed 12x" mean?
  - What is Reset Observations?
  - A cookie is in the wrong category
  - What does the "client" tag on a cookie mean?
---

# Cookies — the detected cookie list

## Where to find it

**Compliance → Cookies** in the sidebar. URL: `/cookies`. Scoped to the site in the header's
site selector.

## What it does

Every cookie Conzent knows about on this site, grouped by category. Cookies get here two ways: a
**scan** crawls your site and finds them, and the **beacon** in the consent script reports what
real visitors' browsers actually set. Each cookie's category decides whether it is blocked
before consent.

## Page layout

A category menu down the left with a count per category, and the cookie cards for the selected
category on the right.

| Category | Icon | Blocked before consent |
|---|---|---|
| Necessary | Shield | No — always allowed |
| Functional | Sliders | Yes |
| Analytics | Chart | Yes |
| Marketing | Megaphone | Yes |
| Unclassified | Question mark | Yes — treated as non-essential |

## What each cookie card shows

| Field | Meaning |
|---|---|
| **Cookie Name** | The cookie's name. A `client` tag means it was reported by real visitors' browsers, not by a scan |
| **Domain** | The domain that set it |
| **Duration** | How long it persists |
| **Detection** | Security flags and warnings — see below |
| Observation line | `Observed 12x (3 pre-consent, 9 post-consent) — last seen …` |
| Description | The description from Conzent's global cookie database, when the cookie is known |
| Platform | The vendor or platform the cookie belongs to |
| Found on URL | The page a scan first saw it on |

### Detection badges

| Badge | Meaning |
|---|---|
| `HttpOnly` | Not readable by JavaScript |
| `Secure` | Sent over HTTPS only |
| `Lax` / `Strict` / `None` | The SameSite attribute |
| `pre-consent` (red) | Set **before** the visitor consented. On a non-necessary cookie this is a compliance failure |
| `pre-consent (Nx)` (amber) | Mostly set after consent, but seen N times before it |

Pre-consent badges are the single most useful thing on this page — they are direct evidence a
tracker is escaping the blocker.

## Header controls

| Control | What it does |
|---|---|
| Site selector | Switches which site's cookies you are viewing |
| Search | Filters cookies by name |
| **Reset Observations** | Clears all beacon observation data for the site and starts fresh. Confirmation required; data rebuilds as visitors browse |
| **Changes** | Opens the register change timeline (`/cookies/changes`) — what every scan added, removed or recategorised. See Knowledgebase: Cookies - Document: register-changes.md |
| **New Scan** | Jumps to **Compliance → Scans** to run a crawl |

## Per-cookie actions

| Action | Shown for | What it does |
|---|---|---|
| **Classify** | Unclassified cookies | Assigns a category. **Applies to your site immediately** |
| **Request change** | Already-classified cookies | Submits a reclassification request to Conzent for review. Your site's category does not change until it is approved |
| `Change requested` tag | Cookies with a pending request | Awaiting Conzent review |

Conzent maintains classifications centrally so that a cookie recognised on one site is
recognised everywhere. That is why moving an already-classified cookie is a request rather than
an edit, while an unclassified one is yours to assign.

### Classify modal

| Field | Required |
|---|---|
| Category | Yes — any category except Unclassified |

### Request change modal

| Field | Required |
|---|---|
| Move to | Yes — the target category |
| Reason | No — free text explaining why |

## How to fix common situations

**Clear the Unclassified bucket** — open the Unclassified category, **Classify** each cookie into
the right bucket. Unclassified cookies are blocked before consent, so leaving them is safe but
means visitors see them described vaguely in the preference centre.

**A cookie shows a red pre-consent badge** — something is setting it before the banner has run.
Check that the Conzent script is first in `<head>`, that the cookie is not in the **Script
Whitelist** under Advanced Settings, and that it is not being set server-side (server-set
cookies cannot be blocked from the browser). See
Knowledgebase: Sites - Document: install-script.md.

**A cookie is in the wrong category** — use **Request change** with a reason. Conzent reviews it
and, once approved, the change propagates to every site.

## Common questions

**What is the difference between a scan and an observation?**
A scan crawls pages you do not have to visit; observations come from real visitors, so they catch
cookies that only appear after login, after interaction, or in geographies your scanner does not
crawl from. Observed cookies carry the `client` tag.

**Should I reset observations?**
Only after you have fixed a blocking problem and want a clean baseline for the pre-consent
counters. It does not delete the cookies, just the observation history.

**A cookie I removed is still listed.**
Observations persist until reset, and scan results persist until the next scan. Run a fresh scan,
then **Reset Observations** if you want the counters cleared too. The removal itself is recorded
on the **Changes** timeline once the next scan completes.

**Where do these descriptions come from?**
Conzent's global cookie reference database, which ships pre-translated. Unknown cookies show no
description until they are classified and documented.

**How do I show this list on my website?**
Copy the embed code from **Banners → Content Settings → Cookie List** and paste
`<div class="cnz-cookie-policy"></div>` into a page. It renders the categorised table.

## Related

- Knowledgebase: Cookies - Document: categories.md — creating and editing categories
- Knowledgebase: Cookies - Document: scans.md — running and scheduling scans
- Knowledgebase: Cookies - Document: register-changes.md — the change timeline and alerts
- Knowledgebase: Compliance - Document: policies-overview.md — publishing the list in a policy
- Knowledgebase: Banner - Document: banner-advanced.md — the script whitelist
