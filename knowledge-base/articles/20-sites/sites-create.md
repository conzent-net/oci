---
id: sites.create
title: Add a site — the three-step wizard
area: Sites
knowledgebase: Sites
url: /sites/create
menu_path: General > Sites > Add Site
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [add-site, create-site, new-website, wizard, domain, languages, frameworks, onboarding]
related: [sites.list, sites.frameworks, sites.languages, sites.install-script, cookies.scans]
source_files:
  - templates/pages/sites/create.html.twig
  - templates/pages/sites/index.html.twig
  - src/Site/Controller/CreateSiteHandler.php
  - src/Site/Controller/CreateSitePageHandler.php
questions:
  - How do I add a new website?
  - What should I enter as the domain?
  - Do I need www in the domain?
  - Can I add a subdomain as a separate site?
  - Which privacy frameworks should I choose?
  - What happens after I create a site?
  - Do I have to pick languages when adding a site?
  - Domain already exists error
---

# Add a site — the three-step wizard

## Where to find it

Two entry points, same wizard:

- **General → Sites → Add Site** — opens it as a modal.
- `/sites/create` — the standalone page, where first-time users land after account setup.

## What it does

Registers a website with Conzent, generates its Website Key, applies your language and framework
choices, and kicks off an initial cookie scan.

## Step 1 — Website

| Field | Required | What it does | Notes |
|---|---|---|---|
| Domain | Yes | The domain the banner will run on | Enter the bare domain: `example.com`. No `https://`, no trailing slash, no path. Must be unique across Conzent |
| Site Name | No | A friendly label in the sites list and site selector | Defaults to the domain |
| Privacy Policy URL | No | Linked from the banner and preference centre | Full URL, e.g. `https://example.com/privacy` |

## Step 2 — Languages

Checkboxes for every language Conzent supports. **The first language you select becomes the
default.** Leave everything unticked and English is used.

Each selected language gets its own copy of the banner text, editable later in
**Banners → Banner Content & Translations**. On Cloud, the entry plan allows 2 languages.

## Step 3 — Privacy Frameworks

Two large cards for the common cases, plus a **More frameworks** disclosure grouped by region.

| Card | Covers | Consent model |
|---|---|---|
| **GDPR** | EU & EEA | opt-in — selecting it also enables ePrivacy Directive |
| **US Privacy** | CCPA / CPRA | opt-out |

GDPR and ePrivacy are pre-selected by default. **More frameworks** reveals the rest — LGPD,
POPIA, PIPEDA, the US state laws and others — each tagged with its consent model (opt-in,
opt-out or notice-only). The counter next to the toggle shows how many extras you have picked.

The banner adjusts blocking, buttons and signals per visitor location based on what you select
here. Full catalogue: Knowledgebase: Sites - Document: frameworks.md.

On Cloud, the entry plan allows 2 frameworks; exceeding it shows a plan error on this step.

## What happens on create

1. The site is created and a **Website Key** is generated.
2. An initial cookie scan is queued automatically.
3. You return to the sites list (or the dashboard, from the standalone page).

Next: apply a template and install the script — see
Knowledgebase: Account - Document: dashboard-customer.md and
Knowledgebase: Sites - Document: install-script.md.

## Common questions

**Should I include `www`?**
No. Enter `example.com`. The banner serves on the domain and its subdomains; a separate `www`
site is not needed.

**Can I add a subdomain as its own site?**
Yes, if you want separate configuration and separate consent records — for example
`shop.example.com` with a different banner. If you instead want one consent decision to carry
across related domains, use associated domains rather than separate sites.

**"Domain already exists"**
The domain is registered — either elsewhere in your account (check the Deleted tab on `/sites`)
or on another account. Permanently remove a deleted site to release the domain, or contact
support if it belongs to someone else.

**I picked the wrong frameworks.**
Change them any time from **Compliance → Privacy Frameworks**, or in the Edit Site modal on
`/sites`.

**Do I have to choose languages now?**
No. Skip it and English is used; add languages later under **Configuration → Languages**.

**When does the first scan finish?**
Usually within minutes, depending on site size and queue depth. Watch it under
**Compliance → Scans**.

**Nothing happened when I clicked Create Site.**
Check step 1 for a domain error and step 3 for a plan-limit error — the wizard jumps you back to
whichever step failed and shows the message inline.

## Related

- Knowledgebase: Sites - Document: sites-list.md — managing sites afterwards
- Knowledgebase: Sites - Document: frameworks.md — the full framework catalogue
- Knowledgebase: Sites - Document: languages.md — adding languages later
- Knowledgebase: Sites - Document: install-script.md — going live
