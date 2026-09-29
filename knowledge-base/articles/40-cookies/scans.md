---
id: cookies.scans
title: Cookie Scans — running, scheduling and reading results
area: Cookies
knowledgebase: Cookies
url: /scans
menu_path: Compliance > Scans
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [scan, crawl, schedule, beacons, scripts, scan-history, cancel-scan, scan-results, detection]
related: [cookies.list, cookies.categories, cookies.register-changes, selfhost.scanner, consent.reports]
source_files:
  - templates/pages/scans/index.html.twig
  - templates/pages/scans/detail.html.twig
  - templates/pages/scans/_scan_table.html.twig
  - src/Scanning/Controller/ScanListHandler.php
  - src/Scanning/Controller/ScanStartHandler.php
  - src/Scanning/Controller/ScanScheduleHandler.php
  - src/Scanning/Controller/ScanCancelHandler.php
  - src/Scanning/Controller/ScanDetailHandler.php
questions:
  - How do I scan my website for cookies?
  - How do I schedule automatic cookie scans?
  - Why is the Scan Now button disabled?
  - How long does a scan take?
  - How do I cancel a running scan?
  - What is a beacon in the scan results?
  - My scan found no cookies
  - What pages does the scanner crawl?
  - Do I get notified when a scan finds new cookies?
---

# Cookie Scans — running, scheduling and reading results

## Where to find it

**Compliance → Scans** in the sidebar. URL: `/scans`. A scan's results live at `/scans/{id}`.

## What it does

Crawls your website with a real browser, loads each page, and records every cookie and
third-party script it sees. Results feed the cookie list, which drives what the banner blocks
and what your cookie policy publishes.

A scan runs automatically when you first add a site. After that you run them on demand or on a
schedule.

## Summary cards

| Card | Shows |
|---|---|
| **Last Scan** | When it completed, plus cookies and beacons found |
| **Next Scheduled** | Date, time and frequency of the next automatic scan |
| **Cookies Detected** | Cookie count from the last scan, and how many scripts they came from |

## Scan history table

| Column | Meaning |
|---|---|
| Status | Queued, running, completed, failed or cancelled |
| Cookies | Cookies found |
| Beacons | Third-party scripts and tracking requests found |
| Started | When it began |
| Actions | Open the detail view, or cancel a running scan |

The table refreshes itself while a scan is in progress.

## Start Scan

**Scan Now** opens a confirmation modal and queues a full scan. It is **disabled while a scan is
already running** for this site — one at a time.

## Schedule modal

| Field | Options | Notes |
|---|---|---|
| **Frequency** | One-time / Monthly | Monthly is the usual choice |
| **Scan Date** / **Start Date** | Date picker | Label changes with frequency |
| **Time** | Time picker | Defaults to 03:00. Off-peak is kinder to your server |

Monthly scans matter because sites change: a new marketing tag added by a colleague introduces
cookies that will sit Unclassified — and therefore blocked — until someone notices.

## Scan detail (`/scans/{id}`)

| Section | Contents |
|---|---|
| Summary cards | Cookies Found, Beacons / Scripts, Categories |
| **Scan Details** | Started, Completed, Attempts |
| **By Category** | Cookie counts per category |
| **Detected Cookies** | Name, Domain, Category, Expiry, HttpOnly, Secure, SameSite |
| **Third-Party Scripts & Beacons** | URL / Domain, Type, First Seen |

Beacons carry the same classification actions as cookies: **Classify** an unclassified beacon, or
**Request category change** with an optional reason for one already classified.

## Register changes and alerts

Every completed scan — manual or scheduled — compares the site's cookie register (cookies AND
third-party scripts) with the previous state. Anything added, removed or recategorised is
recorded on the change timeline at `/cookies/changes`, triggers an **email alert** (per-site
toggle on the timeline page, on by default), and appears as a "Cookie register changes" section
in scheduled scan reports. See Knowledgebase: Cookies - Document: register-changes.md.

## Common questions

**Do I get notified when a scan finds new cookies?**
Yes — any register change (new cookie, removed cookie, category change) sends an email alert
after the scan completes, unless you turned alerts off on the changes timeline page.

**Why is Scan Now disabled?**
A scan is already queued or running for this site. Wait for it, or cancel it from the history
table.

**How long does a scan take?**
Minutes for a small site; longer for large ones, and longer again when the scan queue is busy.
The status column updates live.

**My scan found nothing.**
Usual causes: the site is behind HTTP basic auth, a login wall, or a firewall that blocks the
scanner; the domain in Conzent does not resolve (check for a typo, or `www` vs apex); or the
site returns an error to non-browser user agents. On self-hosted installs, also confirm the scan
server is registered and healthy — see Knowledgebase: Self-Hosting - Document: scanner.md.

**Does the scanner find cookies behind a login?**
No. It crawls publicly reachable pages. Cookies that only appear after login are caught by the
beacon in the consent script, which reports what real visitors' browsers set — those appear in
the cookie list with a `client` tag.

**What is the difference between a cookie and a beacon here?**
A cookie is stored in the browser. A beacon is a third-party script or tracking request the page
loaded. Both are blocked before consent; they are listed separately because they are found
differently.

**Can I choose which pages get scanned?**
Not from this page — the scanner crawls from the site's domain. Very large sites are covered by
a page cap that depends on your plan.

**Do scans affect my analytics?**
The scanner loads your pages with a real browser, so it can register as traffic. Schedule scans
off-peak, and filter the scanner out of your analytics if the volume is noticeable.

**A scan failed. What now?**
Open the detail view and check the Attempts count. Re-run it. Persistent failures usually mean
the scanner cannot reach your site — check firewall rules and whether the domain resolves
publicly.

## Related

- Knowledgebase: Cookies - Document: cookies-list.md — what the scan populates
- Knowledgebase: Cookies - Document: categories.md — classifying what was found
- Knowledgebase: Self-Hosting - Document: scanner.md — running your own scanner
- Knowledgebase: Consent - Document: reports.md — including scan data in a report
