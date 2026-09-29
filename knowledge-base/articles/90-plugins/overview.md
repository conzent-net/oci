---
id: plugins.overview
title: Plugins and extensions — overview
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (external) — installed in your CMS, not in Conzent
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [plugins, cms, wordpress, wix, drupal, joomla, typo3, umbraco, matomo, extension, integration, install]
related: [sites.install-script, plugins.wordpress, plugins.wix, plugins.extension]
source_files:
  - plugins/
  - plugins/getconzent_wp/README.md
  - plugins/getconzent_drupal/README.md
  - plugins/getconzent_joomla/README.md
  - plugins/getconzent_typo3/README.md
  - plugins/getconzent_umbraco/README.md
  - plugins/getconzent_wix/README.md
  - plugins/conzent_matomo/README.md
questions:
  - Which CMS platforms does Conzent support?
  - Is there a Conzent plugin for my CMS?
  - Do I need a plugin or can I paste the script?
  - Does the plugin work with self-hosted Conzent?
  - Where do I get my website key for the plugin?
  - Is there a Shopify or Squarespace plugin?
---

# Plugins and extensions — overview

## Where to find it

Plugins are installed in your CMS, not in Conzent. What they all need from Conzent is the
**Website Key**, on `/sites` in the Website Key column.

## What they do

Each plugin injects the Conzent consent script into every page of your site, so you never touch
a template. You paste one value — the Website Key — and the plugin handles the rest.

Every plugin also accepts a **Server URL**, which is what makes them work with a self-hosted OCI
instance instead of Conzent Cloud.

## Available plugins

| Platform | Requirements | Configured at | Article |
|---|---|---|---|
| **WordPress** | WP 5.0+, PHP 5.6+, tested to WP 7.0.3 | GetConzent CMP → Settings | Knowledgebase: Plugins - Document: wordpress.md |
| **Wix** | Wix site + the Conzent Wix app | Wix dashboard → Conzent settings page | Knowledgebase: Plugins - Document: wix.md |
| **Drupal** | Drupal 10 or 11, PHP 8.1+ | `/admin/config/system/conzent` | Knowledgebase: Plugins - Document: drupal.md |
| **Joomla** | Joomla 4.x or 5.x, PHP 8.1+ | Extensions → Plugins → Conzent CMP | Knowledgebase: Plugins - Document: joomla.md |
| **TYPO3** | TYPO3 12 LTS or 13, PHP 8.1+ | Admin Tools → Settings → Extension Configuration | Knowledgebase: Plugins - Document: typo3.md |
| **Umbraco** | Umbraco CMS (.NET) | `appsettings.json` under `Conzent` | Knowledgebase: Plugins - Document: umbraco.md |
| **Matomo Tag Manager** | Matomo 4.0+ with Tag Manager | A native **Conzent CMP** tag type | Knowledgebase: Plugins - Document: matomo.md |

Plus the **Consent Mode Inspector** browser extension, a debugging tool rather than an
integration — see Knowledgebase: Plugins - Document: browser-extension.md.

## Plugin or plain script?

| | Plugin | Direct script |
|---|---|---|
| Effort | Paste a key | Paste one `<script>` tag into `<head>` |
| Placement | Handled for you | You must put it first, before every other script |
| Updates | Through the CMS | Nothing to update |
| Availability | Only for the platforms above | Anywhere |

The plugin is the safer route where one exists, because getting the tag first in `<head>` is
where manual installs go wrong. There is no functional difference otherwise — same script, same
configuration.

For Shopify, Squarespace, Webflow, Ghost, static sites and anything not listed, use the direct
script. See Knowledgebase: Sites - Document: install-script.md.

## Common shape

Every plugin exposes the same two settings:

| Setting | What it does |
|---|---|
| **Website Key** | From `/sites`. Ties the script to your site's configuration |
| **Server URL** | Leave empty for Conzent Cloud. Set it to your own domain for a self-hosted OCI install (e.g. `https://consent.example.com`) |

Most also verify the key on save and tell you whether verification succeeded.

## Common questions

**Where is my Website Key?**
**General → Sites**, the Website Key column. It is also embedded in the install snippet on the
dashboard's Install Script step.

**Do plugins work with self-hosted Conzent?**
Yes, all of them. Set the **Server URL** field to your own installation's URL. Empty means Cloud.

**Is one plugin per site or per key?**
One key per site. If you run several domains from one CMS install, each needs its own Conzent site
and its own key.

**Does the plugin do the blocking, or does Conzent?**
Conzent. The plugin only injects the script; all blocking, banner rendering and consent logging
happens in the script and is configured in the Conzent app.

**Do I still configure the banner in the Conzent app?**
Yes. Plugins carry no banner settings — layout, colours, text, categories and frameworks all live
in Conzent.

**Is there a Shopify plugin?**
Not currently. Use the direct script in your theme's `theme.liquid`, first in `<head>`, and add
the Shopify bridge tag (`/c/shopify.js`) right after it so consent is forwarded to Shopify's
Customer Privacy API. See Knowledgebase: Sites - Document: install-script.md.

**The plugin says my key could not be verified.**
The banner still loads. Verification needs your CMS server to reach the Conzent server — a
firewall or an outbound-request block will fail it while the browser-side script works fine.
Double-check the key is right, then test the site in a private window.

## Related

- Knowledgebase: Sites - Document: install-script.md — the manual method
- Knowledgebase: Sites - Document: sites-list.md — where the Website Key lives
- Knowledgebase: Plugins - Document: browser-extension.md — debugging what the script does
