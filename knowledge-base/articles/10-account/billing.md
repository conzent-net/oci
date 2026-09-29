---
id: account.billing
title: Billing and subscription
area: Account
knowledgebase: Account
url: /billing
menu_path: Account > Billing
edition: [cloud]
audience: [customer, agency]
plan: any
tags: [billing, subscription, plan, upgrade, invoice, stripe, cancel, trial, pricing, vat]
related: [platform.editions, sites.list, account.profile, agency.customers]
source_files:
  - src/Modules/Billing/templates/billing.html.twig
  - src/Modules/Billing/config/routes.php
  - config/pricing.json
  - templates/components/pricing_table.html.twig
questions:
  - How do I upgrade my plan?
  - How much does Conzent cost?
  - How do I cancel my subscription?
  - Where do I download my invoices?
  - Why are my sites suspended?
  - What happens when I hit my pageview limit?
  - Is there a free trial?
  - How do I add my VAT number to invoices?
  - What is the difference between the two plans?
---

# Billing and subscription

## Where to find it

**Account → Billing** in the sidebar, or the account menu in the top bar. URL: `/billing`.

> **Cloud only.** Self-hosted installs have no billing — the page is not registered and the
> menu entry is hidden. See Knowledgebase: Platform - Document: editions.md.

## What it does

Shows your current plan, lets you subscribe or change plan through Stripe Checkout, opens the
Stripe customer portal for payment methods, and lists your invoices and payment history.

## Page sections

| Section | What it shows |
|---|---|
| Current Plan | Plan name and billing cycle, or **No Active Plan** with a prompt to subscribe |
| Actions | **Manage Payment / Billing Portal** (opens Stripe), **Cancel Subscription** |
| Pricing table | Plan cards with the site-count slider, monthly/yearly toggle, and Subscribe buttons |
| Invoices | Invoice number, Date, Amount, Status, plus links to view online and download the PDF |
| Payment history | Date, Description, Type |
| Migrate | Shown when a legacy subscription can be moved across (`/billing/migrate`) |

## Plans

Two plans, both priced **per site per month**, with the per-site price falling as the site count
rises. A **14-day trial** applies.

| | Small Businesses | Agencies and E-commerce |
|---|---|---|
| From | €7 / site / month | €30 / site / month |
| At 200+ sites | €2 / site / month | €5 / site / month |
| Pageviews per month | 100,000 | Unlimited |
| Banner languages | 2 | Unlimited |
| Banner layouts | 2 | Unlimited |
| Privacy frameworks | 2 | Unlimited |
| Custom layouts | No | Unlimited |
| Remove "Powered by Conzent" | No | Yes |

Both plans include: GDPR / ePrivacy / CCPA-CPRA compliance, LGPD / POPIA and other global
frameworks, compliance checklist, audit logging, consent logging and renewal, the revenue impact
dashboard, Google Consent Mode v2, IAB TCF 2.4, Microsoft Clarity and Amazon consent signals,
cookie blocking before consent, automatic monthly scans, YouTube and embed blocking, 52
pre-translated languages with geo targeting, subdomain consent management, GPC and Do-Not-Sell
signal handling, agency dashboard, EU server location, and 24/7 support.

Yearly billing is charged at the yearly rate shown in the pricing table (a discount on monthly).

## Why sites get suspended

The **Status** column on `/sites` shows a reason under any suspended site:

| Reason | Meaning | Fix |
|---|---|---|
| No subscription | No active plan | Subscribe |
| Plan limit | More sites than the plan covers | Upgrade, or disable/delete a site to free a slot |
| Subscription ended | A previous subscription lapsed | Resubscribe |

Suspended sites stop serving a banner. Configuration and consent history are kept.

## Pageview limits

The dashboard shows a **Monthly Pageviews** gauge. At 80% you get a warning; at 100% the banner
is paused until the next billing period or an upgrade. Unlimited plans show "Unlimited
pageviews" instead of a gauge.

## How to change plan

1. Open **Account → Billing**.
2. Set the site-count slider to the number of sites you need, and pick monthly or yearly.
3. Click **Subscribe** on the plan you want — this opens Stripe Checkout.
4. Complete payment. Suspended sites reactivate automatically once the subscription is active.

## How to cancel

1. **Account → Billing** → **Cancel Subscription**.
2. Confirm in the modal (**Keep Subscription** backs out).

Cancellation runs to the end of the paid period. After that, sites move to
**Suspended — subscription ended** and stop serving a banner. Data is retained.

## Common questions

**Where are my invoices?**
The Invoices table on this page — view online or download the PDF. Both open Stripe-hosted
documents.

**How do I get my VAT number on the invoice?**
Set it on **Account → Profile** under Company Information before the next invoice is issued.

**I upgraded but my sites are still suspended.**
Reload `/sites`. If a site is still suspended, check it is not a second reason — for example
enough sites exist to still exceed the new limit. Reactivating is per site via the **Enable**
button.

**Is there a free plan?**
No free Cloud tier — there is a 14-day trial. If you want free, self-host: Conzent OCI is
Apache 2.0 and unlimited.

**Can I change my payment card?**
**Manage Payment / Billing Portal** opens Stripe's portal, where cards, addresses and receipts
are managed.

**I am an agency — how does customer billing work?**
Agency plans cover the sites you manage. Customer accounts you create do not need their own
subscription. See Knowledgebase: Growth - Document: agency.md.

## Related

- Knowledgebase: Platform - Document: editions.md — what plan limits gate
- Knowledgebase: Sites - Document: sites-list.md — suspended-site statuses
- Knowledgebase: Account - Document: profile.md — VAT and billing address
