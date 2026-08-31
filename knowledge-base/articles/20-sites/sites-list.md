---
id: sites.list
title: My Sites — the site list
area: Sites
knowledgebase: Sites
url: /sites
menu_path: General > Sites
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [sites, websites, domains, status, disable, delete, restore, website-key, suspended, edit-site]
related: [sites.create, platform.site-context, account.billing, sites.frameworks]
source_files:
  - templates/pages/sites/index.html.twig
  - src/Site/Controller/SiteListHandler.php
  - src/Site/Controller/SiteUpdateHandler.php
  - src/Site/Controller/SiteDeleteHandler.php
  - src/Site/Controller/SiteRestoreHandler.php
  - src/Site/Controller/SiteDestroyHandler.php
questions:
  - How do I add a website to Conzent?
  - Where do I find my website key?
  - Why is my site suspended?
  - What is the difference between disabling and deleting a site?
  - How do I restore a deleted site?
  - How do I change a site's domain?
  - I hit my domain limit, what now?
  - How do I permanently remove a site?
  - What does the compliance column mean?
---

# My Sites — the site list

## Where to find it

**General → Sites** in the sidebar. URL: `/sites`.

## What it does

Lists every website in your account with its status, key and compliance state, and provides
every lifecycle action: add, edit, disable, enable, delete, restore and permanently remove.

## Status tabs

Filter tabs above the table, each with a count. Deleted and Suspended only appear when sites are
in those states.

| Tab | Meaning |
|---|---|
| All | Every site except permanently removed ones |
| Active | Serving a banner |
| Disabled | Paused by you. Configuration and history kept |
| Deleted | Soft-deleted. Recoverable |
| Suspended | Offline for a billing or plan reason (Cloud only) |

## Columns

| Column | What it shows |
|---|---|
| Domain | The site's domain. Clicking it switches the app's site context and opens that site's dashboard |
| Site Name | Your friendly label, or the domain if none was set |
| Website Key | The identifier your installed script uses (`data-key`) |
| Status | Active / Disabled / Deleted / Suspended. Suspended shows a secondary reason |
| Compliance | Compliant (green), Partial (amber), Non-compliant (red), or Not checked |
| Created | When the site was added |
| Actions | Per-row buttons — see below |

### Suspension reasons

| Reason | Fix |
|---|---|
| No subscription | Subscribe on `/billing` |
| Plan limit | Upgrade, or disable/remove another site to free a slot |
| Subscription ended | Resubscribe |

## Row actions

| Button | Shown for | What it does |
|---|---|---|
| Dashboard (chart) | Not deleted | Switches to this site and opens its dashboard |
| Edit (pencil) | Active | Opens the three-step edit modal |
| Disable (pause) | Active | Pauses the banner. Nothing is lost |
| Enable (play) | Disabled, Suspended | Reactivates. Suspended sites also need the underlying reason cleared |
| Banner Settings (window) | Not deleted | Switches to this site and opens `/banners` |
| Delete (trash) | Not deleted | Soft-delete, with confirmation. Recoverable |
| Restore | Deleted | Brings the site back |
| Remove Permanently (×) | Deleted | Destroys the site and all its data. Irreversible |

## The Edit Site modal

Same three steps as the create wizard, prefilled:

| Step | Fields |
|---|---|
| 1 — Website | Domain (required), Site Name, Privacy Policy URL |
| 2 — Languages | Checkboxes for the languages this site's banner supports. The first is the default |
| 3 — Frameworks | GDPR and US Privacy cards, plus **More frameworks** grouped by region |

**Save Changes** commits all three steps.

## Plan limits (Cloud)

When you reach your plan's domain cap:

- **Add Site** is replaced by **Upgrade Plan**, with the cap shown beneath it.
- A blue notice explains how many active sites the plan allows versus how many you have.
- Trying to enable an extra site opens a **Plan limit reached** modal with an upgrade link.

Self-hosted installs have no cap.

## How to

**Add a site** — **Add Site** in the header opens the three-step wizard. See
Knowledgebase: Sites - Document: sites-create.md.

**Change a domain** — Edit → step 1 → change Domain → Save. The Website Key does not change, so
the installed script keeps working. Verify the domain matches exactly, without `https://` or a
trailing slash.

**Free up a plan slot** — Disable a site you are not using, or delete one and then **Remove
Permanently** to release the slot entirely.

**Recover a deleted site** — Deleted tab → **Restore**. It comes back disabled; enable it when
you are ready.

## Common questions

**Disable or delete — which do I want?**
Disable pauses the banner and keeps everything; reversible instantly. Delete is a soft-delete —
the site stops serving and moves to the Deleted tab, but data is kept and Restore brings it
back. Only **Remove Permanently** destroys anything.

**Does deleting a site delete its consent logs?**
Soft-delete keeps them. **Remove Permanently** destroys the site and all of its data, including
consent logs and scans. Export first if you need the audit trail.

**Where is my Website Key?**
The Website Key column here, and inside the install snippet on the dashboard's Install Script
step.

**Compliance says "Not checked".**
No compliance check has run for that site yet. Open its dashboard and use **Run Verification**,
or wait for the next scheduled check.

**Can I move a site to another account?**
Not from the UI. Contact support.

**Can two sites share one banner configuration?**
Not directly — settings are per site. Related domains that should share *consent state* are
handled with associated domains rather than a second site.

## Related

- Knowledgebase: Sites - Document: sites-create.md — the add-site wizard
- Knowledgebase: Platform - Document: site-context.md — how switching works
- Knowledgebase: Sites - Document: frameworks.md — the compliance column's source
- Knowledgebase: Account - Document: billing.md — plan limits and suspension
