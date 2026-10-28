---
id: platform.editions
title: Editions — Conzent Cloud vs self-hosted (OCI)
area: Platform
knowledgebase: Platform
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [edition, cloud, self-hosted, oci, licensing, open-source, cmp-id, differences]
related: [platform.overview, account.billing, selfhost.install, compliance.tcf]
source_files:
  - src/Shared/Service/EditionService.php
  - config/pricing.json
  - config/pricing.oci.json
  - .env.example
  - README.md
  - LICENSE.md
questions:
  - What is the difference between Conzent Cloud and self-hosted?
  - Is Conzent open source?
  - Do I have to pay to self-host Conzent?
  - Why is there no Billing page in my installation?
  - Why can't I turn on IAB TCF?
  - How many websites can I add on self-hosted?
  - What is OCI?
  - Which features are Cloud only?
---

# Editions — Conzent Cloud vs self-hosted (OCI)

## Where to find it

There is no edition switch in the UI. Which edition you are running is decided by whether the
server has a `CMP_ID` configured. The sidebar and the available pages change accordingly.

## What it does

Conzent ships in two editions from the same codebase. **Conzent Cloud** is the hosted service at
`app.getconzent.com`, billed per plan. **Conzent OCI** (Open Consent Infrastructure) is the
open-source core, Apache 2.0 licensed, that you run on your own infrastructure with no licence
fee and no limits.

The core CMP — banners, blocking, scanning, consent logging, policies, frameworks — is
identical in both. What differs is billing, plan enforcement, and a handful of commercial
modules.

## How the edition is decided

`EditionService` reads the `CMP_ID` environment variable:

| `CMP_ID` | Edition | Effect |
|---|---|---|
| Set to a valid IAB CMP ID | Cloud | Billing on, plan limits enforced, agency features on, IAB TCF available |
| Empty or `0` | Self-hosted | Billing hidden, unlimited domains, no agency module, TCF disabled |

`CMP_ID` is your own registration with IAB Europe. It is stamped into every TC string you issue,
so it cannot be borrowed from another CMP. A self-hoster who registers with IAB Europe and sets
their own `CMP_ID` gets full TCF support.

## Feature comparison

| Feature | Cloud | Self-hosted (OCI) |
|---|---|---|
| Consent banner, blocking, preference centre | Yes | Yes |
| Cookie scanning + beacon observations | Yes | Yes (scanner ships in the stack) |
| Consent logs, reports, audit trail | Yes | Yes |
| Cookie + privacy policy generators | Yes | Yes |
| Privacy frameworks (20+) | Limited by plan | Unlimited |
| Banner languages | Limited by plan | Unlimited |
| Custom layouts | Business plan | Unlimited |
| Remove "Powered by Conzent" branding | Business plan | Optional, no gate |
| Number of websites | Limited by plan | Unlimited |
| Monthly pageviews | Limited by plan | Unlimited |
| **Billing** (`/billing`) | Yes | Page not registered |
| **Agency / reseller** (`/agency`) | Yes | Not available |
| **A/B split tests** (`/ab-tests`) | Yes | Not available |
| **Revenue Impact** (`/impact`) | Yes | Not available |
| **IAB TCF v2.4** | Yes (CMP ID 401) | Only with your own registered CMP ID |
| Google Consent Mode v2 | Yes | Yes |
| Support | 24/7 support + documentation | Community (GitHub, Reddit) |
| Data location | EU servers | Wherever you host it |
| Price | From €7/site/month | Free |

## What a self-hosted user will not see

If a support answer mentions any of these, it does not apply to a self-hosted install:

- **Billing** in the Account menu, plan upgrades, invoices, Stripe.
- **Agency** menu section, customers, invites, commissions, "log in as customer".
- **A/B Tests** under Configuration.
- **Revenue Impact** under General.
- Any "upgrade your plan" prompt — plan limits are not enforced.

## Plan limits (Cloud only)

| Limit | Small Businesses | Agencies and E-commerce |
|---|---|---|
| Pageviews / month | 100,000 | Unlimited |
| Banner languages | 2 | Unlimited |
| Banner layouts | 2 | Unlimited |
| Privacy frameworks | 2 | Unlimited |
| Custom layouts | No | Unlimited |
| Remove branding | No | Yes |

Both plans are priced per site and get cheaper per site as the count rises. A 14-day trial
applies. See Knowledgebase: Account - Document: billing.md.

## Common questions

**Is Conzent open source?**
The core is, under Apache 2.0. That covers everything in the table above marked "Yes" for
self-hosted. The commercial modules (billing, agency, A/B testing, revenue impact) are
proprietary and are not in the public repository.

**Does self-hosting cost anything?**
No licence fee. You pay for whatever server you run it on.

**Why can't I enable IAB TCF on my self-hosted install?**
TCF requires an IAB Europe CMP membership and your own registered CMP ID. The toggle in
**Banners → General Settings** shows a lock icon and explains this. Register with IAB Europe,
then set `CMP_ID` in your environment. Conzent Cloud runs under CMP ID 401.

**Can I move from self-hosted to Cloud, or the other way?**
Yes, but not automatically. There is a legacy-account import on the profile page for users
coming from the older Conzent product; moving between OCI and Cloud is a manual export/import —
contact support.

**Am I limited to a certain number of sites on self-hosted?**
No. `EditionService::getMaxDomains()` returns 0 (unlimited) when `CMP_ID` is not set.

## Related

- Knowledgebase: Account - Document: billing.md — plans and invoices (Cloud)
- Knowledgebase: Self-Hosting - Document: install.md — installing OCI
- Knowledgebase: Self-Hosting - Document: configuration.md — environment reference
- Knowledgebase: Compliance - Document: iab-tcf.md — TCF and CMP IDs
