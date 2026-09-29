---
id: banner.advanced
title: Banner Settings — Advanced Settings
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Advanced Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [advanced, iframe, youtube, delay, purge-cache, renew-consent, whitelist, gtm, meta, uet, clarity, amazon, consent-signals, samesite, punch-out, cross-site, advertiser-consent-mode]
related: [banner.general, sites.install-script, integrations.signals, compliance.gcm]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Controller/BannerUpdateHandler.php
  - src/Banner/Controller/BannerPurgeCacheHandler.php
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - How do I stop YouTube videos being blocked?
  - How do I make the banner appear faster?
  - My changes are not showing on my site
  - How do I force everyone to consent again?
  - How do I stop Conzent blocking a specific script?
  - What is Allow Google Tags to fire before consent?
  - How do I send consent signals to Meta or Microsoft?
  - Where do I set my GTM container ID?
  - What is the script whitelist?
  - What should the consent cookie SameSite be set to?
  - Consent is not remembered in our B2B punch-out flow
  - What is Google advertiser consent from TCF?
---

# Banner Settings — Advanced Settings

## Where to find it

**Configuration → Banners** (`/banners`), the **Advanced Settings** section near the bottom.

## What it does

Site-level behaviour that is not about how the banner looks: what gets blocked, how fast it
appears, which ad-platform consent signals fire, GTM auto-injection, and the two maintenance
actions — renew consents and purge cache.

Unlike the sections above, these settings are stored on the **site**, not on the banner.

## Fields

### Blocking and timing

| Field | What it does | Default |
|---|---|---|
| **Block Iframes** | `Only Youtube` blocks YouTube embeds until consent; `All` blocks every third-party iframe | Only Youtube |
| **Banner Display Delay (ms)** | How long after page load before the banner appears. 1000 = 1 second | 2000 |
| **Consent Cookie SameSite** | The SameSite attribute on the consent cookies: `Lax (recommended)` / `Strict` / `None (cross-site / punch-out)` | Lax | 

Blocking still happens immediately regardless of the delay — the delay only affects when the
banner becomes visible. `None` always sends `Secure` too and is only needed for cross-site
embeds and B2B punch-out flows; `Lax` keeps consent readable on ordinary inbound links.

### Languages and Google tags

| Field | What it does | Default |
|---|---|---|
| **Load All Languages** | Loads the full pre-translated language set so the banner can match any visitor's browser language. Otherwise only your configured languages load | Off |
| **Allow Google Tags to fire before consent** | Lets Google tags load in a restricted, cookieless mode before consent. Required by IAB Europe and TCF 2.4 if you use TCF, and the mechanism behind Consent Mode **Advanced** | Off |

### Consent signals

Each switch controls one ad or analytics platform's consent signal. Turning one off stops
Conzent telling that platform anything.

| Field | What it does |
|---|---|
| **Google Consent Mode V2 (GCM)** | Master switch for Google consent signals. Off means no signals reach Google services |
| **Google advertiser consent from TCF** | Lets Google tags derive advertising consent from the TCF string (`enableAdvertiserConsentMode`). On by default; turn off only if you do not want Conzent signalling consent to Google tags via TCF |
| **Meta Consent Mode** | Sends `fbq` consent signals based on marketing-cookie consent |
| **Microsoft UET Consent Mode** | Sends signals to `window.uetq` for Microsoft Advertising (Bing) based on marketing consent |
| **Microsoft Clarity Consent Mode** | Sends `clarity("consent")` based on **analytics** consent, independently of the others |
| **Amazon Consent Signal** | Sends consent to Amazon Publisher Services (`apstag`) and the Amazon Ads Tag |

### Google Tag Manager auto-inject

| Field | What it does | Notes |
|---|---|---|
| **GTM Container ID** | e.g. `GTM-XXXXXXX`. Conzent injects GTM for you with consent-aware loading | Optional. Leave blank if you load GTM yourself |
| **GTM Data Layer Name** | A non-default data-layer name | Leave empty for `dataLayer`. When set, the install snippet gains `data-dl` |

### Maintenance actions

| Action | What it does |
|---|---|
| **Renew User Consents → Renew Now** | Invalidates every stored consent for this site, so all visitors are asked again on their next visit. Shows when it was last done |
| **Purge Script Cache → Purge & Regenerate** | Rebuilds the consent script and clears every cache layer — browser, CDN and Redis |

### Script Whitelist

A textarea, one URL or keyword per line. Anything matching is **never** blocked by the banner.
Partial URLs (`cdn.jsdelivr.net/npm/alpinejs`) and filenames (`alpine.min.js`) both work.

Use it for scripts your site cannot function without that are being caught by the blocker. Do
not whitelist analytics or advertising scripts — that defeats consent and is the single most
common way sites become non-compliant while appearing to have a CMP.

## How to

**Stop YouTube being blocked** — you generally should not, but if the videos are essential to
the page and you have a lawful basis, set **Block Iframes** to a narrower option or whitelist
the specific embed. The better fix is that the blocked-content placeholder invites the visitor
to accept, which unblocks it.

**Fix "my changes aren't showing"** — **Purge & Regenerate**, then hard-refresh your site and
purge any CDN in front of it.

**Force re-consent after a policy change** — **Renew Now**. Every visitor sees the banner again
on their next visit. GDPR expects this when your processing purposes materially change.

**Unblock a broken script** — add its URL fragment or filename to the **Script Whitelist**, one
per line, then save and purge.

## Common questions

**What should Consent Cookie SameSite be set to?**
Leave it on `Lax` unless something specific breaks. `Strict` can make a visitor arriving from an
external link look consent-less on their first page view. `None` (always paired with `Secure`)
exists for cross-site embeds and B2B punch-out flows, where the storefront is loaded inside
another system and the consent cookie must travel cross-site.

**Consent is not remembered in our B2B punch-out / procurement flow.**
Set **Consent Cookie SameSite** to `None (cross-site / punch-out)` and save. Punch-out loads
your site in a cross-site context, and `Lax`/`Strict` cookies are withheld there.

**What is "Google advertiser consent from TCF"?**
On TCF sites, Google tags can read advertising consent directly from the TC string
(`enableAdvertiserConsentMode`). Leave it on — it is how Google tags honour the TCF choice.
Turning it off means Conzent stops signalling consent to Google tags through TCF.

**What does "Allow Google Tags to fire before consent" actually do?**
It is the switch behind Consent Mode **Advanced**. Google tags load in a cookieless mode before
consent so Google can model conversions from visitors who decline. Basic mode does not load them
at all. TCF 2.4 requires it if you run TCF.

**Does turning off Meta/Microsoft/Amazon signals block their pixels?**
No. Blocking is separate and category-driven. These switches only control whether Conzent sends
each platform an explicit consent *state*. With a signal off, the pixel is still blocked until
consent, but the platform is never told what the visitor chose.

**Why is Clarity separate from the others?**
Clarity is analytics, not advertising, so it is keyed to **analytics** consent while the ad
signals key to **marketing** consent.

**I set a GTM container ID and now GTM loads twice.**
Remove your manual GTM snippet from the page — either Conzent injects it or you do, not both.

**Does Renew Now delete my consent logs?**
No. Existing records stay in **Consent Logs** for your audit trail. It only invalidates the
stored consent in visitors' browsers.

**What is the "Disable Banner on Specific Pages" field I've read about?**
It exists in the markup but is hidden — the early blocker needed to support it is not
implemented yet. Do not rely on it.

## Related

- Knowledgebase: Banner - Document: banner-general.md — geo targeting, TCF, consent expiry
- Knowledgebase: Sites - Document: install-script.md — where the snippet goes
- Knowledgebase: Integrations - Document: consent-signals.md — the ad-platform signals in detail
- Knowledgebase: Compliance - Document: google-consent-mode.md — Basic vs Advanced
