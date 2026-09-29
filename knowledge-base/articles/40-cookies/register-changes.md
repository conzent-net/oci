---
id: cookies.register-changes
title: Register changes — the cookie change timeline and alerts
area: Cookies
knowledgebase: Cookies
url: /cookies/changes
menu_path: Compliance > Cookies > Changes
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [changes, change-log, register, history, alerts, email, added, removed, recategorized, audit, maintainability]
related: [cookies.list, cookies.scans, consent.reports, policies.overview]
source_files:
  - templates/pages/cookies/changes.html.twig
  - src/Cookie/Controller/CookieChangesHandler.php
  - src/Cookie/Controller/CookieChangesAlertToggleHandler.php
  - src/Cookie/Service/CookieRegisterDiffService.php
questions:
  - How do I see what changed in my cookies over time?
  - Why did I get an email about cookie register changes?
  - How do I turn cookie change alerts on or off?
  - A cookie disappeared from my list — where did it go?
  - What counts as a register change?
  - Does the change log cover scripts too?
---

# Register changes — the cookie change timeline and alerts

## Where to find it

**Compliance → Cookies** (`/cookies`) → the **Changes** button in the page header, or directly
at `/cookies/changes`. Scoped to the site in the header's site selector.

## What it does

A permanent timeline of how this site's cookie register has changed over time. Every completed
scan — manual or scheduled — compares the current register (cookies **and** third-party
scripts) against the previous state and records the differences. A marketing tag someone added
last Tuesday, a cookie that quietly vanished, a category that changed: all of it becomes a
dated, reviewable record instead of a silent difference.

The very first scan of a site sets the baseline and records no changes; the timeline starts
with the second scan.

## Fields

| Field | What it does | Default | Notes |
|---|---|---|---|
| **Email me on changes** | Sends an alert email whenever a scan records any change | On | Per-site. Goes to the scan-report recipient, or the site owner |
| **All / Cookies / Beacons** tabs | Filter the timeline by entry type | All | Beacons are third-party scripts, identified per script — one domain can carry entries in several categories |
| Change badges | `Added` (green) / `Removed` (red) / `Recategorized` (amber) / `Attributes` (grey) | — | Attributes covers security-flag changes: HttpOnly, Secure, SameSite |

Entries are grouped by day, newest first, with pagination.

## What counts as a change

| Change | Recorded when |
|---|---|
| **Added** | A cookie or script appears that the previous register did not have |
| **Removed** | An entry from the previous register is gone |
| **Recategorized** | The same entry now resolves to a different category — shows old → new |
| **Attributes** | A cookie's HttpOnly / Secure / SameSite flags flipped between two known states |

A cookie merely gaining details it lacked before (a client-observed cookie getting full
attributes from its first server scan) is enrichment, not a change, and is not recorded.

## How to

**Review what a scan changed** — run the scan, wait for it to complete, open **Changes**. The
day's entries are at the top.

**Turn alerts off (or on)** — toggle **Email me on changes** in the page header. It saves
immediately, per site.

**Chase a disappeared cookie** — filter to Cookies, find the red `Removed` entry, and you have
the date it left the register and what category it was in.

## After a change: what to check

A changed register can affect two other things you publish:
1. **Your cookie policy** — if it embeds the cookie table, regenerate it so the published list
   matches reality. See Knowledgebase: Compliance - Document: policies-overview.md.
2. **New Unclassified entries** — an added cookie usually lands Unclassified (blocked, vaguely
   described). Classify it. See Knowledgebase: Cookies - Document: cookies-list.md.

## Common questions

**Why did I get a "Cookie register changed" email?**
The latest scan recorded at least one change. The mail lists the changes with the same badges
as the timeline; the **Review changes** button links here. If you don't want these mails for
this site, toggle **Email me on changes** off.

**Does this cover third-party scripts, not just cookies?**
Yes. Scripts (beacons) are tracked per script path, so one domain — say Google's — can have a
necessary reCAPTCHA entry and a marketing ads entry, and each is tracked separately.

**Why is my timeline empty?**
Either the site has had fewer than two completed scans (the first scan is the baseline), or
nothing has changed between scans. That second case is the good news it looks like.

**Do changes appear without running a scan?**
No — the diff runs when a scan completes. Between scans, the live cookie list can still gain
client-observed entries; those become recorded changes at the next scan.

**Where do the alert emails go?**
To the scan report schedule's email address if one is set (Reports page), otherwise to the site
owner's account email.

## Related

- Knowledgebase: Cookies - Document: cookies-list.md — the live register the timeline tracks
- Knowledgebase: Cookies - Document: scans.md — the scans that trigger the diff
- Knowledgebase: Consent - Document: reports.md — register changes in scheduled reports
