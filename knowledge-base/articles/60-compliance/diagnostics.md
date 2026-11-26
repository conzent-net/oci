---
id: compliance.diagnostics
title: Consent Mode diagnostics — finding what reads consent before the default
area: Compliance
knowledgebase: Compliance
url: /diagnostics
menu_path: Compliance > Diagnostics
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [diagnostics, consent-mode, tag-assistant, gtm, consent-initialization, read-before-default, wasSetLate, litespeed, wp-rocket, delay-js, page-optimizer, plugin-version, template-version, data-plugin, gallery-update, tag-gateway]
related: [compliance.gcm, integrations.gtm, plugins.wordpress, plugins.overview, sites.install-script, dashboard.customer]
source_files:
  - src/Modules/Diagnostics/Service/DiagnosticsService.php
  - src/Modules/Diagnostics/Service/StaticHtmlAnalyzer.php
  - src/Modules/Diagnostics/Service/ContainerAnalyzer.php
  - src/Modules/Diagnostics/Service/PluginCatalog.php
  - src/Modules/Diagnostics/Service/OptimizerCatalog.php
  - src/Modules/Diagnostics/Service/PageCacheAnalyzer.php
  - src/Modules/Diagnostics/Service/ScannerProbe.php
  - src/Modules/Diagnostics/templates/index.html.twig
  - docker/scanner/lib/consent-mode.js
questions:
  - Tag Assistant says "A tag read consent state before a default was set" — is that Conzent or my setup?
  - How do I run the Consent Mode diagnostic?
  - Why does setting the Conzent CMP tag priority to 100 not fix the warning?
  - Which tags may fire on Consent Initialization?
  - Does LiteSpeed Cache or WP Rocket break Conzent?
  - How do I know whether my GTM template or CMS plugin is outdated?
  - Which version of the Conzent CMP template is the latest?
  - Can I run the diagnostic for a Conzent Classic (conzent.net) site?
  - I fixed my settings but the diagnostic still fails. Is it my cache?
  - Which page optimizers and caches does the diagnostic recognise?
  - What does each diagnostic check mean?
---

# Consent Mode diagnostics

## Where to find it

**Compliance → Diagnostics** (`/diagnostics`). The dashboard's **Run Verification** modal links to
it as "Consent Mode & Google Tag diagnostic". Pick the site in the top-right selector and click
**Run diagnostic**. A run takes up to 25 seconds; the last result stays on the page.

## What it does

Answers one question a Tag Assistant screenshot cannot: **what, on this site, reads consent before
the default is set — and whose problem is it.** Four layers, each isolated so a failure in one never
hides the others:

| Layer | Source | What it looks at |
|---|---|---|
| **Homepage HTML (static)** | A server-side fetch of the homepage, and a second fetch past the cache when needed | Is the Conzent loader there, first in `<head>`, parser-blocking, untouched by a page optimizer; which Google tags the page hardcodes and in what order; which CMS plugin build printed it (every Conzent plugin marks its loader tag with `data-plugin="<cms>/<version>"`); which page caches and CDNs served the page, and whether the cached copy is outdated |
| **Tag Manager container** | The compiled container (`gtm.js`) the page loads, downloaded from Google | Whether the Conzent CMP template is installed, on which trigger, with this site's key and platform, and **which gallery release it is**; **every other tag on Consent Initialization**; variable templates that read consent |
| **Conzent configuration** | This site's settings | Consent Mode template applied, TCF, the Google advertiser consent flag |
| **Deep scan (real browser)** | A headless Chromium load through the scan server | Data sent before consent, the live TCF object, and **Google's own consent registry** (`google_tag_data.ics`): was a default registered, and did Google flag a read before it (`wasSetLate`) — once as the page loaded, once after a simulated first interaction |

The verdict at the top names the root cause first: a delayed loader or a tag on the wrong trigger
outranks the live timing failure it explains.

## The checks

### Homepage HTML

| Check | Pass means | Fail / warn means |
|---|---|---|
| Conzent CMP installed | `/c/consent.js` is in the HTML with this site's key | Missing; or a WordPress plugin is active but printed no loader — usually because no Website Key is saved and verified in it, otherwise because an optimizer merged the loader into a combined file at the end of the page |
| Conzent loader runs first | The tag is parser-blocking, in `<head>`, not rewritten | Held back by a page optimizer (named, with its setting) or by any script type the browser does not run; a local copy made by LiteSpeed's Localize Resources or FlyingPress's self-hosting; carries `async`/`defer` without the async-install stub (the product that added it is named); sits in `<body>`; a Google tag precedes it — including a localized or deferred copy of the GTM snippet |
| Page cache and CDN | Informational: names every cache layer that served the homepage (with its date or age) and how to purge each | — |
| Cached copy is current | Runs only when a loader check fails on a cached page: a fresh copy fetched past the cache shows the same result, so the problem is in the current site | The fresh copy passes where the cached copy fails: visitors still receive an outdated page, and the fix is to purge the listed caches |
| Conzent loads before Google tags | The loader's byte offset precedes the first Google tag | A Google tag (hardcoded `gtag.js`, GTM snippet or an optimizer-rewritten copy) comes first |
| Google tag detected | Which tag IDs the page carries | A container different from the one Conzent manages; a hardcoded copy of a container Conzent also injects |
| CMS plugin version | The plugin that printed the loader — WordPress, Joomla, Drupal, TYPO3, Umbraco, Wix or Matomo Tag Manager, read from the `data-plugin` marker on the loader tag — is the current release | A version behind the current release, with the update path for that CMS; the Conzent Classic WordPress plugin for conzent.net (script handle `cnz-banner`), which is not the plugin for this platform; or GetConzent CMP and the Classic plugin active together. A loader injected from JavaScript (Matomo, Wix) is read from the live page in the deep scan instead |
| Google Tag Gateway | Gateway detected or not, and whether consent mode copes with it | Gateway with basic consent mode |

### Tag Manager container

| Check | Pass means | Fail / warn means |
|---|---|---|
| Conzent CMP template in GTM | The template fires on Consent Initialization – All Pages with this site's key and Platform set to GetConzent | Wrong trigger; additional consent checks on the CMP tag; another site's key; Platform set to Classic; no Platform saved at all (a tag saved before the field existed loads from conzent.net, whatever the editor shows); no template and no usable page loader either |
| Conzent CMP template version | The container runs the code of the latest gallery release, 41cfa2e (2026-09-30) | An older release, named with its date and what has changed since; accept the update under **Templates** in Tag Manager and publish. A gallery update never changes the tag's settings |
| Tags on Consent Initialization | Only the Conzent CMP tag | Any other tag — typically the Google tag or Conversion Linker — is named with its ID |
| Variables that read consent state | No template calls `isConsentGranted`, `addConsentListener` or `readAnalyticsStorage` | One does; in Tag Assistant preview that alone produces the warning. The live check decides whether it matters |

### Deep scan

| Check | Pass means | Fail means |
|---|---|---|
| Data sent before consent | No Google identifiers or data hits before consent (cookieless pings allowed in advanced mode) | A Google request fired pre-consent with storage granted or without consent state |
| Google Consent Mode integration (live) | The live TCData object carries `enableAdvertiserConsentMode` as configured | Flag missing, wrong type, contradicting the setting, or slower than 500 ms |
| Consent defaults before tags (live) | Defaults registered before any tag read them; `wasSetLate=false`. Also a pass: the loader set the defaults at once and only Tag Manager waited for the first interaction | Google flagged a read before the default; a gtag config preceded the default in the dataLayer; nothing consent-related ran until the first interaction (Delay JS, product named); scripts still held after a simulated interaction (warn); the defaults appeared seconds after the page was ready, released on a timer (warn); Google tags ran with no default at all |

## "A tag read consent state before a default was set"

This is what Tag Assistant shows on a **Consent Default** event when something asked Google for
the consent state before that default was applied. The template is rarely the cause; the cause is
whatever ran first.

**Why priority does not fix it.** Google applies a template's `setDefaultConsentState` only after
the whole Consent Initialization event and every tag on it has finished. Any other tag on that
trigger therefore reads consent before the default, whatever its priority. The Conzent CMP tag must
be the only tag on **Consent Initialization – All Pages**; the Google tag belongs on
**Initialization – All Pages**, Conversion Linker and conversion tags on **All Pages**.

The three causes, and which check identifies each:

| Cause | Where it shows | Check that names it |
|---|---|---|
| Another tag on the Consent Initialization trigger | Tag Assistant: the tag is listed under *Tags fired* on event 1, Consent Initialization | **Tags on Consent Initialization** — names the tag and its ID |
| The Conzent loader delayed, deferred, combined or placed below a Google tag on the page | Tag Assistant connects late; the Consent Default appears far down the event list | **Conzent loader runs first**, **Conzent CMP installed** — name the optimizer and the setting to change |
| A variable that reads consent (built-in Analytics Client ID / Session ID / Session Number, or a consent-state variable template) | Only in preview; a real page load is fine | **Variables that read consent state**, settled by **Consent defaults before tags (live)**: when the live check passes, the preview warning is a false positive |

**Before changing anything** make sure the site is on the current release of its CMS plugin (the
**CMS plugin version** check) and on the latest gallery release of the Conzent CMP template (the
**Conzent CMP template version** check; in Tag Manager open **Templates**, accept the update and
publish). Both are updated regularly, and older builds behave differently from what this article
describes. The template releases the check knows, newest first: 41cfa2e (2026-09-30, Platform
field mandatory), 3191c5a (2026-09-14, consent restored from the GetConzent cookie, no banner
inside Shopify pixels), a507345 (2026-09-01, URL passthrough and ads data redaction fixed),
5264ce3 to 23a3fb3 (2026-07-18 to 2026-08-29, the Platform selector), and the 2025 releases that
only knew conzent.net. The two newest run identical code, so the check reports them as the latest
either way.

## Page optimizer plugins

LiteSpeed Cache, WP Rocket, Perfmatters, FlyingPress, W3 Total Cache, Hummingbird, WP Meteor,
Google PageSpeed and Cloudflare Rocket Loader can **delay** every script until the visitor's first mouse movement or key press, **combine** scripts
into one file at the end of `<body>`, or add `defer`. Any of these turns the Conzent loader from
the first thing on the page into the last: until the visitor moves, there are no consent defaults,
no TCF stub and no tracker blocking, and the tag manager — if it was not delayed too — runs
unprotected. The loader itself asks to be left alone (`data-no-optimize`, `data-no-defer`,
`data-cfasync="false"`, `nowprocket`) from WordPress plugin 2.2.4 on, but an optimizer with
"combine external" or an older plugin build ignores that.

Exclude `consent.js` and `getconzent.com` (and your tag manager snippet) everywhere the optimizer
touches JavaScript:

| Optimizer | Where |
|---|---|
| LiteSpeed Cache | Page Optimization → Tuning → **JS Excludes**, **JS Deferred/Delayed Excludes** and **Guest Mode JS Excludes**, one entry per line, then Purge All. With Guest Optimization on, first-time visitors are delayed whatever Load JS Deferred says, and only Guest Mode JS Excludes applies to them |
| WP Rocket | File Optimization → **Excluded JavaScript Files**, and under Delay JavaScript execution → **Excluded JavaScript Files** |
| Perfmatters | Assets → JavaScript → exclusions for Delay and Defer |
| FlyingPress | JavaScript settings → Delay JavaScript exclusions; also exclude it from self-hosting third-party scripts |
| W3 Total Cache | Performance → User Experience → Delay Scripts (remove Conzent); Performance → Minify (never minify consent.js) |
| Hummingbird | Asset Optimization → Extra Optimization → Delay JavaScript Execution exclusions |
| Autoptimize | JavaScript options → Exclude scripts from Autoptimize |
| SiteGround Speed Optimizer | Frontend → JavaScript → exclude from Combine and from Defer |
| Jetpack Boost | Add `data-jetpack-boost="ignore"` to the tag, or switch off Defer Non-Essential JavaScript |
| Google PageSpeed (server module) | Add `data-pagespeed-no-defer` to the tag, or turn off `defer_javascript` |
| Cloudflare Rocket Loader | Disable Rocket Loader for the site, or add `data-cfasync="false"` before `src` on the tag |

Any other optimizer that rewrites the loader's script type is reported generically as "a page
optimizer"; the check fails either way.

Then clear every cache (plugin, server, CDN) and re-run the diagnostic.

## Page caches and CDNs

A page cache is healthy on its own. It matters for consent in one way: after you fix your install,
every cache layer keeps serving the old page until it is purged, and visitors — and this diagnostic —
receive the old page. The **Page cache and CDN** check names each layer that served the homepage:
LiteSpeed Cache, QUIC.cloud, WP Rocket, FlyingPress, W3 Total Cache, WP Super Cache, Cloudflare
(cache and APO), Hostinger's CDN, Kinsta, Varnish, Nginx server caches, or a generic CDN or proxy
cache. Each comes with its purge step. Some caches send no headers and are not identified.

When a loader check fails on a cached page, the diagnostic fetches the homepage once more with a
random query parameter no cache has stored, and compares. If the fresh copy passes, **Cached copy is
current** fails and heads the verdict: the fix is in place and only the purge is missing. If the
fresh copy fails the same way, the problem is in the current site. Conzent's own **Purge &
Regenerate** clears only Conzent's servers, never your site's cache.

## Common questions

**Is the warning Conzent's fault or mine?**
Run the diagnostic. If **Tags on Consent Initialization**, **Conzent loader runs first** or
**Conzent CMP installed** fails, the cause is in the container or on the page and the check says
what to change. If every check passes including the live one and the warning only appears in Tag
Assistant preview, it is a preview-only effect of consent-reading variables and visitors are not
affected.

**Can I run it for a Conzent Classic (conzent.net) site?**
No. The diagnostic runs for sites in this app, and its container checks expect the Conzent CMP
tag's Platform to be GetConzent. For a site that only exists on conzent.net, check the same things
by hand: in Tag Assistant, only the Conzent CMP tag may fire on event 1 (Consent Initialization);
the template version is shown under **Templates** in Tag Manager; and the page source shows
whether a page optimizer rewrote or delayed the scripts.

**I fixed my settings but the diagnostic still fails. Is it my cache?**
Look at **Cached copy is current**. If it fails, a cache still serves the old page: purge every
layer **Page cache and CDN** lists, closest to WordPress first, then re-run. If it passes, the fresh
page fails too and the fix is not in place yet.

**Which page optimizers and caches does it recognise?**
By name: the optimizers in the table under Page optimizer plugins and the caches under Page caches
and CDNs. Anything else that rewrites the loader is still reported, as "a page optimizer".

**The diagnostic says the container could not be downloaded.**
The container is unpublished, the ID in Banners → Advanced Settings is wrong, or Google answered
slowly. Publish the container, correct the ID, and re-run.

**The container analysis is missing entirely.**
No GTM container was found on the page and none is configured in Conzent, so there is nothing to
analyze; or the run spent its time budget on a slow homepage. Re-run once the site responds faster.

**The deep scan is skipped.**
No active scan server (self-hosted installs without the scanner sidecar), or the site blocked the
scanner. The HTML, container and configuration layers still give a complete static answer.

**Does the live check interact with my site?**
It loads the homepage once and, only when the page delayed its scripts, simulates one mouse
movement and key press so delayed scripts run. It never clicks the banner, never records a consent
and never counts a pageview — the scanner answers Conzent's own write endpoints itself.

**Does it tell me which variable reads consent?**
It names the sandbox API the template calls, not the variable's name — Tag Manager strips names
when it compiles the container. The built-in Analytics Client ID, Session ID and Session Number
variables are not visible this way at all; disable them in Variables → Configure if you want the
preview warning gone.

## Related

- Knowledgebase: Compliance - Document: google-consent-mode.md — the signals and placement rules
- Knowledgebase: Integrations - Document: gtm-wizard.md — the triggers the wizard uses
- Knowledgebase: Plugins - Document: wordpress.md — the plugin and page optimizers
- Knowledgebase: Sites - Document: install-script.md — why the loader must be first in `<head>`
- Knowledgebase: Account - Document: dashboard-customer.md — the Run Verification modal that links here
