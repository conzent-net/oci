---
id: platform.glossary
title: Glossary — Conzent and privacy terminology
area: Platform
knowledgebase: Platform
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [glossary, terminology, definitions, jargon, acronyms, tcf, gcm, gpc, cmp]
related: [platform.overview, compliance.tcf, compliance.gcm, sites.frameworks]
source_files:
  - config/privacy-frameworks.json
  - templates/pages/banners/index.html.twig
  - README.md
questions:
  - What does TCF mean?
  - What is Google Consent Mode?
  - What is GPC?
  - What is a website key?
  - What is a preference center?
  - What does opt-in vs opt-out mean?
  - What is a beacon?
  - What is a TC string?
  - What does "pre-consent" mean on a cookie?
---

# Glossary — Conzent and privacy terminology

## Where to find it

Reference only. These terms appear across the app's labels and help text.

## What it does

Defines the vocabulary a support answer can safely use, so terms mean the same thing in the app
and in the answer.

## Conzent terms

| Term | Meaning |
|---|---|
| **Website Key** | The per-site identifier that ties your installed script to its configuration. Shown in the Website Key column on `/sites` and in the install snippet as `data-key` |
| **Site** | One website (one domain) in Conzent. Almost every setting is scoped to a site |
| **Banner / cookie notice** | The first-layer message shown to visitors. Types: Banner (full-width bar), Box (corner card), Popup (centred modal) |
| **Preference Center** | The second layer, where visitors toggle individual cookie categories |
| **Opt-out Center** | The US-privacy equivalent of the preference centre, used under CCPA/CPRA |
| **Revisit consent button** | The small floating button that reopens the banner after a choice was made |
| **Layout** | The HTML/Twig template that renders the banner. System layouts are read-only; duplicate one to make a custom layout |
| **Template** (dashboard) | A preset bundle of settings — GCM Basic, GCM Advanced, and their TCF variants |
| **Scan** | A crawl of your site that detects cookies and third-party scripts |
| **Beacon** | A third-party script or tracking request found by a scan, or reported by real visitors |
| **Observation** | A record that a real visitor's browser set a given cookie. Feeds the "Observed Nx" counter on the cookie list |
| **Pre-consent** | A cookie seen *before* the visitor consented. On a non-necessary cookie this is a compliance problem |
| **Consent proof** | The detail view of one consent record: timestamp, IP, country, categories, TC string, GCM state |
| **Associated domains** | Extra domains that share one site's consent state |
| **OCI** | Open Consent Infrastructure — the open-source, self-hostable core of Conzent |

## Regulation and standards terms

| Term | Meaning |
|---|---|
| **CMP** | Consent Management Platform. Conzent is one |
| **CMP ID** | Your registration number with IAB Europe, stamped into every TC string. Conzent Cloud is 446 |
| **GDPR** | EU General Data Protection Regulation. Opt-in: nothing non-essential loads before consent |
| **ePrivacy Directive** | The EU "cookie law" that sits alongside GDPR. Enabled together with GDPR by default |
| **CCPA / CPRA** | California privacy law. Opt-out: tracking may run until the visitor says stop |
| **LGPD / POPIA / PIPEDA / …** | Brazilian / South African / Canadian and other national laws. See Privacy Frameworks |
| **Opt-in** | Consent required *before* non-essential cookies load. GDPR model |
| **Opt-out** | Cookies may load; the visitor must be able to stop them. US model |
| **TCF (IAB TCF v2.4)** | Transparency & Consent Framework — the ad industry standard for passing consent to vendors |
| **TC string** | The encoded consent payload TCF vendors read |
| **GVL** | Global Vendor List — IAB's registry of vendors, purposes and features. Refreshed daily |
| **Google Additional Consent (AC)** | Signals consent for Google ad partners that are not on the IAB vendor list. Requires TCF |
| **GCM / Google Consent Mode v2** | Google's protocol for telling Google tags what the visitor allowed (`ad_storage`, `analytics_storage`, `ad_user_data`, `ad_personalization`, …) |
| **GCM Basic vs Advanced** | Basic: Google tags do not fire until consent. Advanced: tags fire in a cookieless mode before consent, so Google can model conversions |
| **GPC (Global Privacy Control)** | A browser signal meaning "do not sell my data". Some US laws require honouring it |
| **DNT (Do Not Track)** | An older, largely unenforced browser signal |
| **Do Not Sell link** | The footer link US privacy laws require. Conzent generates the snippet |
| **Consent Mode default** | The `denied`-by-default `gtag('consent','default',…)` call that must run before any Google tag. Pushed from inside `consent.js` — not something you paste |

## Cookie categories

| Category | Meaning | Default |
|---|---|---|
| **Necessary** | Required for the site to work. Cannot be switched off | Always on |
| **Functional** | Remembers preferences (language, layout) | Off until consent |
| **Analytics** | Measures traffic and behaviour | Off until consent |
| **Marketing** | Advertising and cross-site tracking | Off until consent |
| **Unclassified** | Detected but not yet categorised. Treated as non-essential | Off until consent |

## Common questions

**What is the difference between a cookie and a beacon here?**
A cookie is stored in the browser. A beacon is a third-party script or tracking request the scan
saw the page load. Both are blocked before consent; they are listed separately because they are
found differently.

**What does "Advanced" Consent Mode change for me?**
Google tags load before consent in a restricted, cookieless mode, so Google can model
conversions from visitors who declined. It also means tags run before consent, which is why the
matching **Allow Google Tags to fire before consent** switch exists in Advanced Settings.

**Is a TC string the same as the Conzent consent cookie?**
No. `conzentConsent` is Conzent's own record of the visitor's choice. The TC string is the
IAB-format payload published for TCF vendors, and only exists when TCF is enabled.

## Related

- Knowledgebase: Compliance - Document: iab-tcf.md
- Knowledgebase: Compliance - Document: google-consent-mode.md
- Knowledgebase: Sites - Document: frameworks.md
- Knowledgebase: Cookies - Document: categories.md
