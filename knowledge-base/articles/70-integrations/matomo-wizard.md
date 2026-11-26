---
id: integrations.matomo
title: Matomo Tag Manager Wizard
area: Integrations
knowledgebase: Integrations
url: /matomo/wizard
menu_path: Configuration > Matomo TM Wizard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [matomo, matomo-tag-manager, wizard, api-token, container, pixels, self-hosted-analytics, privacy-analytics]
related: [integrations.gtm, plugins.matomo, sites.install-script]
source_files:
  - templates/pages/matomo/wizard.html.twig
  - src/Site/Controller/MatomoWizardHandler.php
  - src/Site/Controller/MatomoValidateHandler.php
  - src/Site/Controller/MatomoContainersHandler.php
  - src/Site/Controller/MatomoWizardApplyHandler.php
questions:
  - How do I connect Conzent to Matomo Tag Manager?
  - Where do I get a Matomo API token?
  - Can I use Conzent with self-hosted Matomo?
  - What pixels can the Matomo wizard set up?
  - Matomo validation failed
  - Can I add a custom tag through the Matomo wizard?
---

# Matomo Tag Manager Wizard

## Where to find it

**Configuration → Matomo TM Wizard** in the sidebar. URL: `/matomo/wizard`.

## What it does

The Matomo counterpart to the GTM wizard. Connects to your Matomo instance with an API token,
lets you pick a site and Tag Manager container, then creates consent-aware tags in it for the
pixels you supply — including a free-form custom tag.

Works with Matomo Cloud and self-hosted Matomo alike.

## Step 1 — Connect

| Field | What it does | Notes |
|---|---|---|
| **Matomo Server URL** | Your Matomo instance | e.g. `https://analytics.example.com`. Matomo Cloud users use their `*.matomo.cloud` URL |
| **API Token** | Authenticates the connection | Matomo → **Administration → Personal → Security → Auth tokens** → create one |
| **Matomo Site** | Which Matomo site to target | Populated after validation; shows name and main URL |
| **Tag Manager Container** | Which MTM container to write into | Populated after picking the site |

Validation runs against the URL and token before the site list appears — a failure here means one
of those two is wrong, or Matomo is not reachable from your Conzent server.

## Step 2 — Pixels to migrate

Snippet fields want the whole `<script>` block from the vendor.

| Field | Format |
|---|---|
| Google Analytics | Full snippet |
| Facebook Pixel | Full snippet |
| Microsoft Clarity | Full snippet |
| LinkedIn Insight Tag | Full snippet |
| Snapchat Pixel | Full snippet |
| TikTok Pixel | Full snippet |
| **Custom Tag Name** + **Custom Tag Code** | A name and any custom HTML or `<script>` you want added as a consent-aware tag |

The custom tag pair is the notable difference from the GTM wizard — it lets you push an arbitrary
script into the container with consent conditions already attached.

## Step 3 — Apply

Conzent creates the tags in the selected container with consent conditions matching each one's
purpose. Review and publish in Matomo Tag Manager itself.

## The Conzent script is still required

As with GTM, the tag manager does not replace the Conzent loader. Keep the Conzent tag from
the dashboard's Install Script step first in `<head>`.

## Common questions

**Where exactly is the API token in Matomo?**
**Administration (cog) → Personal → Security → Auth tokens → Create new token.** Give it a
descriptive name. The token inherits your permissions, so use an account with Tag Manager access
to the target site.

**Validation failed.**
Check: the URL includes the scheme and no trailing path (`https://analytics.example.com`, not
`…/index.php`); the token was copied whole; Matomo is reachable from the Conzent server — a
Matomo on a private network is not reachable from Conzent Cloud, in which case use a self-hosted
Conzent, or configure the Matomo tag by hand.

**No containers listed.**
The Tag Manager plugin must be active on that Matomo site. It ships with Matomo 3.7+ but can be
disabled. Also check your account has Tag Manager access to that site.

**Can I use this with Matomo Cloud?**
Yes. Use your `*.matomo.cloud` URL and a token from the same account.

**Is there a native Conzent tag type in Matomo?**
Yes — a separate Matomo plugin adds **Conzent CMP** as a tag type you configure with just a
Website Key, no wizard needed. See Knowledgebase: Plugins - Document: matomo.md.

**Does Conzent send consent signals to Matomo?**
Matomo tags created by the wizard are gated on analytics consent, so they only fire once the
visitor agrees. There is no separate Matomo consent-signal switch the way there is for Google,
Meta and Microsoft.

## Related

- Knowledgebase: Integrations - Document: gtm-wizard.md — the Google Tag Manager equivalent
- Knowledgebase: Plugins - Document: matomo.md — the native Matomo CMP tag type
- Knowledgebase: Sites - Document: install-script.md — the required loader
