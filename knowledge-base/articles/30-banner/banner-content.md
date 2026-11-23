---
id: banner.content
title: Banner Settings — Content Settings
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Content Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [buttons, accept, reject, customize, branding, powered-by, cookie-policy-link, revisit-button, gpc, cookie-list, embed-code]
related: [banner.general, banner.translations, policies.overview, sites.frameworks]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Controller/BannerUpdateHandler.php
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - How do I remove the "Powered by Conzent" branding?
  - How do I add a Reject All button?
  - Why is my Reject button missing?
  - How do I show the revisit consent button?
  - How do I let visitors reopen the banner?
  - How do I embed the cookie list on a page?
  - What is Respect Global Privacy Control?
  - How do I add a link to my cookie policy in the banner?
  - Can I add a close X to the banner?
---

# Banner Settings — Content Settings

## Where to find it

**Configuration → Banners** (`/banners`), the **Content Settings** section.

## What it does

Decides which elements appear on the banner and in the preference centre — which buttons, which
links, the branding, the cookie list, and the floating revisit button. It controls *whether*
elements show; the actual wording is set in **Banner Content & Translations**.

## Fields

### Cookie Notice (first layer)

| Field | What it does | Default | Notes |
|---|---|---|---|
| **Accept All Button** | Shows the accept-everything button | On | GDPR banner types only |
| **Reject All Button** | Shows the reject-everything button | On | GDPR requires Accept and Reject to be equally prominent — leaving this off is a common compliance failure |
| **Customize Button** | Opens the preference centre | On | GDPR requires a way to make granular choices |
| **Cookie Policy Link** | Links to your cookie policy from the banner | On | Uses the Privacy Policy URL on the site, or the generated cookie policy |
| **Remove "Powered by Conzent" Branding** | Hides the Conzent attribution | Off | **Business plan** on Cloud; shown with a "Business Plan" tag if unavailable. Optional and ungated on self-hosted |

A close (×) button is not offered — it is deliberately disabled, because under GDPR dismissing a
banner with an × does not count as a valid choice.

### Preference Center

| Field | What it does | Notes |
|---|---|---|
| **Show Google Privacy Policy** | Adds a link to Google's privacy policy in the preference centre | GDPR banner types. Some Google programmes expect this disclosure |

### Opt-out Center

| Field | What it does | Notes |
|---|---|---|
| **Respect "Global Privacy Control"** | Honours the GPC browser signal as an automatic opt-out | CCPA / GDPR+CCPA types. Required by several US state laws — check the GPC badge on your framework cards |

### Cookie List

| Field | What it does | Notes |
|---|---|---|
| **Show cookie list on banner** | Renders the detected cookie table inside the banner | Makes the banner tall; usually better on a policy page |
| **Embed Code** | Read-only snippet with a copy button: `<div class="cnz-cookie-policy"></div>` | Paste into any page to render the cookie table there |

### Revisit Consent Button

| Field | What it does | Default |
|---|---|---|
| **Show floating revisit button** | A small persistent button that reopens the banner after a choice | Off |
| **Button Position** | Left or Right | Right |

GDPR requires withdrawing consent to be as easy as giving it. The revisit button is the usual way
to satisfy that; if you leave it off, provide another route — for example the
`revisitCnzConsent()` link used by the Do Not Sell snippet.

## How to

**Remove Conzent branding** — turn on **Remove "Powered by Conzent" Branding**. On Cloud this
needs the Business plan; the toggle is replaced by a "Business Plan" tag otherwise. Self-hosted
installs can switch it off freely.

**Give visitors a way back to the banner** — turn on **Show floating revisit button** and pick a
side. Alternatively place a link that calls `revisitCnzConsent()` anywhere on your site.

**Publish your cookie list on a page** — copy the Embed Code and paste
`<div class="cnz-cookie-policy"></div>` into your cookie policy page. The script fills it in
with the current, categorised cookie table.

**Add a Do Not Sell link** — that snippet lives in **General Settings**, not here. See
Knowledgebase: Banner - Document: banner-general.md.

## Common questions

**My Reject All button disappeared.**
Either the toggle is off, or the site's banner type is not a GDPR type — Accept/Reject/Customize
only render on GDPR and GDPR+CCPA. Check **Compliance → Privacy Frameworks**.

**Do I have to show a Reject button?**
Under GDPR and ePrivacy, effectively yes. Regulators treat an accept-only banner, or one where
rejecting takes more clicks than accepting, as invalid consent.

**Where do I change the button text?**
**Banner Content & Translations**, further down the same page. This section only decides which
buttons exist. See Knowledgebase: Banner - Document: banner-translations.md.

**Can I change the wording of "Powered by Conzent"?**
No — it is either shown or removed.

**The embed code shows nothing on my page.**
The Conzent script must be loaded on that page, and the site needs at least one scanned or
manually added cookie. Run a scan under **Compliance → Scans**.

**What does Respect GPC actually do?**
When a visitor's browser sends the Global Privacy Control signal, Conzent applies an opt-out for
frameworks that require it without showing anything. It is a legal requirement under several US
state laws, marked with a `GPC` badge on the framework card.

## Related

- Knowledgebase: Banner - Document: banner-general.md — Do Not Sell code, TCF, consent expiry
- Knowledgebase: Banner - Document: banner-translations.md — the actual wording
- Knowledgebase: Compliance - Document: policies-overview.md — cookie policy embeds
- Knowledgebase: Sites - Document: frameworks.md — which laws require GPC and DNS
