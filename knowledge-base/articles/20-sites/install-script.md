---
id: sites.install-script
title: Installing the consent script on your website
area: Sites
knowledgebase: Sites
url: /
menu_path: General > Dashboard > Quick Configuration > Install Script
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [install, script, snippet, website-key, head, gtm, data-key, banner-not-showing, embed, setup]
related: [dashboard.customer, plugins.overview, banner.advanced, integrations.gtm]
source_files:
  - templates/pages/dashboard/customer.html.twig
  - public/c/consent.js
  - public/c/shopify.js
  - src/Banner/Service/ScriptGenerationService.php
  - src/Site/Controller/SiteVerifyHandler.php
questions:
  - How do I install the Conzent script on my website?
  - How do I install Conzent on Shopify?
  - Where do I put the script tag?
  - My banner is not showing up
  - What is data-key?
  - Do I need a separate Consent Mode default block?
  - Can I install Conzent through Google Tag Manager?
  - How do I install Conzent on WordPress?
  - The banner shows old settings after I saved changes
  - What is data-dl?
  - Can I load the script async?
  - Does the banner work with a Content-Security-Policy?
  - How big is the consent script?
  - What happens if the Conzent server is down?
  - How do I install Conzent in Next.js or React?
---

# Installing the consent script on your website

## Where to find it

**General → Dashboard → Quick Configuration → step 3, Install Script.** The snippet there is
pre-filled with the selected site's Website Key. The key alone is also on `/sites`.

## What it does

Adds one `<script>` tag to your website. It sets Google Consent Mode to "denied" before
anything can load, then starts Conzent, which blocks trackers, shows the banner and applies the
visitor's choice.

## The tag

One tag — `<script src="…/c/consent.js" data-key="YOUR-KEY"></script>` — in `<head>`, **before
any other script**: analytics, ad tags, chat widgets, tag managers, everything.

There is no second tag to paste; everything happens inside `consent.js`. The loader is
parser-blocking on purpose (no `async`, no `defer`), so the browser runs it to completion before
it carries on parsing the page. That ordering is what makes the rest work:

| Runs inside the loader, before the page continues | Why it must be first |
|---|---|
| **Google Consent Mode defaults** | Pushes `consent default` with everything denied. If a Google tag loads first, that tag has already run with default-granted behaviour and the signal is too late |
| **IAB TCF stub** | Installs `__tcfapi`. Ad vendors probe for it the moment they initialise and never retry, so a missing stub drops them to non-personalised ads |
| **Tracker blocking** | Scripts and cookies are intercepted before they can set anything, then your configuration is fetched and the banner shown |

### Attributes on the loader

| Attribute | Required | What it does |
|---|---|---|
| `src` | Yes | Points at `/c/consent.js` on your Conzent server. Cloud uses the Conzent CDN; self-hosted uses your own `APP_URL` |
| `data-key` | Yes | Your site's Website Key. Ties the script to this site's configuration |
| `data-dl` | No | Custom data-layer name. Only emitted when you have set a non-default GTM data layer |
| `nonce` | No | Your page's CSP nonce, for sites running a strict Content-Security-Policy. The loader relays it to everything Conzent injects. Add `data-nonce` too if your framework strips `nonce` attributes |
| `async` / `defer` | Not alone | Never add either to the bare tag — Google and ad tags would run before the loader. If you need an async loader, use the **async install mode** below, which keeps the guarantees via an inline snippet |

The **Copy** button copies the tag with your Website Key already filled in.

## Installation routes

| Route | Best for | How |
|---|---|---|
| Direct in `<head>` | Any site you can edit | Paste the tag. This is the required, always-supported method |
| CMS plugin | WordPress, Wix, Drupal, Joomla, TYPO3, Umbraco | Install the plugin and paste only the Website Key. See Knowledgebase: Plugins - Document: overview.md |
| Google Tag Manager | Sites already running GTM | Optional. Connect from the dashboard's Install Script step — Conzent auto-injects GTM with consent-aware loading. See Knowledgebase: Integrations - Document: gtm-wizard.md |
| Matomo Tag Manager | Matomo users | Native Conzent CMP tag type. See Knowledgebase: Plugins - Document: matomo.md |

Installing via GTM alone is not enough — GTM itself loads asynchronously, so the Conzent loader
still belongs directly in `<head>` for the blocking to be reliable.

## Async install mode (advanced)

For sites whose performance budget genuinely cannot carry a parser-blocking tag, there is a
supported async variant: a ~1 KB synchronous inline snippet keeps the two guarantees that must
not race (the Consent Mode denied default and the `__tcfapi` stub for ad vendors), and the
loader tag itself gets `async`. The tradeoff you accept: the pre-consent tracker blocker starts
only when the async loader arrives, so a very early third-party tag could slip through in that
window — the default blocking install closes it completely.

The snippet and the full reasoning live in the repository's `docs/embed-snippet.md` under
"Async install". Use the default blocking tag unless you have measured a problem.

## Frameworks and single-page apps

For React, Next.js and other JS frameworks, the `@getconzent/consent` npm package wraps the
install: a typed loader, a React `useConsent()` hook and a Next.js `<ConzentScript>` component
that preserves the blocking guarantees. See Knowledgebase: Sites - Document: js-api.md.

## Verify it worked

Use **Run Verification** on the dashboard (step 4). It checks the script is present, GTM is
configured, TCF is valid (when enabled) and Consent Mode v2 is active. To check by hand, open
the site in a private window — the banner should appear, and `document.cookie` should contain
`conzentConsent` after you choose.

## Self-hosted differences

The snippet's `src` points at your own `APP_URL`, which is baked into the generated script. If
you change `APP_URL` after installing, regenerate the scripts:

```bash
php bin/oci scripts:regenerate
```

Everything else is identical.

## Common questions

**My banner is not showing.**
In rough order of likelihood:

1. The site is **Disabled** or **Suspended** on `/sites`.
2. The tag was pasted in `<body>` or below other scripts instead of first in `<head>`.
3. The `data-key` does not match the site — compare it with the Website Key column on `/sites`.
4. **Geo Targeting** is set to EU or specific countries and you are outside them.
5. A page cache or CDN is serving the old HTML. Purge it.
6. You already consented on this browser — clear the `conzentConsent` cookie, or use a private
   window.
7. **Banner Display Delay** in Advanced Settings is set high; the default is 2000 ms.

**I saved changes but the banner still shows the old text.**
The generated script is cached. Use **Purge & Regenerate** in **Banners → Advanced Settings**,
then hard-refresh your site. See Knowledgebase: Banner - Document: banner-advanced.md.

**Do I need a separate Consent Mode default block?**
No. Older snippets had an inline `gtag('consent','default',{…denied…})` block in front of the
loader. It is gone: `consent.js` makes that push itself, synchronously, before any Google tag
can initialise — and unlike the hardcoded block it honours `data-dl`, so a renamed GTM data
layer gets the defaults too. Sites still carrying the old two-tag snippet keep working, but you
can safely drop the inline block and keep the single tag.

**Can I load the script from my own CDN?**
Serve it from the Conzent server so the version handshake works — the loader fetches
`version.json` first and cache-busts `script.js` from it. Put a CDN in front of your Conzent
server instead of copying the file.

**How do I install on Shopify?**
Paste the tag first in `<head>` of your theme's `theme.liquid`, then add the Shopify bridge tag
directly after it:

```html
<script src="https://…/c/consent.js" data-key="YOUR-KEY"></script>
<script src="https://…/c/shopify.js"></script>
```

The bridge forwards the visitor's choice to Shopify's Customer Privacy API
(`setTrackingConsent`), so Shopify's own tracking and privacy-aware apps honour the banner.
Without it the banner shows but Shopify never receives the consent signal. Both tags use the
same host as your snippet.

**What is `data-dl` for?**
A custom data-layer name. If your GTM setup uses something other than `dataLayer`, set it in
**Banners → Advanced Settings → GTM Data Layer Name** and it appears in the snippet.

**Does the script slow my site down?**
The loader is ~10 KB and the site bundle is around 100–125 KB compressed on the wire — visitors
fetch only the site's main language, with other languages loading lazily on demand. Everything
is cache-busted by content hash, so repeat visits hit cache. The loader is parser-blocking by
design — that is what lets a single tag get the ordering right — and there is a documented
async install mode if your performance budget demands it.

**What happens if the Conzent server is unreachable?**
The banner keeps working. Repeat visitors' browsers boot it from cached state, and consent given
during an outage is stored locally and delivered — exactly once — when the server returns.
Blocking is fail-closed throughout: an outage never lets trackers through, and never loses a
consent record.

## Related

- Knowledgebase: Account - Document: dashboard-customer.md — the four setup steps
- Knowledgebase: Plugins - Document: overview.md — CMS plugins
- Knowledgebase: Integrations - Document: gtm-wizard.md — Google Tag Manager
- Knowledgebase: Banner - Document: banner-advanced.md — cache purge, delay, script whitelist
