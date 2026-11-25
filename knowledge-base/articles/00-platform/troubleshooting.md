---
id: platform.troubleshooting
title: Troubleshooting — the most common problems
area: Platform
knowledgebase: Platform
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [troubleshooting, banner-not-showing, not-working, cache, changes-not-appearing, broken, help, fix, diagnose, outage, offline, fail-closed, pageview-limit, watchdog]
related: [sites.install-script, banner.advanced, dashboard.customer, plugins.extension]
source_files:
  - templates/pages/dashboard/customer.html.twig
  - templates/pages/banners/index.html.twig
  - src/Banner/Controller/BannerPurgeCacheHandler.php
  - src/Site/Controller/SiteVerifyHandler.php
questions:
  - My cookie banner is not showing
  - My changes are not appearing on my website
  - The banner shows the old text
  - Google Analytics stopped working after installing Conzent
  - Cookies are being set before consent
  - The banner shows on some pages but not others
  - My site broke after installing Conzent
  - Verification says the script is not installed
  - What happens when the Conzent server is unreachable?
  - The console says pre-consent blocking stays active
  - What happens when I hit my pageview limit?
---

# Troubleshooting — the most common problems

## Where to find it

Start with **Dashboard → step 4 → Run Verification**, which checks the script, GTM, TCF and
Consent Mode in one pass. The fixes below cover what it cannot.

## The two fixes that solve most cases

**1. Purge and regenerate.**
**Banners → Advanced Settings → Purge & Regenerate**, then hard-refresh your site
(Ctrl/Cmd+Shift+R), then purge any CDN or caching plugin in front of it. The generated script is
cached aggressively; almost every "I saved but nothing changed" is this.

**2. Test in a private window.**
Once you have consented, the banner does not reappear — that is the point. A private window, or
clearing the `conzentConsent` cookie, gives you a clean visitor.

## The banner is not showing

Work down this list:

| Check | Where |
|---|---|
| Site status is **Active**, not Disabled or Suspended | `/sites` |
| The `data-key` in your page matches the site's Website Key | `/sites`, Website Key column |
| The Conzent tag is in `<head>`, **first**, before every other script | Your site's source |
| Geo Targeting is not excluding you | Banners → General Settings |
| Banner Display Delay is not set very high (default 2000 ms) | Banners → Advanced Settings |
| You have not already consented in this browser | Private window |
| Your cache / CDN / caching plugin is not serving old HTML | Purge them |
| On Cloud: you have not hit your monthly pageview limit | Dashboard gauge |

## Changes are not appearing

1. Did you press **Save Changes** / **Save All Changes**? Banner content has its own
   **Save Content** button, separate from the page's save.
2. **Purge & Regenerate** in Advanced Settings.
3. Hard-refresh, and purge CDN / caching plugin.
4. Check you were editing the right site — the site selector in the page header.

## Google Analytics or Ads stopped recording

Expected in **GCM Basic** mode: analytics cookies are blocked until the visitor consents, so
refusals are not measured. If measurement went to *zero*, something is misconfigured:

| Check | Detail |
|---|---|
| Script order | Conzent must load before GTM, gtag.js and every analytics tag |
| The defaults reach the data layer | Console: `dataLayer.filter(a => a[0] === 'consent')` should show a `default` entry with everything denied. It is pushed from inside `consent.js`, so it is not in your page source |
| GCM master switch | **Banners → Advanced Settings → Google Consent Mode V2 (GCM)** |
| Data layer name matches | **GTM Data Layer Name** in Advanced Settings, if you use a custom one |
| Consider GCM Advanced | Google models conversions from visitors who declined |

See Knowledgebase: Compliance - Document: google-consent-mode.md.

## Cookies are being set before consent

The cookie list flags these with a red **pre-consent** badge.

| Cause | Fix |
|---|---|
| Conzent loads after the tracker | Move the Conzent tag first in `<head>` |
| The script is in the whitelist | Remove it from **Banners → Advanced Settings → Script Whitelist** |
| The cookie is classified **Necessary** | Reclassify it — Necessary is never blocked |
| The cookie is set server-side | Browser-side blocking cannot stop it. Fix it in your application |
| A tag manager fires it before Conzent | Load Conzent directly in `<head>`, not only through the tag manager |

## The banner shows on some pages but not others

The Conzent tag is missing from those page templates. With a CMS plugin this should not happen —
check the plugin is active site-wide. With a manual install, every template needs the tag. Note
that the (hidden) "Disable Banner on Specific Pages" field is not implemented and is not the
cause.

## Verification says the script is not installed, but it is

The checker fetches your page from the outside. It fails when:

- The tag sits below other scripts, or in `<body>`.
- A CDN or page cache is serving an old version.
- The site is behind HTTP basic auth, a firewall, or a bot filter.
- The Website Key belongs to a different site.

Purge, then re-run **Run Verification**.

## My site broke after installing Conzent

Conzent blocks third-party scripts before consent, so a script your site depends on may be
caught.

1. Identify it in the browser console.
2. If it is genuinely functional (not analytics or advertising), add its URL fragment or
   filename to **Banners → Advanced Settings → Script Whitelist**, one per line.
3. Save and **Purge & Regenerate**.

Do not whitelist analytics or advertising scripts — that defeats consent and leaves you
non-compliant while appearing to have a CMP.

## Server unreachable, outages and the fail-closed design

The banner is built to survive its own server being unreachable:

| Situation | What happens |
|---|---|
| Repeat visitor during an outage | The banner boots from state cached in the browser and works normally — banner shows, choices apply, blocking runs |
| Consent given during an outage | Stored locally in the visitor's browser and delivered to the server — exactly once — when it is reachable again. No consent records are lost |
| Geo-targeted banner, location lookup unavailable | The banner shows anyway by default (**Show banner when location is unknown** in Banner → General Settings) — the compliance-safe direction |
| First-ever visitor during a total outage, nothing cached | The banner cannot render, and blocking stays **on** (fail closed): trackers are never released by an outage. After ~20 seconds the browser console logs a clear warning that pre-consent blocking is active without a banner |

That console message — *"Pre-consent blocking stays ACTIVE (fail closed)"* — is the watchdog
for the last row. If you see it outside an outage, the script is failing to load: check the
installation and the server status page.

## Pageview limit reached (Cloud)

When a site passes its plan's monthly pageview limit, the banner is **paused**: it stops
showing, blocking is released, and the site runs without consent management until the limit
resets or the plan is upgraded. The browser console states this explicitly, including the
compliance implication. The dashboard gauge shows usage before it happens.

## Diagnosing consent signals

Install the **Consent Mode Inspector** extension, open the side panel, and reload the page. It
shows the live state for Google, Meta, Microsoft UET, Clarity, Amazon and TCF, so you can see
what each platform is actually being told. See Knowledgebase: Plugins - Document: browser-extension.md.

## Self-hosted extras

| Symptom | Fix |
|---|---|
| Banner broke after moving domain | `php bin/oci scripts:regenerate` — `APP_URL` is baked into every script |
| Password reset emails never arrive | `MAIL_HOST` is empty. Set SMTP; meanwhile use `php bin/oci user:password` |
| Scans stuck queued | No registered scanner, or `queue:work` is not running. See Knowledgebase: Self-Hosting - Document: scanner.md |
| Scheduled scans and reports never fire | `schedule:run` is not on cron |
| Testing changes constantly | Set `DISABLE_CACHE=true` temporarily |

## Related

- Knowledgebase: Sites - Document: install-script.md — correct installation
- Knowledgebase: Banner - Document: banner-advanced.md — purge, whitelist, delay
- Knowledgebase: Plugins - Document: browser-extension.md — live signal inspection
- Knowledgebase: Account - Document: dashboard-customer.md — Run Verification
