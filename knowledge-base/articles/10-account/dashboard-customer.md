---
id: dashboard.customer
title: Dashboard — setup wizard, stats and recommendations
area: Dashboard
knowledgebase: Account
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency]
plan: any
tags: [dashboard, home, setup, quick-configuration, template, verify, compliance-score, pageviews, recommendations]
related: [sites.install-script, banner.general, compliance.gcm, agency.customers, growth.abtest]
source_files:
  - templates/pages/dashboard/customer.html.twig
  - templates/pages/dashboard/index.html.twig
  - src/Dashboard/Controller/DashboardHandler.php
  - src/Dashboard/Controller/ComplianceCheckHandler.php
  - src/Dashboard/Controller/ScriptCheckHandler.php
  - src/Dashboard/Controller/ApplyTemplateHandler.php
questions:
  - What do the four steps on the dashboard mean?
  - Which template should I choose, GCM Basic or Advanced?
  - What is my compliance score and how do I raise it?
  - How do I check if the Conzent script is installed correctly?
  - Where do I see how many pageviews I have used?
  - What are the recommendations on the dashboard?
  - How do I preview my banner?
  - Verification says the script is not installed but it is
---

# Dashboard — setup wizard, stats and recommendations

## Where to find it

`/` — the landing page after sign-in, and **General → Dashboard** in the sidebar. Agency
accounts get a different dashboard; see Knowledgebase: Growth - Document: agency.md.

## What it does

The dashboard is both the setup wizard and the health check for the currently selected site. It
walks you through the four steps to go live, then reports consent performance, pageview usage,
a compliance score and a list of things still to fix.

## Quick Configuration — the four steps

The steps are clickable in any order; they are a checklist, not a forced sequence.

### Step 1 — Apply Template

Four preset bundles. Choosing one pre-configures the banner settings and generates the site's
Website Key.

| Template | What it does | Gate |
|---|---|---|
| **GCM Basic** | Google Consent Mode v2 Basic. Google tags do not load until consent | Any plan |
| **GCM Advanced** | Google tags fire before consent in a cookieless mode so Google can model conversions | Paid plan |
| **GCM Basic + TCF** | Basic, plus IAB TCF v2.4 | Paid plan; requires TCF enabled on the server |
| **GCM Advanced + TCF** | Advanced, plus full TCF v2.4 | Paid plan; requires TCF enabled on the server |

TCF cards are disabled with an explanation when the server has no valid `CMP_ID`.

### Step 2 — Customize Banner

A shortcut to **Open Banner Settings** (`/banners`), where layout, content, colours and
translations live. If a template was applied, its name is shown so you know what the defaults
came from.

### Step 3 — Install Script

Two side-by-side options:

| Option | What it is |
|---|---|
| **Direct Script Install** (Required) | One `<script>` tag for your `<head>`: the Conzent loader with your `data-key`. It sets the Consent Mode defaults to denied itself, before anything below it runs. **Copy** puts it on the clipboard |
| **Google Tag Manager** (Optional) | Sign in with Google, pick a GTM account and container, and Conzent auto-injects GTM with consent-aware loading. An **Advanced** disclosure lets you set a custom data-layer name |

Full detail: Knowledgebase: Sites - Document: install-script.md.

### Step 4 — Verify

**Run Verification** opens a modal that live-checks the site:

| Check | What it confirms |
|---|---|
| Consent Script Installed | The loader tag is present and reachable on your domain |
| Google Tag Manager | GTM integration is configured correctly (skipped if you are not using GTM) |
| IAB TCF v2.4 | TCF compliance, when TCF is enabled (skipped otherwise) |
| Google Consent Mode V2 | GCM v2 is active and signals are configured |
| Overall Compliance | Rollup, with a list of any issues found |

Re-run it any time with **Re-run Checks**.

## Cards on the page

| Card | What it shows |
|---|---|
| **Monthly Pageviews** | A gauge of used vs plan limit. Warning at 80%, banner paused at 100%. Shows "Unlimited pageviews" on unlimited plans |
| **Page Views** | Trend chart with a 7 Days / 30 Days / All Time selector |
| **Site Status** | Active / Inactive badge, the compliance score gauge, the site's enabled privacy frameworks, and any framework warnings |
| **Recommendations** | A live checklist of remaining setup items. Each line is done (green), failed (red), checking (spinner) or informational (blue) |
| **A/B Test Performance** | Appears only when an experiment has data: consent rate by variant, consent breakdown, uplift, confidence and the winner |

## Header controls

| Control | What it does |
|---|---|
| Site selector | Switches which site the whole dashboard describes |
| **Preview** | Opens a fullscreen preview of the banner as it will appear on your site |

A yellow **No active plan** notice appears above everything on Cloud when you have no
subscription.

## Compliance score

A 0–100 rollup of whether the site's configuration satisfies the frameworks you enabled. Green
at 80+, amber at 50–79, red below. It moves as you complete recommendations, enable required
signals (GPC, Do Not Sell links), install the script, and generate policies. The Frameworks
panel underneath lists the specific warnings driving it down.

## Common questions

**Which template should I pick?**
GCM Basic if you want the strictest reading of GDPR — nothing Google loads before consent.
GCM Advanced if you run Google Ads and want conversion modelling from visitors who decline; it
lets Google tags run cookieless before consent. Add TCF only if you sell programmatic
advertising through IAB vendors.

**Verification says the script is not installed, but it is.**
Common causes: the tag was added below other scripts instead of first in `<head>`; a cache or
CDN is serving an old page; the site is behind HTTP auth or a firewall that blocks the checker;
or the Website Key belongs to a different site. Purge your own cache, then use **Purge &
Regenerate** in **Banners → Advanced Settings**.

**My compliance score dropped after I changed frameworks.**
Adding a framework adds its requirements. For example, enabling a US framework that mandates
GPC will flag the site until GPC handling is on. Open **Site Status → Frameworks → Manage** to
see each warning.

**The pageview gauge says I am over the limit.**
The banner stops serving until the next period or an upgrade. See
Knowledgebase: Account - Document: billing.md.

**Where did the A/B card go?**
It only renders when an experiment on this site has collected data. Cloud only.

## Related

- Knowledgebase: Sites - Document: install-script.md — the install routes in detail
- Knowledgebase: Banner - Document: banner-general.md — step 2 in full
- Knowledgebase: Compliance - Document: google-consent-mode.md — Basic vs Advanced
- Knowledgebase: Sites - Document: frameworks.md — what drives the warnings
