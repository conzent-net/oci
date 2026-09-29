---
id: integrations.gtm
title: Google Tag Manager Wizard
area: Integrations
knowledgebase: Integrations
url: /gtm/wizard
menu_path: Configuration > GTM Wizard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [gtm, google-tag-manager, wizard, container, workspace, pixels, migration, tags, oauth]
related: [sites.install-script, banner.advanced, integrations.matomo, compliance.gcm]
source_files:
  - templates/pages/gtm/wizard.html.twig
  - src/Site/Controller/GtmWizardHandler.php
  - src/Site/Controller/GtmAuthHandler.php
  - src/Site/Controller/GtmAccountsHandler.php
  - src/Site/Controller/GtmWorkspacesHandler.php
  - src/Site/Controller/GtmCreateWorkspaceHandler.php
  - src/Site/Controller/GtmWizardApplyHandler.php
questions:
  - How do I move my tracking pixels into Google Tag Manager?
  - How do I connect Conzent to Google Tag Manager?
  - What is the GTM wizard?
  - Which pixels can the GTM wizard set up?
  - Google OAuth is not configured — what do I do?
  - What is a GTM workspace?
  - Do I still need the Conzent script if I use GTM?
---

# Google Tag Manager Wizard

## Where to find it

**Configuration → GTM Wizard** in the sidebar. URL: `/gtm/wizard`.

A lighter version of the same connection lives on the dashboard's **Install Script** step, where
it only picks a container for auto-injection.

## What it does

Connects your Google account, lets you pick a GTM account, container and workspace, and then
creates consent-aware tags in that container for the tracking pixels you supply. It is a
migration tool: instead of hand-writing pixels into GTM and wiring consent conditions yourself,
you paste each pixel and Conzent builds the tags with the right consent triggers.

## Step 1 — Connect and choose a destination

| Field | What it does |
|---|---|
| **GTM Account** | Which Google Tag Manager account to use. Populated after you sign in with Google |
| **Container** | The container within that account, shown as name and public ID (`GTM-XXXXXXX`) |
| **Workspace** | Which workspace to write into. You can also create a new one |

Working in a **new workspace** is the safe default: changes stay unpublished until you review and
publish them in GTM, so nothing reaches your live site by accident.

## Step 2 — Pixels to migrate

Fill in only the ones you use. ID fields want the identifier; snippet fields want the whole
`<script>` block copied from the vendor.

| Field | Format | Example |
|---|---|---|
| Google Analytics | Measurement ID | `G-XXXXXXXXXX` |
| Matomo Analytics | Full tracking snippet | Paste the whole `<script>` |
| Google Ads Conversion ID | ID | `AW-XXXXXXXXX` |
| Google Ads Conversion Label | Label | From the conversion action in Google Ads |
| Facebook Pixel | Full snippet | Paste the whole `<script>` |
| Microsoft Clarity | Full snippet | Paste the whole `<script>` |
| LinkedIn Insight Tag | Partner ID | `123456` |
| Pinterest Pixel | Tag ID | `2612345678901` |
| Snapchat Pixel | Full snippet | Paste the whole `<script>` |
| TikTok Pixel | Full snippet | Paste the whole `<script>` |

## Step 3 — Apply

Conzent writes the tags into the chosen workspace, each with the consent conditions matching its
purpose — analytics tags gated on analytics consent, advertising tags on marketing consent.

Then, in GTM itself: review the workspace changes, preview, and publish.

## Prerequisites

| Requirement | Notes |
|---|---|
| Google OAuth configured | Cloud has it. Self-hosted needs `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`, the Tag Manager API enabled in Google Cloud Console, and `{APP_URL}/gtm/callback` as an authorised redirect URI. Without it the page shows **Google OAuth Not Configured** |
| GTM edit permission | Your Google account needs publish or edit rights on the container |

## The Conzent script is still required

GTM does not replace the Conzent loader. GTM loads asynchronously, so it cannot reliably block
trackers before they run. Keep the Conzent tag from the Install Script step directly in
`<head>`; GTM handles your other tags, Conzent handles consent.

You can have Conzent inject GTM for you — set **GTM Container ID** in **Banners → Advanced
Settings**, and remove your own GTM snippet so it does not load twice.

## Common questions

**Does the wizard change my live site immediately?**
Not if you write into a new workspace — GTM changes are staged until you publish. Always review
in GTM before publishing.

**"Google OAuth Not Configured"**
Self-hosted install without Google credentials. Create an OAuth client in Google Cloud Console,
enable the Tag Manager API, add `{APP_URL}/gtm/callback` as a redirect URI, and set
`GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`.

**My GTM account list is empty.**
The signed-in Google account has no Tag Manager access, or you authorised a different Google
account than the one owning the container. Sign out of Google and reconnect.

**Can I re-run the wizard?**
Yes. Re-running creates tags again — use a fresh workspace and reconcile in GTM rather than
duplicating tags in the same one.

**What about a custom data layer?**
Set **GTM Data Layer Name** in **Banners → Advanced Settings**. The install snippet then carries
`data-dl` so Conzent and GTM agree on the name.

**Is there an equivalent for Matomo?**
Yes — **Configuration → Matomo TM Wizard**. See Knowledgebase: Integrations - Document: matomo-wizard.md.

## Related

- Knowledgebase: Sites - Document: install-script.md — the required loader
- Knowledgebase: Banner - Document: banner-advanced.md — GTM container ID and data layer
- Knowledgebase: Compliance - Document: google-consent-mode.md — the signals GTM tags read
- Knowledgebase: Integrations - Document: matomo-wizard.md — the Matomo equivalent
