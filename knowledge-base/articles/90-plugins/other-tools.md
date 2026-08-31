---
id: plugins.other-tools
title: Companion WordPress tools — Compliance Audit and Whitepaper Gateway
area: Plugins
knowledgebase: Plugins
url: /sites
menu_path: (WordPress admin) — separate plugins, not part of the CMP
edition: [cloud, self-hosted]
audience: [agency, admin]
plan: any
tags: [audit-plugin, compliance-audit, whitepaper-gateway, lead-generation, marketing, agency-tools, wordpress]
related: [plugins.overview, plugins.wordpress, agency.customers]
source_files:
  - plugins/Conzent Audit Plugin/readme.txt
  - plugins/Conzent Audit Plugin/compliance-audit.php
  - plugins/whitepaper-consent-gateway/README.md
  - plugins/whitepaper-consent-gateway/readme.txt
questions:
  - What is the Conzent Audit Plugin?
  - How do I offer a free compliance scan on my website?
  - What is the Whitepaper Consent Gateway?
  - Are the audit and whitepaper plugins part of the CMP?
  - How do agencies generate leads with Conzent?
---

# Companion WordPress tools — Compliance Audit and Whitepaper Gateway

## Where to find it

Both are separate WordPress plugins installed on **your own marketing site**. Neither is part of
the CMP, and neither is needed to run a consent banner.

## What they do

Lead-generation tools for agencies and resellers who sell consent compliance. One scans a
prospect's website and shows them what is wrong; the other gates a whitepaper behind an optional
consent-compliant form.

> These are marketing tools. A customer asking "how do I set up my cookie banner" wants
> Knowledgebase: Plugins - Document: wordpress.md, not these.

## Conzent Compliance Audit Plugin

Puts a public compliance-audit form on your site. A visitor enters their domain; the plugin
scans it and returns a scored report on their current consent setup — a natural opening for a
sales conversation.

| Component | Purpose |
|---|---|
| `compliance-audit.php` | The WordPress plugin: form, results page and CTA templates |
| `scan-site.js` | Headless scan of the submitted domain |
| `score-risk.js` | Turns findings into a risk score |
| `server.js` + Docker | The scanning service the plugin calls |

Templates cover the form, the result page and the call to action. It ships with its own Docker
setup because the scanning runs outside WordPress.

## Whitepaper Consent Gateway

A dual-option landing page for email campaigns: download a whitepaper without giving consent, or
request a personalised website report — which does require consent. The consent-free path exists
deliberately, so the page itself is not a dark pattern.

### Features

| Feature | Detail |
|---|---|
| Dual-option landing page | Card layout with two choices |
| Whitepaper download | Direct PDF, no consent required |
| Website report requests | Lead capture with GDPR-compliant consent |
| Campaign tracking | Tokens, campaigns, sources and tags via URL parameters |
| Signed links | Optional HMAC-SHA256 signed URLs |
| Webhook integration | POSTs submissions to external systems, with retry |
| CSV export | Requests and events, filtered by date and status |
| Rate limiting | Configurable abuse protection |
| Admin dashboard | Manage requests, view events, configure settings |

### Requirements

WordPress 6.0+, PHP 8.1+, MySQL 5.7+ / MariaDB 10.3+.

### Setup

1. Upload to `/wp-content/plugins/` and activate.
2. **Whitepaper Gateway → Settings** — upload the PDF or point at an external URL, set the
   privacy policy URL, customise the consent text, configure email notification and webhook.
3. Add `[whitepaper_gateway]` to a page, or enable **Auto-Create Page** for a `/whitepaper/`
   landing page.

### Shortcode

```
[whitepaper_gateway
    pdf_id="123"
    pdf_url="https://cdn.example.com/whitepaper.pdf"
    privacy_url="https://yoursite.com/privacy"
    title="Download Our Guide"
    intro="Choose your preferred option below."]
```

| Attribute | Default |
|---|---|
| `pdf_id` | Settings value |
| `pdf_url` | Settings value |
| `privacy_url` | Settings value |
| `title` | "Whitepaper & Website Report" |
| `intro` | "Choose one of the options below to get started." |

### Campaign links

Pre-fill and track per recipient:

```
https://yoursite.com/whitepaper/?t=TOKEN123&d=example.com&e=user@example.com
```

| Parameter | Pre-fills |
|---|---|
| `d` | Domain |
| `e` | Email |
| `c` | Company |
| `t` | Campaign token |

## Common questions

**Do I need these to use Conzent?**
No. They are optional marketing tools, mainly for agencies. The CMP works without them.

**Can my clients use the audit plugin?**
It is aimed at whoever sells the service. An agency runs it on their own site to qualify
prospects.

**Are these in the open-source repository?**
They live under `plugins/` alongside the CMS integrations, separate from the CMP core.

**Does the whitepaper gateway need Conzent installed?**
No, it is standalone. It is designed to be consent-aware: the download path requires no consent,
so it does not force a choice to access the content.

## Related

- Knowledgebase: Plugins - Document: wordpress.md — the actual CMP plugin
- Knowledgebase: Plugins - Document: overview.md — all integrations
- Knowledgebase: Growth - Document: agency.md — managing the clients these tools win
