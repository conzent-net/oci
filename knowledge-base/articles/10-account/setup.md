---
id: account.setup
title: First-run account setup
area: Account
knowledgebase: Account
url: /account/setup
menu_path: (not in menu) — shown once after registration
edition: [cloud, self-hosted]
audience: [customer, agency]
plan: any
tags: [onboarding, setup, first-run, company, getting-started, import]
related: [account.profile, sites.create, dashboard.customer]
source_files:
  - templates/pages/account/setup.html.twig
  - src/Identity/Controller/AccountSetupHandler.php
  - config/routes.php
questions:
  - What is the Complete your account screen?
  - Why is Conzent asking for my company details?
  - Can I skip account setup?
  - How do I change the company details I entered at signup?
  - What is "Import from existing account"?
  - What happens after I finish setup?
---

# First-run account setup

## Where to find it

`/account/setup`. Shown automatically the first time you sign in after registering. Afterwards
the same information lives on **Account → Profile**.

## What it does

Collects your name and company details once, so they can be used to pre-fill the privacy policy
wizard, to appear on invoices, and to identify your organisation as the data controller in
generated policies. It is a single form headed **Complete your account**.

## Fields

| Field | Required | What it does |
|---|---|---|
| First Name | Yes | Your name in the app and on emails |
| Last Name | Yes | — |
| Company Name | Yes | Used as the controller name in generated privacy policies, and on invoices |
| Address | No | Street address. Pre-fills the privacy policy wizard |
| City | No | — |
| ZIP / Postal Code | No | — |
| State / Region | No | — |
| Country Code | No | Two-letter code, e.g. `DK`. Max 10 characters. Affects VAT handling on Cloud |
| VAT Number | No | e.g. `DK12345678`. Shown on invoices; may zero-rate VAT for EU businesses |
| Phone | No | Contact number, also offered as a contact point in generated policies |

## Import from existing account

If you are moving from the older Conzent product and your email matches a legacy account, a
modal offers to import it. It brings across:

- Company information (name, address, VAT)
- Your website domains

It does **not** bring across banner design, colours or content — those start from defaults.
Domains that already exist in your new account are skipped. On Cloud, imported sites stay
suspended until you subscribe.

The same import is available later from **Account → Profile** if you skip it here.

## How to complete setup

1. Fill in first name, last name and company name — the three required fields.
2. Add address and VAT details if you want them on invoices and in generated policies.
3. Save. You land on the **Add New Site** wizard at `/sites/create`.
4. Add your first site, then follow the four steps on the dashboard: apply a template,
   customise the banner, install the script, verify.

## Common questions

**Can I skip this?**
The three required fields must be filled to continue. Everything else can be left blank and
added later on the profile page.

**Why does Conzent need my company name?**
Generated privacy and cookie policies name your organisation as the data controller, and Cloud
invoices need a billing entity. It is not shared with anyone.

**I entered the wrong company details.**
Fix them on **Account → Profile** — same fields, editable any time.

**I am self-hosting. Do I still need to fill this in?**
The required fields still apply, but nothing is billed and the details only feed the policy
generator.

**What happens right after setup?**
You are taken to the site creation wizard. If you already have sites (for example from a legacy
import) you land on the dashboard instead.

## Related

- Knowledgebase: Account - Document: profile.md — editing the same details later
- Knowledgebase: Sites - Document: sites-create.md — the next step
- Knowledgebase: Account - Document: dashboard-customer.md — the four-step setup wizard
