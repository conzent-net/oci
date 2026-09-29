---
id: plugins.typo3
title: TYPO3 extension
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (TYPO3 backend) Admin Tools > Settings > Extension Configuration > conzent
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [typo3, extension, composer, ter, install, website-key, server-url, cms, middleware]
related: [plugins.overview, sites.install-script]
source_files:
  - plugins/getconzent_typo3/README.md
  - plugins/getconzent_typo3/ext_emconf.php
  - plugins/getconzent_typo3/ext_localconf.php
  - plugins/getconzent_typo3/Classes/Middleware/
questions:
  - How do I install Conzent on TYPO3?
  - Where do I configure Conzent in TYPO3?
  - Which TYPO3 versions are supported?
  - Can I install the TYPO3 extension via Composer?
  - The banner is not showing on my TYPO3 site
---

# TYPO3 extension

## Where to find it

**TYPO3 backend → Admin Tools → Settings → Extension Configuration → conzent.**

## What it does

Injects the Conzent banner script into every TYPO3 frontend page automatically, through a
middleware. No template changes needed.

## Settings fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent |
| **Server URL** | No | Empty for Conzent Cloud. Set it to your self-hosted OCI server, e.g. `https://consent.example.com` |

## Requirements

| | |
|---|---|
| TYPO3 | 12 LTS or 13 |
| PHP | 8.1 or later |
| Account | A Conzent account (free tier available) or a self-hosted OCI instance |
| Licence | GPL-3.0-or-later |

## Installing

**Composer (recommended)**

```bash
composer require conzent/conzent-typo3
```

Then activate the extension in the TYPO3 Extension Manager.

**TER** — search for "conzent" in the Extension Manager and install directly.

**Then configure**

1. **Admin Tools → Settings → Extension Configuration → conzent**.
2. Paste the **Website Key**.
3. Optionally set the **Server URL** for a self-hosted install.
4. Save, then **clear all caches**.

Clearing caches is not optional — TYPO3 caches rendered pages aggressively and the script tag
will not appear until you do.

## Features

- GDPR, CCPA and ePrivacy compliance
- IAB TCF certified, Google CMP Partner
- Google Consent Mode v2
- Automatic cookie blocking before consent
- 30+ languages
- Self-hosted (OCI) and Conzent Cloud support

## Common questions

**The banner is not appearing.**
Clear all TYPO3 caches first — that is the usual cause. Then check the Website Key matches
`/sites`, the extension is activated, the site is Active in Conzent, and geo targeting is not
excluding you.

**Which TYPO3 versions?**
12 LTS and 13, on PHP 8.1+. Older TYPO3 needs the direct script method in a page template.

**Where in the page is the script injected?**
Through the extension's frontend middleware, into the page head — which is where it needs to be
so blocking happens before other scripts.

**Can I control which pages get the banner?**
Not from the extension. Conzent applies site-wide, with geo targeting as the only built-in
restriction.

**Does it work with TYPO3's own cookie/consent handling?**
Run one consent manager. If you have another consent extension active, disable it.

**Where do I configure the banner?**
In the Conzent app under **Configuration → Banners**. The extension holds only the connection
settings.

**Support**
Website: getconzent.com · Documentation: help.getconzent.com · Email: support@getconzent.com

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
