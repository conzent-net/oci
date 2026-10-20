---
id: plugins.extension
title: Consent Mode Inspector — browser extension
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Chrome) Extensions > Consent Mode Inspector > side panel
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [extension, chrome, debug, inspector, consent-mode, tcf, meta, clarity, uet, amazon, troubleshooting, verify]
related: [compliance.gcm, compliance.tcf, integrations.signals, sites.install-script]
questions:
  - How do I check if Google Consent Mode is working?
  - How do I debug my consent banner?
  - Is there a Conzent browser extension?
  - How do I verify TCF is working on my site?
  - How do I tell if I am in Basic or Advanced consent mode?
  - How do I check the Meta pixel consent signal?
  - My consent signals are not firing — how do I diagnose it?
source_files:
  - plugins/getconzent_extension/manifest.json
  - plugins/getconzent_extension/sidepanel.html
  - plugins/getconzent_extension/inject.js
  - plugins/getconzent_extension/_locales/en/messages.json
---

# Consent Mode Inspector — browser extension

## Where to find it

**Consent Mode Inspector by GetConzent**, a Chrome extension. Install it, open any website, click
the extension icon, and the inspector opens in Chrome's side panel next to the page.

## What it does

Reads the live consent state on whatever page you are on and shows what each platform is actually
being told. It is a debugging tool: use it when consent signals are not behaving and you need to
see the truth rather than infer it.

It works on **any** site, not only sites using Conzent — which makes it equally useful for
auditing a prospect's existing CMP.

## Header

| Element | Shows |
|---|---|
| Domain | The page being inspected |
| **GCM** indicator | Whether Google Consent Mode was detected |
| Overall status | A rollup badge — `No Data` until signals are seen |

## Tabs

| Tab | What it inspects |
|---|---|
| **Google** | Consent Mode signals — `ad_storage`, `ad_user_data`, `ad_personalization`, `analytics_storage`, `functionality_storage`, `personalization_storage`, `security_storage` — with a status pill. Plus a **Google Additional Consent** card: AC version, consented and disclosed provider counts, and the raw AC string |
| **Clarity** | Microsoft Clarity: whether the `clarity` function exists, whether `consent()` was called, and the resulting ad/analytics storage state |
| **Amazon** | Amazon Consent Signal: the `acs` state, ad storage, user data, and whether `apstag` is present |
| **UET** | Microsoft UET (Bing Ads) consent state |
| **Meta** | Meta / Facebook Pixel consent state |
| **TCF** | IAB TCF: whether the CMP API is present, and the TC string being published |

Each tab carries a **Not Detected / Detected** status pill, so an empty panel distinguishes
"the platform is not on this page" from "the platform is here but no signal reached it".

## How it works

The extension injects a script at `document_start` in the page's main world so it can observe
`gtag`, `fbq`, `uetq`, `clarity`, `apstag` and the TCF API from the moment the page begins
loading. It needs `activeTab`, `storage`, `tabs`, `sidePanel` and `scripting` permissions to do
that.

Because it hooks at document start, **reload the page with the panel open** — signals fired
before the panel existed will not appear.

## Diagnosing common problems

| Symptom | What to look for |
|---|---|
| Google reports missing consent signals | Google tab shows **Not Detected** → the Conzent tag sits after a Google tag, or `async`/`defer` was added to it. The loader pushes the Consent Mode defaults itself, but only if it runs first in `<head>` |
| Unsure whether you are in Basic or Advanced | Google tab shows signals present *before* any consent choice → Advanced. Nothing until the visitor chooses → Basic |
| TCF vendors not bidding | TCF tab shows no CMP API or an empty TC string → TCF is off, or `CMP_ID` is not set on a self-hosted install |
| Meta pixel underreporting | Meta tab shows Not Detected after accepting marketing → **Meta Consent Mode** is off in **Banners → Advanced Settings** |
| Clarity recording when it should not | Clarity tab shows consent granted while analytics was refused → check the Clarity cookies are classified Analytics |
| A tracker fires before consent | Signals show granted before any interaction → the script is not first in `<head>`, or the tracker is in the **Script Whitelist** |

## Common questions

**Does it work on sites that do not use Conzent?**
Yes. It reads the standard platform APIs, so it inspects any CMP.

**Why does everything say "No Data"?**
Open the side panel, then reload the page. The extension hooks at document start; anything that
fired before the panel opened is invisible to it.

**Does it change the page?**
No. It observes and reports; it does not modify consent state.

**Is there a Firefox version?**
It is a Manifest V3 Chrome extension using Chrome's side panel API. Check the store listing for
other browsers.

**Does it send my data anywhere?**
It reads consent state locally in your browser to display it.

**Can I use it to check a competitor's CMP?**
Yes — that is one of its uses. It shows what any site's consent implementation actually signals.

## Related

- Knowledgebase: Compliance - Document: google-consent-mode.md — what the Google signals mean
- Knowledgebase: Compliance - Document: iab-tcf.md — reading the TC string
- Knowledgebase: Integrations - Document: consent-signals.md — Meta, Microsoft, Amazon
- Knowledgebase: Sites - Document: install-script.md — fixing script order
