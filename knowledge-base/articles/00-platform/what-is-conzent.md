---
id: platform.overview
title: What Conzent is and how the pieces fit together
area: Platform
knowledgebase: Platform
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [overview, cmp, getting-started, consent, gdpr, ccpa, what-is]
related: [platform.editions, platform.navigation, sites.install-script, dashboard.customer]
source_files:
  - README.md
  - config/routes.php
  - templates/components/sidebar.html.twig
questions:
  - What is Conzent?
  - What does Conzent actually do for my website?
  - How does the consent banner get onto my site?
  - What is a CMP?
  - Is Conzent GDPR compliant?
  - Do I need Conzent if I already have Google Consent Mode?
  - What is the difference between Conzent and the Conzent script?
---

# What Conzent is and how the pieces fit together

## Where to find it

The app dashboard at `/` is the home screen. Everything else hangs off the left sidebar.

## What it does

Conzent is a Consent Management Platform (CMP). It shows a consent banner to your website
visitors, blocks tracking cookies and scripts until they agree, records what each visitor
chose, and passes those choices on to Google, Meta, Microsoft and IAB ad partners in the
formats they expect.

You configure everything in this web app. Your website loads one small script that reads that
configuration and does the work in the visitor's browser.

## The four moving parts

| Part | What it is | Where it lives |
|---|---|---|
| **The app** | Where you configure sites, banners, cookies and policies, and read consent logs | Conzent Cloud, or your own server |
| **The script** | `consent.js`, loaded by your website with your Website Key | Your website's `<head>` |
| **The scanner** | Crawls your site to find cookies and trackers | Runs alongside the app |
| **The beacon** | Reports cookies real visitors trigger, back into your cookie list | Inside the script |

## How a visit works

1. A visitor opens your page. The script loads before anything else and **blocks** non-essential
   cookies, scripts and iframes.
2. The script checks the visitor's country against the privacy frameworks you enabled for that
   site, and picks the right behaviour — opt-in for GDPR, opt-out for US privacy laws.
3. The banner appears. The visitor accepts, rejects, or opens the preference centre and picks
   categories.
4. The script unblocks whatever they consented to, writes the `conzentConsent` cookie, and
   fires the consent signals (Google Consent Mode v2, IAB TC string, Meta, Microsoft, Amazon).
5. The choice is logged back to the app, where it appears under **Consent Logs**.

## The setup path

Four steps, all reachable from the **Quick Configuration** card on the dashboard:

1. **Apply a template** — picks sensible defaults (GCM Basic, GCM Advanced, and the TCF
   variants). See Knowledgebase: Account - Document: dashboard-customer.md.
2. **Customise the banner** — layout, text, colours, translations. See
   Knowledgebase: Banner - Document: banner-general.md.
3. **Install the script** — copy one `<script>` tag into your site's `<head>`, or use a CMS
   plugin. See Knowledgebase: Sites - Document: install-script.md.
4. **Verify** — run the built-in checks that confirm the script is live and the signals fire.

## Common questions

**Does installing Conzent make my site GDPR compliant?**
It gives you the technical half: blocking before consent, a lawful banner, an audit trail, and
the right signals. Compliance also depends on what data you collect and what you say in your
policies. Conzent generates cookie and privacy policies to help — see
Knowledgebase: Compliance - Document: policies-overview.md — but it is not legal advice.

**Do I need Conzent if I already use Google Consent Mode?**
Yes. Consent Mode is a signalling protocol — it needs a CMP to tell it what the visitor chose.
Conzent is that CMP, and it sends Consent Mode v2 signals natively.

**Where does my data live?**
On Conzent Cloud, in the EU. On a self-hosted install, wherever you run it — nothing leaves
your infrastructure. See Knowledgebase: Platform - Document: editions.md.

**Can I manage more than one website?**
Yes. Every page in the app is scoped to one site, and you switch between them with the site
selector in the page header. See Knowledgebase: Platform - Document: site-context.md.

**What cookie does Conzent itself set?**
One: `conzentConsent`, which stores the visitor's own preferences. It holds no personal data.

## Related

- Knowledgebase: Platform - Document: editions.md — Cloud vs self-hosted
- Knowledgebase: Platform - Document: navigation.md — the full menu map
- Knowledgebase: Sites - Document: install-script.md — getting the script onto your site
- Knowledgebase: Platform - Document: glossary.md — terminology
