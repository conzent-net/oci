---
id: growth.impact
title: Revenue Impact — what your consent rate costs
area: Growth
knowledgebase: Growth
url: /impact
menu_path: General > Revenue Impact
edition: [cloud]
audience: [customer, agency]
plan: any
tags: [revenue-impact, money-left-on-the-table, roas, cpa, emq, google-ads, meta-ads, consent-rate, signal-loss, tiers]
related: [consent.logs, growth.abtest, compliance.gcm, integrations.signals]
source_files:
  - src/Modules/Impact/templates/dashboard.html.twig
  - src/Modules/Impact/config/routes.php
  - development/oci/revenue-impact-tier3-setup.md
questions:
  - How much revenue am I losing to cookie rejections?
  - What is "money left on the table"?
  - How do I connect my Google Ads account to Conzent?
  - What is Event Match Quality?
  - What are the data tiers on the revenue impact page?
  - Are these revenue numbers real or estimated?
  - How do I improve my consent rate to recover revenue?
---

# Revenue Impact — what your consent rate costs

## Where to find it

**General → Revenue Impact** in the sidebar. URL: `/impact`.

> **Cloud only.** Not available on self-hosted installs.

## What it does

Translates your consent rate into money. When a visitor refuses marketing consent, your ad
platforms lose the conversion signal — so campaigns optimise on incomplete data and attribution
undercounts. This page estimates what that costs per month, and what a higher consent rate would
recover.

## Money left on the table

The headline figure: estimated monthly revenue your ad platforms cannot see because visitors
denied marketing consent, **net of what Google Consent Mode modelling already recovers**.

Beside it, **Upside at target consent** shows what you would gain by reaching a target rate,
against the rate you are at today.

A **Live data** or **Estimated** tag says which the number is based on. Below 100 consent events
in the period, a warning notes the figures will sharpen with more data.

## Data tiers

The page works with whatever data it has, and says which tier it is using.

| Tier | Source | Accuracy |
|---|---|---|
| **Tier 1** | Industry benchmarks plus your consent rate | Rough order of magnitude |
| **Tier 2** | Your manually entered business signals | Good — as good as your inputs |
| **Tier 3** | Live data pulled from connected Google Ads and Meta Ads accounts | Actual spend, ROAS and conversions |

## Your business signals (Tier 2)

Fill these in to move off pure benchmarks. Each field has a hover help bubble with an example and
where to find the number.

| Field | What it is | Example |
|---|---|---|
| **Average Order Value (€)** | Revenue from one conversion — a sale, order or lead. Every recovered conversion is valued at this | €85 |
| **Conversion Rate (%)** | Share of visitors who complete your goal. Enter `3.5` for 3.5%, not `0.035` | 3.5 |
| **Baseline Consent Rate (%)** | Your opt-in rate *before* you started improving the banner. Uplift is measured against it | 31 |
| **Google Ads Monthly Spend (€)** | Typical monthly Google Ads spend | €5,000 |
| **Meta Ads Monthly Spend (€)** | Typical monthly Meta (Facebook & Instagram) spend | €3,000 |
| **Average CPC (€)** | Average cost per click across paid campaigns. Spend ÷ CPC gives clicks | €1.50 |

## Google Ads panel

| Metric | Meaning |
|---|---|
| Observed Conversions | Conversions Google can actually see |
| Modeled Conversions | Conversions Google infers via Consent Mode modelling |
| Signal Quality | How complete the consent signal is |
| ROAS / Est. ROAS | Return on ad spend — actual when connected, estimated otherwise |
| Monthly Ad Spend | Spend for the period |
| Effective CPA | Cost per acquisition given current signal loss |
| CPA Improvement | Estimated CPA gain from a higher consent rate |

Connected (Tier 3) adds **Actual ROAS**, **Actual CPA**, **Modeling Uplift** and **Ad Clicks**
pulled live from the account.

## Meta Ads panel

| Metric | Meaning |
|---|---|
| Pixel Fire Rate | Share of visitors whose pixel actually fires |
| Est. Event Match Quality | Meta's measure of how well your events match real people, out of 10 |
| Visible Conversions | Conversions Meta can attribute |
| Signal Loss | Share of conversion signal lost to refusals |
| Current EMQ / Projected EMQ | EMQ today vs at the target consent rate |
| Additional Signals | Extra conversion signals a higher consent rate would recover |

Connected adds **Actual Purchases**, **Purchase Value**, **Actual ROAS** and **Ad Spend**.

Event Match Quality drives Meta's delivery and attribution. Low consent means fewer and poorer
signals, which means worse targeting — the loss compounds beyond the missing conversions
themselves.

## Connecting live accounts (Tier 3)

Each panel has an account picker: **Select your Google Ads account…** / **Select your Meta ad
account…**, preceded by an OAuth connection. Connect once, choose the account, and the panels
switch from estimates to live figures.

Tier 3 requires the platform to be configured on the Conzent server. Where it is not, the
connection controls are inert and the page stays on Tier 1/2 estimates.

## How to act on it

1. Fill in the business signals so the figures are yours, not benchmarks.
2. Read the **Upside at target consent** number — that is the prize.
3. Raise the consent rate legitimately: clearer value exchange in the banner copy, a less
   intrusive banner type, better timing. Test it with
   Knowledgebase: Growth - Document: ab-tests.md.
4. Make sure you are not losing signal for technical reasons: Consent Mode configured correctly
   (Knowledgebase: Compliance - Document: google-consent-mode.md) and the Meta/Microsoft signals on
   (Knowledgebase: Integrations - Document: consent-signals.md).
5. Consider **GCM Advanced**, which enables conversion modelling and directly reduces the
   modelled-loss component.

## Common questions

**Are these numbers real?**
Depends on the tier, and the page tells you which — **Live data** or **Estimated**. Tier 1 is a
benchmark-based order of magnitude. Tier 2 is as good as the signals you entered. Tier 3 is
pulled from your actual ad accounts.

**Why does it say "Limited data"?**
Fewer than 100 consent events in the period. Percentages on small samples swing wildly.

**Can I get to 100% consent?**
No, and you should not try. A rate that high normally means rejecting is harder than accepting,
which invalidates the consent. Realistic good performance for a compliant EU banner is 50–70%.

**Where do I connect Google Ads?**
The account picker in the Google Ads panel, after the OAuth connection. If the controls do nothing,
the platform is not configured on this Conzent instance.

**Does this exist on self-hosted?**
No — Cloud only.

## Related

- Knowledgebase: Growth - Document: ab-tests.md — testing your way to a better rate
- Knowledgebase: Consent - Document: consent-logs.md — the rate itself
- Knowledgebase: Compliance - Document: google-consent-mode.md — Basic vs Advanced modelling
- Knowledgebase: Integrations - Document: consent-signals.md — Meta and Microsoft signals
