---
id: plugins.drupal
title: Drupal module
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (Drupal admin) Configuration > System > Conzent CMP
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [drupal, module, composer, drush, install, website-key, server-url, cms]
related: [plugins.overview, sites.install-script]
source_files:
  - plugins/getconzent_drupal/README.md
  - plugins/getconzent_drupal/conzent_drupal.info.yml
  - plugins/getconzent_drupal/conzent_drupal.routing.yml
questions:
  - How do I install Conzent on Drupal?
  - Where do I configure Conzent in Drupal?
  - Which Drupal versions are supported?
  - Can I install the Drupal module with Composer?
  - Does the Drupal module work with self-hosted Conzent?
---

# Drupal module

## Where to find it

**Drupal admin → Configuration → System → Conzent CMP.**
Path: `/admin/config/system/conzent`.

## What it does

Adds the Conzent consent banner to every page of a Drupal site. Enter your Website Key and the
module verifies it and injects the script.

## Settings fields

| Field | Required | What it does |
|---|---|---|
| **Website Key** | Yes | From **General → Sites** in Conzent |
| **Server URL** | No | Empty for Conzent Cloud (`https://app.getconzent.com`). Set to your own OCI installation, e.g. `https://consent.yourdomain.com` |

Saving verifies the key automatically. When a Server URL is set, verification uses your
self-hosted API endpoint too.

## Requirements

| | |
|---|---|
| Drupal | 10 or 11 |
| PHP | 8.1 or higher |
| Licence | GPL-3.0-or-later |

## Installing

**Composer (recommended)**

```bash
composer require conzent/conzent-drupal
drush en conzent_drupal -y
```

**Manual**

1. Extract the module to `modules/custom/conzent_drupal/`.
2. Enable it at **Extend** (`/admin/modules`) or with `drush en conzent_drupal -y`.

Then go to **Configuration → System → Conzent CMP**, paste the Website Key and save. The banner
appears on all pages once a verified key is configured.

## Features

- GDPR, CCPA and ePrivacy compliance
- IAB TCF certified, Google CMP Partner
- Google Consent Mode v2
- Automatic cookie blocking before consent
- 30+ languages
- Geo-targeted banners
- Consent logging and audit trail
- Works with Conzent Cloud and self-hosted OCI

## Common questions

**Which Drupal versions?**
Drupal 10 and 11, on PHP 8.1+. Older Drupal needs the direct script method instead.

**The banner is not appearing.**
Confirm the module is enabled, a Website Key is saved and verified, and Drupal's page cache has
been cleared (`drush cr`). Also check the site is Active in Conzent and that geo targeting is not
excluding you.

**Can I restrict the banner to certain paths?**
Not from the module. Conzent applies the banner site-wide, with geo targeting as the only
built-in restriction.

**Does it work behind a reverse proxy or CDN?**
Yes. Purge the CDN after changing settings, and use **Banners → Advanced Settings → Purge &
Regenerate** in Conzent.

**Where do I configure the banner itself?**
In the Conzent app. The module holds only the connection settings.

**Does it conflict with Drupal's EU Cookie Compliance module?**
Yes — two consent managers will both try to block and both show a banner. Disable the other one.

## Related

- Knowledgebase: Plugins - Document: overview.md — all platforms
- Knowledgebase: Sites - Document: install-script.md — the manual alternative
