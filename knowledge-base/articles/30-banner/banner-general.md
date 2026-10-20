---
id: banner.general
title: Banner Settings — General Settings
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > General Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [banner, geo-targeting, countries, tcf, iab, google-consent-mode, additional-consent, consent-expiration, reload, do-not-sell, fail-open, url-passthrough, ads-data-redaction, gclid, attribution]
related: [banner.layout, banner.content, sites.frameworks, compliance.tcf, compliance.gcm]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Controller/BannerListHandler.php
  - src/Banner/Controller/BannerUpdateHandler.php
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - How do I show the banner only in Europe?
  - How do I show the banner only in certain countries?
  - How long does consent last before I ask again?
  - How do I turn on IAB TCF?
  - Why is the IAB TCF toggle locked?
  - What is Google Additional Consent?
  - How do I make the page reload after someone consents?
  - Where do I get the Do Not Sell link code?
  - How do I turn on Google Consent Mode?
  - What happens when the visitor's location can't be determined?
  - What is URL passthrough?
  - What is ads data redaction?
  - How do I keep ad attribution when visitors reject cookies?
---

# Banner Settings — General Settings

## Where to find it

**Configuration → Banners** (`/banners`), the first collapsible section, **General Settings**.
It is open by default.

## What it does

Sets who sees the banner, which compliance standards it publishes to, and how long a visitor's
choice lasts. These are the settings that change the banner's legal behaviour rather than its
appearance.

The page header shows the site's enabled frameworks, the active template and when the banner was
last updated. **Save Changes** at the top or **Save All Changes** at the bottom commits the whole
page, not just this section.

## Fields

| Field | What it does | Default | Notes |
|---|---|---|---|
| **Geo Targeting** | Where the banner is shown at all. `Worldwide` / `EU Countries & UK` / `Selected Countries` | Worldwide | Controls *whether* the banner appears. What it *does* per country comes from Privacy Frameworks |
| **Select Countries** | Multi-select country list with search | — | Only shown when Geo Targeting is `Selected Countries`. Picked countries appear as removable tags |
| **Show banner when location is unknown** | If the location lookup fails or times out, show the banner anyway instead of skipping it | On | Only shown when Geo Targeting is `EU Countries & UK` or `Selected Countries`. Keeping it on is the compliance-safe choice — see below |
| **IAB TCF v2.4 Support** | Publishes an IAB TC string for programmatic ad vendors | Off | Locked when the server has no valid `CMP_ID`. Requires an IAB Europe CMP membership. GDPR banner types only |
| **Google Additional Consent** | Signals consent for Google ad tech providers not on the IAB vendor list | Off | Only visible when IAB TCF is on |
| **Google Consent Mode v2** | Sends consent signals to Google tags (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`) | On | Also has a master switch in Advanced Settings |
| **URL passthrough** | While `ad_storage` is denied, passes ad click information (`gclid` and similar) through your internal page URLs so campaigns keep attribution without cookies | Off | Only visible when Google Consent Mode v2 is on |
| **Ads data redaction** | While `ad_storage` is denied, further redacts ads data — ad click identifiers are stripped from Google network requests | Off | Only visible when Google Consent Mode v2 is on |
| **Show CCPA Banner** | Displays the opt-out banner for US visitors under CCPA/CPRA | — | Only on CCPA / GDPR+CCPA banner types |
| **"Do Not Sell" Link Code** | Read-only snippet for your website footer, with a copy button | Generated | Only on CCPA / GDPR+CCPA banner types. Required by US privacy laws |
| **Consent Expiration (days)** | How long a visitor's choice is remembered before the banner asks again | 365 | Range 1–730. GDPR guidance is typically 6–12 months |
| **Reload page after consent** | Reloads the page when a visitor gives or updates consent | Off | Turn on if third-party scripts on your site only initialise at page load |

## Geo targeting vs privacy frameworks

These are frequently confused:

| | Geo Targeting | Privacy Frameworks |
|---|---|---|
| Question it answers | *Does the banner appear?* | *How does the banner behave?* |
| Where | Banners → General Settings | Compliance → Privacy Frameworks |
| Example | "Only show it to EU and UK visitors" | "EU visitors get opt-in blocking; Californians get a Do Not Sell link" |

Setting Geo Targeting to EU does **not** stop US visitors being tracked without consent — it
stops them being asked. Only restrict the banner geographically if you are sure no other law
applies.

## How to

**Show the banner only in Europe** — Geo Targeting → `EU Countries & UK` → Save.

**Show it in specific countries** — Geo Targeting → `Selected Countries`, search and tick each
country, then Save. Selected countries show as tags you can remove with the ×.

**Turn on IAB TCF** — the toggle only unlocks when the server has a valid, IAB-registered
`CMP_ID`. Conzent Cloud runs under CMP ID 446, so paid Cloud plans can enable it directly.
Self-hosters must register with IAB Europe and set their own `CMP_ID`. See
Knowledgebase: Compliance - Document: iab-tcf.md.

**Keep ad attribution for visitors who reject cookies** — turn on **URL passthrough** (and
usually **Ads data redaction** with it). Google's cookieless pings then carry click information
through your URLs, so paid campaigns keep attribution for consent-denied traffic.

**Add the Do Not Sell link** — copy the snippet with the copy button and paste it into your
site footer. It renders as a link that reopens the opt-out centre.

## Common questions

**What happens when the visitor's location can't be determined?**
With geo targeting on, the banner needs a location lookup to decide whether to show. If that
lookup fails or times out (network problems, blockers), **Show banner when location is unknown**
decides the outcome. On (the default) the banner shows anyway — the worst case is one
unnecessary dialog for an out-of-scope visitor. Off, the banner is skipped — which can mean
tracking fires for an EU visitor who was never asked. Only turn it off if you are certain no
opt-in law applies to any of your traffic.

**What is URL passthrough and should I enable it?**
When a visitor denies `ad_storage`, Google tags cannot use cookies to connect an ad click to a
later conversion. URL passthrough carries the click identifier (`gclid`) through your internal
page URLs instead, so Google Ads keeps campaign attribution without setting cookies. Enable it
if you run Google Ads; it only ever acts for consent-denied traffic.

**What is ads data redaction?**
With it on and `ad_storage` denied, Google's cookieless pings are further stripped of ad click
identifiers. It is the more privacy-conservative posture; pair it with URL passthrough so
attribution survives via URLs while the pings stay clean.

**Why is IAB TCF greyed out with a padlock?**
TCF is not enabled on this server. The help text says: set `CMP_ID` to a valid IAB-registered
CMP ID. The ID is encoded into every TC string you issue, so it must be your own — you cannot
reuse another CMP's.

**What does Google Additional Consent add on top of TCF?**
TCF only covers vendors on the IAB Global Vendor List. Many Google ad tech providers are not on
it. Additional Consent signals those separately, so it only exists when TCF is on.

**How long should consent last?**
365 days is the default. Many EU regulators expect 6–12 months. Shorter means more banners and
lower consent rates; longer risks a regulator's view that the consent is stale. If you need
everyone to re-consent right now, use **Renew User Consents** in Advanced Settings instead of
shortening this.

**Should I turn on "Reload page after consent"?**
Only if scripts on your site are not consent-aware and need a fresh page load to initialise.
It is a worse experience, so leave it off unless something is visibly broken after consent.

**I turned Google Consent Mode on here but signals still aren't firing.**
Check the master **Google Consent Mode V2 (GCM)** switch in **Advanced Settings** too, and
confirm the Conzent tag sits first in your page's `<head>`, above GTM and every Google tag. The
defaults are pushed from inside `consent.js`, so you will not find them in your page source — in
the browser console, `dataLayer.filter(a => a[0] === 'consent')` should show a `default` entry
with everything denied. See Knowledgebase: Sites - Document: install-script.md.

## Related

- Knowledgebase: Banner - Document: banner-layout.md — banner type and position
- Knowledgebase: Banner - Document: banner-content.md — buttons, links, branding
- Knowledgebase: Banner - Document: banner-advanced.md — GCM master switch, cache purge, consent renewal
- Knowledgebase: Sites - Document: frameworks.md — per-country behaviour
