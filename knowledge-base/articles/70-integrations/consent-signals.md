---
id: integrations.signals
title: Consent signals — Meta, Microsoft, Amazon
area: Integrations
knowledgebase: Integrations
url: /banners
menu_path: Configuration > Banners > Advanced Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [meta, facebook, fbq, pixel, microsoft, uet, bing, clarity, amazon, apstag, consent-signal, ad-platforms]
related: [banner.advanced, compliance.gcm, cookies.list, growth.impact]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - Does Conzent work with the Facebook pixel?
  - How do I send consent to Meta?
  - Does Conzent support Microsoft Advertising?
  - What is Microsoft Clarity consent mode?
  - Does Conzent support Amazon advertising consent?
  - Do I need to turn these on if the pixels are already blocked?
  - Which consent category controls each platform?
---

# Consent signals — Meta, Microsoft, Amazon

## Where to find it

**Configuration → Banners → Advanced Settings**, in the block of consent-signal toggles beneath
the Google Consent Mode switch.

## What it does

Blocking a tracker until consent is one thing; telling the platform *what the visitor chose* is
another. These switches make Conzent send an explicit consent state to each platform's own
consent API, so the platform behaves correctly for visitors who accepted, and knows to hold back
for those who did not.

## The signals

| Switch | Platform | API it calls | Keyed to |
|---|---|---|---|
| **Google Consent Mode V2 (GCM)** | Google Analytics, Ads, GTM, Floodlight | `gtag('consent', …)` | Marketing + Analytics, per signal |
| **Meta Consent Mode** | Meta / Facebook Pixel | `fbq('consent', …)` | **Marketing** consent |
| **Microsoft UET Consent Mode** | Microsoft Advertising (Bing) | `window.uetq` consent push | **Marketing** consent |
| **Microsoft Clarity Consent Mode** | Microsoft Clarity | `clarity("consent")` | **Analytics** consent |
| **Amazon Consent Signal** | Amazon Publisher Services (`apstag`) and Amazon Ads Tag | apstag consent state | **Marketing** consent |

Clarity is deliberately separate from UET: Clarity is session-analytics, not advertising, so it
follows analytics consent while the ad platforms follow marketing consent. A visitor who accepts
analytics but refuses marketing gets Clarity and not UET.

Google's is covered in depth separately — see Knowledgebase: Compliance - Document: google-consent-mode.md.

## Blocking vs signalling

These are two independent mechanisms, and the distinction matters when troubleshooting:

| | Blocking | Signalling |
|---|---|---|
| What it does | Stops the pixel loading before consent | Tells the platform the visitor's decision |
| Driven by | The cookie's category in **Compliance → Cookies** | These switches |
| If it is off | The tracker runs before consent — a compliance failure | The tracker is still blocked, but the platform is never told the state |

Turning a signal off does **not** unblock the pixel. It just means the platform gets no explicit
consent state, which for Meta and Microsoft means degraded measurement even for visitors who did
consent.

## Setting up a platform

Using Meta as the example; the others follow the same shape.

1. Install the Meta Pixel on your site as normal — Conzent does not install it for you.
2. Run a scan (**Compliance → Scans**) so its cookies are detected.
3. Check the pixel's cookies sit in **Marketing** under **Compliance → Cookies**. Classify them
   if they are Unclassified.
4. Turn on **Meta Consent Mode** in **Banners → Advanced Settings**.
5. Save, then **Purge & Regenerate**.
6. Verify in the browser: with marketing refused the pixel should not fire; after accepting it
   should, and the consent call should be visible in the network tab.

## Common questions

**Do I need these on if the pixels are already blocked?**
Yes, if you use the platform. Blocking protects you legally; signalling is what lets the platform
attribute and measure correctly for consenting visitors. Without the signal, Meta and Microsoft
treat traffic conservatively even when consent was given.

**Which category controls Meta?**
Marketing. A visitor who accepts analytics but refuses marketing does not get the pixel.

**Does Conzent install these pixels for me?**
No. Install them yourself, or use the GTM / Matomo wizards, which create the tags with consent
conditions attached. See Knowledgebase: Integrations - Document: gtm-wizard.md.

**I turned Meta Consent Mode on but nothing changed.**
Save the page, then **Purge & Regenerate** in Advanced Settings, then hard-refresh. Confirm the
pixel's cookies are classified Marketing, and that the pixel is not sitting in the **Script
Whitelist** — whitelisted scripts are never blocked.

**Does this cover TikTok, LinkedIn, Snapchat, Pinterest?**
There is no dedicated consent-signal switch for those. They are handled by category blocking, and
the GTM and Matomo wizards can create consent-gated tags for them.

**What about Amazon?**
The Amazon Consent Signal covers Amazon Publisher Services (`apstag`) and the Amazon Ads Tag,
keyed to marketing consent. Relevant if you monetise with Amazon's header bidding or run Amazon
advertising.

## Related

- Knowledgebase: Banner - Document: banner-advanced.md — where the switches live
- Knowledgebase: Compliance - Document: google-consent-mode.md — the Google signals
- Knowledgebase: Cookies - Document: cookies-list.md — classifying pixel cookies
- Knowledgebase: Integrations - Document: gtm-wizard.md — creating consent-gated tags
