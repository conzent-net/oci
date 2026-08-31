---
id: plugins.joomla
title: Joomla plugin
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Joomla admin) Extensions > Plugins > Conzent CMP
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [joomla, plugin, install, website-key, server-url, cms, extension]
related: [plugins.overview, sites.install-script]
source_files:
  - plugins/getconzent_joomla/README.md
  - plugins/getconzent_joomla/conzent.xml
  - plugins/getconzent_joomla/conzent_joomla.php
questions:
  - How do I install Conzent on Joomla?
  - Where do I enter my website key in Joomla?
  - Which Joomla versions are supported?
  - The Joomla plugin is installed but nothing happens
  - Does the Joomla plugin work with self-hosted Conzent?
---

# Joomla plugin

## Where to find it

**Joomla admin → Extensions → Plugins**, search for "Conzent", open **Conzent CMP**.

## What it does

Adds the Conzent consent banner to every page of a Joomla site. One field to fill in: the
Website Key.

## Settings fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent |
| **Server URL** | No | Empty for Conzent Cloud (`https://app.getconzent.com`). Set to your self-hosted OCI URL, e.g. `https://your-domain.com/app` |

## Requirements

| | |
|---|---|
| Joomla | 4.x or 5.x |
| PHP | 8.1 or higher |
| Licence | GPL-3.0-or-later |

## Installing

1. Download the latest release ZIP.
2. Joomla admin → **System → Install → Extensions**.
3. Upload the ZIP on the **Upload Package File** tab.
4. Go to **System → Manage → Plugins**, search for "Conzent", and click the status icon to
   **enable** it — installing does not enable it.
5. **Extensions → Plugins → Conzent CMP**, paste the Website Key, **Save & Close**.

## Getting your Website Key

1. Sign in to Conzent.
2. **General → Sites**.
3. Copy the value in the **Website Key** column for the site.

## Features

- GDPR, CCPA and ePrivacy compliant banner
- IAB TCF certified, Google CMP Partner
- Google Consent Mode v2
- Automatic cookie blocking before consent
- 30+ languages
- Customisable design and position (configured in the Conzent app)
- Consent logging and audit trail
- Cookie scanning and categorisation
- Self-hosted (OCI) support

## Common questions

**I installed it but nothing happens.**
Joomla installs plugins disabled. Go to **System → Manage → Plugins**, find Conzent CMP and click
the status icon to enable it.

**The banner still does not show after enabling.**
Check the Website Key matches `/sites`, clear the Joomla cache (**System → Clear Cache**), confirm
the site is Active in Conzent, and test in a private window in case you already consented.

**Which Joomla versions?**
Joomla 4.x and 5.x on PHP 8.1+. Joomla 3 is not supported — use the direct script method.

**Can I use my own Conzent server?**
Yes. Set **Server URL** to your OCI installation. Leave it empty for Cloud.

**Where do I change the banner design?**
In the Conzent app under **Configuration → Banners**. The plugin has no design settings.

**Support**
Website: getconzent.com · Documentation: getconzent.com/docs · Email: support@getconzent.com

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
