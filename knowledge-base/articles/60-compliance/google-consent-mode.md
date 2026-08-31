---
id: compliance.gcm
title: Google Consent Mode v2
area: Compliance
knowledgebase: Compliance
url: /banners
menu_path: Configuration > Banners > General Settings > Google Consent Mode v2
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [google-consent-mode, gcm, gcm-v2, basic, advanced, ad-storage, analytics-storage, conversion-modelling, gtag, dma, url-passthrough, ads-data-redaction, gclid, attribution]
related: [banner.general, banner.advanced, sites.install-script, dashboard.customer, compliance.tcf]
source_files:
  - templates/pages/dashboard/customer.html.twig
  - templates/pages/banners/index.html.twig
  - src/Banner/Service/ScriptGenerationService.php
  - src/Dashboard/Controller/ApplyTemplateHandler.php
questions:
  - What is Google Consent Mode v2?
  - Basic or Advanced consent mode — which should I pick?
  - How do I turn on Google Consent Mode?
  - Why is Google Analytics not recording data?
  - Where are the Consent Mode defaults set?
  - Do I need Consent Mode if I use GTM?
  - What are ad_user_data and ad_personalization?
  - Google says my consent signals are missing
---

# Google Consent Mode v2

## Where to find it

Three places:

| Where | Control |
|---|---|
| **Dashboard → Quick Configuration → step 1** | The GCM Basic / GCM Advanced templates |
| **Banners → General Settings** | **Google Consent Mode v2** toggle |
| **Banners → Advanced Settings** | **Google Consent Mode V2 (GCM)** master switch, and **Allow Google Tags to fire before consent** |

## What it does

Consent Mode is Google's protocol for telling Google tags — Analytics, Ads, Tag Manager, Floodlight
— what the visitor agreed to. Conzent sets the signals from the visitor's banner choice. Without
a CMP sending them, Google tags in the EEA and UK operate degraded or not at all.

Consent Mode **v2** is mandatory for EEA and UK traffic under the Digital Markets Act; the v2
signals `ad_user_data` and `ad_personalization` were added for it.

## The signals

| Signal | Granted when the visitor accepts | Controls |
|---|---|---|
| `ad_storage` | Marketing | Advertising cookies |
| `ad_user_data` | Marketing | Sending user data to Google for advertising |
| `ad_personalization` | Marketing | Personalised advertising / remarketing |
| `analytics_storage` | Analytics | Analytics cookies |
| `functionality_storage` | Functional | Preference storage |
| `personalization_storage` | Functional | Personalisation storage |
| `security_storage` | Always granted | Security and fraud prevention |

## Basic vs Advanced

| | **GCM Basic** | **GCM Advanced** |
|---|---|---|
| Before consent | Google tags do not load at all | Google tags load in a restricted, cookieless mode |
| Data before consent | None | Anonymous, cookieless pings |
| Conversion modelling | Not available | Google models conversions from visitors who declined |
| Strictness | The stricter reading of GDPR | Relies on the cookieless pings being lawful without consent |
| Plan | Any plan | Paid plan on Cloud |
| Enabled by | The GCM Basic template | The GCM Advanced template, plus **Allow Google Tags to fire before consent** in Advanced Settings |

Advanced recovers a meaningful share of the ad performance lost to refusals, which is why it
exists. It also means tags execute before consent, so decide deliberately rather than by default.
The Revenue Impact dashboard quantifies what modelling recovers — see
Knowledgebase: Growth - Document: revenue-impact.md.

## URL passthrough and ads data redaction

Two optional signals in **Banners → General Settings**, shown when Google Consent Mode is on.
Both only act while `ad_storage` is **denied**:

| Toggle | What it does | Turn it on when |
|---|---|---|
| **URL passthrough** | Carries ad click information (`gclid` and similar) through your internal page URLs, so Google Ads keeps campaign attribution for consent-denied visitors without cookies | You run Google Ads |
| **Ads data redaction** | Further strips ad click identifiers from Google's cookieless network requests | You want the most privacy-conservative posture for denied traffic — usually paired with URL passthrough |

## Placement — the part people get wrong

Consent Mode requires a `gtag('consent','default',{…})` call that denies everything **before any
Google tag loads**. `consent.js` makes that call itself, synchronously, the moment the browser
reaches the tag — so there is nothing extra to paste. The dashboard's Install Script step gives
you one tag and one tag only:
`<script src="…/c/consent.js" data-key="YOUR-KEY"></script>`.

The loader is parser-blocking on purpose (no `async`, no `defer`), so its defaults are pushed
before the browser reaches any script below it. Keeping the push inside the file we serve has two
more benefits: it honours `data-dl`, so a renamed GTM data layer gets the defaults too, and no
page editor can mangle it — Blogger's gadget editor, for one, HTML-escapes the quotes in pasted
inline code, which used to break the defaults silently.

Two things still get this wrong:

| Mistake | What happens |
|---|---|
| The tag sits below a Google tag, or in `<body>` | That Google tag has already run in its default-granted state, so the signal arrives too late and Google discards it |
| `async` or `defer` was added to the tag | Parsing carries on past the loader, so Google tags win the same race |

The tag goes first in `<head>`, before analytics, ads, GTM and everything else. See
Knowledgebase: Sites - Document: install-script.md.

## Setting it up

1. **Dashboard → step 1** — apply **GCM Basic** or **GCM Advanced**.
2. **Dashboard → step 3** — copy the single script tag into `<head>`, above every Google tag,
   and do not add `async` or `defer`.
3. **Banners → General Settings** — confirm **Google Consent Mode v2** is on.
4. **Banners → Advanced Settings** — confirm the **Google Consent Mode V2 (GCM)** master switch
   is on. For Advanced, also turn on **Allow Google Tags to fire before consent**.
5. **Dashboard → step 4 → Run Verification** — the **Google Consent Mode V2** check confirms it.

## Common questions

**Analytics stopped recording after installing Conzent.**
Working as designed for Basic mode — analytics cookies are blocked until the visitor consents,
so refusals are not measured. Options: switch to Advanced for modelled conversions, or improve
your consent rate. Sudden *total* silence usually means the Analytics tag is firing before the
Conzent script — the Conzent tag was pasted below it, or `async`/`defer` was added — so the
defaults land after Google has already initialised.

**Do I need Consent Mode if I use Google Tag Manager?**
Yes — GTM is a container, not a consent mechanism. The Conzent loader still belongs directly in
`<head>`; GTM loads asynchronously and cannot reliably block on time. Conzent can auto-inject GTM
with consent-aware loading from the Install Script step.

**Google Ads says my consent signals are missing.**
Check, in order: the Conzent tag is the first script in `<head>`, above every Google tag; it has
no `async` or `defer`; the GCM master switch in Advanced Settings is on; and the data-layer name
matches (set **GTM Data Layer Name** in Advanced Settings if you use a custom one). Then purge
with **Purge & Regenerate**.

**Where is the consent default script? I cannot find it in my page source.**
You will not — it is inside `consent.js`, not in your HTML. Older snippets pasted an inline
`gtag('consent','default',{…denied…})` block in front of the loader; that block is gone and the
loader does the push itself. Do not add one back by hand.

**Which mode do most publishers use?**
Advanced, when they run Google Ads and want conversion modelling. Basic, when their legal
position is that no Google tag should execute before consent.

**Does Consent Mode replace TCF?**
No. Consent Mode signals Google tags; TCF signals IAB vendors. Publishers running programmatic
advertising typically need both — hence the combined dashboard templates. See
Knowledgebase: Compliance - Document: iab-tcf.md.

**Does this cover Meta and Microsoft too?**
No. Those are separate switches in **Banners → Advanced Settings**. See
Knowledgebase: Integrations - Document: consent-signals.md.

## Related

- Knowledgebase: Sites - Document: install-script.md — the single loader tag and where it goes
- Knowledgebase: Banner - Document: banner-advanced.md — the master switch and tag-firing toggle
- Knowledgebase: Compliance - Document: iab-tcf.md — TCF alongside Consent Mode
- Knowledgebase: Growth - Document: revenue-impact.md — what modelling recovers
