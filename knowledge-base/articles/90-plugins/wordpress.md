---
id: plugins.wordpress
title: WordPress plugin
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (WordPress admin) GetConzent CMP > Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [wordpress, wp, plugin, cookie-banner, shortcode, consent-api, site-kit, install, website-key]
related: [plugins.overview, sites.install-script, banner.content, policies.overview]
source_files:
  - plugins/getconzent_wp/README.md
  - plugins/getconzent_wp/readme.txt
  - plugins/getconzent_wp/conzent.php
questions:
  - How do I install Conzent on WordPress?
  - Where do I enter my website key in WordPress?
  - Does Conzent work with WordPress Consent API?
  - How do I show the cookie list on a WordPress page?
  - Does the WordPress plugin work with self-hosted Conzent?
  - Is the plugin compatible with Google Site Kit?
  - The banner is not showing on my WordPress site
---

# WordPress plugin

## Where to find it

**WordPress admin → GetConzent CMP → Settings.** Install from the WordPress plugin directory by
searching for "Conzent Cookie Banner".

## What it does

Injects the Conzent consent script into every page of your WordPress site and verifies your
Website Key on save. All banner configuration stays in the Conzent app — the plugin only connects
the two.

## Settings fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent, the Website Key column |
| **Server URL** | No | Leave empty for Conzent Cloud. Set it to your own installation for self-hosted OCI, e.g. `https://consent.example.com` |

On save the plugin verifies the key and reports one of:

- *Settings saved and website verified.*
- *Settings saved. Could not verify website key — the banner will still load.*
- *Settings saved.*

The middle message means the browser-side script will work; only the server-to-server
verification call failed, usually because your host blocks outbound requests.

## Requirements

| | |
|---|---|
| WordPress | 5.0.0 or later, tested to 7.0.3 |
| PHP | 5.6 or later |
| Licence | GPLv3 |

## Installing

**From the directory** — Plugins → Add New Plugin → search "Conzent Cookie Banner" → Install Now →
Activate → **GetConzent CMP → Settings** → paste the Website Key → Save.

**Manually** — download the ZIP, Plugins → Add New Plugin → Upload Plugin → Install Now →
Activate, or upload the extracted folder to `/wp-content/plugins/` by FTP.

## What it integrates with

| Integration | Notes |
|---|---|
| **WordPress Consent API** | Supported since plugin 1.0.11. Other Consent-API-aware plugins read Conzent's consent state and behave accordingly |
| **Google Site Kit** | Supported since 2.0.9 |
| Cookie only | The plugin sets one cookie, `conzentConsent`, holding the visitor's own preferences. No personal data |

## Showing the cookie list on a page

When the site is connected to the Conzent app, use the **HTML embed** rather than the plugin's
legacy shortcode:

```html
<div class="cnz-cookie-policy"></div>
```

Paste it into a page or a Custom HTML block. Get it from
**Banners → Content Settings → Cookie List → Embed Code** or from
**Compliance → Policies → Embed Codes**.

## Data sent to Conzent

Your domain name and the Website Key you enter. No visitor personal data is sent by the plugin
itself; consent records are written by the script, as described in
Knowledgebase: Consent - Document: consent-logs.md.

## Common questions

**The banner is not showing.**
Check, in order: the Website Key matches the one on `/sites`; the site is Active, not Disabled or
Suspended; a caching plugin or CDN is not serving stale HTML (purge it); geo targeting in
**Banners → General Settings** is not excluding you; and you have not already consented in that
browser — try a private window.

**"Could not verify website key" but the banner works.**
Expected on hosts that block outbound HTTP. The script loads in the visitor's browser regardless.

**Does it conflict with other cookie plugins?**
Yes, if another consent plugin is active. Two CMPs will both try to block and both show a banner.
Deactivate the other one.

**Does it work with WP Rocket / W3 Total Cache / Cloudflare?**
Yes, but purge the cache after changing settings in the Conzent app — cached HTML can hold the old
script reference. Also use **Banners → Advanced Settings → Purge & Regenerate**.

**Which languages does the plugin interface support?**
The plugin ships with translation files; banner text is translated in the Conzent app under
**Banners → Banner Content & Translations**.

**Where do I change colours and text?**
In the Conzent app, not WordPress. The plugin has only the two fields above.

**Does installing this make my site GDPR compliant?**
No — as the plugin's own readme states. Every site uses different cookies; you still need to
scan, classify and configure the banner appropriately.

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
- Knowledgebase: Banner - Document: banner-content.md — the cookie list embed code
- Knowledgebase: Compliance - Document: policies-overview.md — publishing policies
