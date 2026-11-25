---
id: plugins.matomo
title: Matomo Tag Manager plugin
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Matomo) Tag Manager > Create New Tag > Consent Management > Conzent CMP
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [matomo, tag-manager, mtm, plugin, tag-type, website-key, server-url, trigger, container]
related: [plugins.overview, integrations.matomo, sites.install-script]
source_files:
  - plugins/conzent_matomo/README.md
  - plugins/conzent_matomo/plugin.json
  - plugins/conzent_matomo/ConzentCmp.php
  - plugins/conzent_matomo/Template/
questions:
  - How do I add Conzent to Matomo Tag Manager?
  - Is there a native Conzent tag in Matomo?
  - Which trigger should I use for the Conzent tag?
  - Can I use the Matomo plugin with self-hosted Conzent?
  - What is the difference between the Matomo plugin and the Matomo wizard?
---

# Matomo Tag Manager plugin

## Where to find it

**Matomo → Tag Manager → your container → Create New Tag → Consent Management → Conzent CMP.**

## What it does

Adds **Conzent CMP** as a native tag type in Matomo Tag Manager. You configure it with a Website
Key — no Custom HTML tag, no hand-written snippet.

## Tag fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent |
| **Server URL** | No | Change to your installation URL for self-hosted Conzent OCI. Leave as default for Cloud |

## Requirements

| | |
|---|---|
| Matomo | 4.0 or later |
| Tag Manager | The Tag Manager plugin must be active (included by default since Matomo 3.7) |

## How to use it

1. Go to **Tag Manager** in Matomo.
2. Select your container.
3. **Create New Tag**.
4. Choose **Conzent CMP** from the **Consent Management** category.
5. Enter your **Website Key**, found in the Conzent dashboard under **Sites**.
6. Assign a trigger — **All Page Views**.
7. Save and **publish** the container.

Use **All Page Views** and nothing narrower. A consent manager that only fires on some pages
leaves the others unprotected.

## Self-hosted Conzent

Change the **Server URL** field to your OCI installation's URL. Everything else is identical.

## This plugin vs the Matomo TM Wizard

Two different things, and both can be used together:

| | This plugin | Knowledgebase: Integrations - Document: matomo-wizard.md |
|---|---|---|
| Installed in | Matomo | Nothing — it is a page in Conzent |
| What it does | Adds a Conzent CMP tag type you configure manually | Connects to your Matomo via API token and creates consent-aware tags for your other pixels |
| Use it to | Load the Conzent banner through MTM | Migrate Google Analytics, Meta, Clarity, LinkedIn, Snapchat, TikTok and custom tags into MTM with consent conditions |

## Common questions

**Do I still need the Conzent script in `<head>`?**
The tag manager loads asynchronously, so a CMP loaded only through MTM can be beaten to the punch
by other scripts. The most reliable setup is the Conzent loader directly in `<head>` — see
Knowledgebase: Sites - Document: install-script.md. Use this plugin when you must manage
everything through MTM, and verify blocking works with the browser extension.

**I cannot find Conzent CMP in the tag list.**
The plugin must be installed and activated in Matomo (**Administration → Plugins**), and Tag
Manager must be active. Refresh the tag list after installing.

**Nothing happens after saving the tag.**
Matomo Tag Manager changes are staged until you publish the container. Publish, then hard-refresh
your site.

**Does it work with Matomo Cloud?**
Yes, on Matomo 4.0+ with Tag Manager.

**Where do I configure the banner?**
The Conzent app. The tag carries only the Website Key and Server URL.

**Support**
Website: getconzent.com · Documentation: getconzent.com/docs ·
Issues: github.com/conzent-net/conzent-matomo-cmp/issues

## Related

- Knowledgebase: Integrations - Document: matomo-wizard.md — the wizard that migrates other pixels
- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the direct method
