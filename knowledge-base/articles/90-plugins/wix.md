---
id: plugins.wix
title: Wix app
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Wix dashboard) Conzent CMP > Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [wix, app, site-extension, install, website-key, gtm, dashboard-page, oauth]
related: [plugins.overview, sites.install-script, integrations.gtm]
source_files:
  - plugins/getconzent_wix/README.md
  - plugins/getconzent_wix/conzent_wix.js
  - plugins/getconzent_wix/.env.example
questions:
  - How do I add Conzent to my Wix site?
  - Where do I enter my website key in Wix?
  - Does the Wix app support Google Tag Manager?
  - Can I use the Wix app with self-hosted Conzent?
  - The banner is not showing on my Wix site
  - How do I self-host the Conzent Wix app?
---

# Wix app

## Where to find it

Install the Conzent app from the Wix App Market, then open **Conzent CMP** in your Wix dashboard.
Its settings page is where the Website Key goes.

## What it does

Injects the Conzent consent banner into every page of a Wix site via a Wix **Site Extension**,
and provides a dashboard settings page for configuration. It can optionally inject a Google Tag
Manager container alongside the banner.

## Settings

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent |
| **Server URL** | No | Empty for Conzent Cloud; your own domain for self-hosted OCI |
| **GTM Container ID** | No | Injects your GTM container with consent-aware loading |

## Features

- GDPR, CCPA and ePrivacy compliant banner
- IAB TCF certified and Google CMP Partner
- Google Consent Mode v2 integration
- Optional GTM container injection
- Automatic cookie blocking before consent
- 30+ languages out of the box
- Works with Conzent Cloud and self-hosted OCI

## Installing

1. Add the Conzent app to your site from the Wix App Market.
2. Open **Conzent CMP** in the Wix dashboard.
3. Paste your **Website Key** from `/sites` in Conzent.
4. Save.
5. Publish your Wix site — Wix changes are not live until published.
6. Open the published site in a private window and confirm the banner appears.

## Self-hosting the app itself

The Wix integration is a small Node service, not just a client-side snippet, because Wix apps
need an OAuth install endpoint, an uninstall webhook, a dashboard page and a site-extension
script. Agencies and self-hosters can run their own instance.

**Requirements**

| | |
|---|---|
| Node.js | 18+ (LTS recommended) |
| Accounts | A Wix Developer account and a Conzent account or OCI instance |

**Wix Developer Center setup**

| Setting | Value |
|---|---|
| OAuth redirect URL | `https://your-server.com/wix/install` |
| Uninstall webhook | `https://your-server.com/wix/uninstall` |
| Dashboard Page component | `https://your-server.com/wix/settings` |
| Site Extension component | `https://your-server.com/wix/site-extension.js` |

**Environment** — copy `.env.example` to `.env` and set `WIX_APP_ID`, `WIX_APP_SECRET` and
`BASE_URL`. Note the App ID and Secret from the Wix app dashboard.

**Run** — `npm ci --omit=dev`, ensure `data/` exists and is writable, then `node conzent_wix.js`
(port 3000 by default) under PM2, systemd or Docker. Local development needs an HTTPS tunnel such
as ngrok, since Wix requires HTTPS URLs.

## Common questions

**The banner is not showing on my Wix site.**
Publish the site — Wix keeps dashboard changes in draft until you publish. Then check the Website
Key matches `/sites`, the site is Active in Conzent, and geo targeting is not excluding you. Test
in a private window.

**Can I edit the banner design in Wix?**
No. Layout, colours, text and categories are all configured in the Conzent app. The Wix app only
carries the connection settings.

**Does the app work with Wix Studio and Wix Editor?**
It installs as a standard Wix app and injects through the Site Extension mechanism, which applies
across Wix site types.

**Can I use my own OCI instead of Conzent Cloud?**
Yes — set **Server URL** to your installation. If you also want to run the app service yourself,
follow the self-hosting section above.

**Wix already has a cookie banner. Do I need Conzent?**
Wix's built-in notice is a notification, not a consent manager — it does not block trackers
before consent, log consent for audit, or emit Consent Mode and TCF signals. Deactivate it to
avoid two banners.

**Where do I get support for the app?**
The Conzent app in the Wix dashboard links back to Conzent support. Licence is GPL-3.0-or-later.

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the underlying script
- Knowledgebase: Integrations - Document: gtm-wizard.md — Tag Manager setup
