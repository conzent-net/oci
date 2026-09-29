---
id: growth.abtest
title: A/B Split Tests
area: Growth
knowledgebase: Growth
url: /ab-tests
menu_path: Configuration > A/B Tests
edition: [cloud]
audience: [customer, agency]
plan: any
tags: [ab-test, split-test, experiment, variant, control, challenger, uplift, confidence, consent-rate, optimisation]
related: [growth.impact, consent.logs, banner.layouts-library, dashboard.customer]
source_files:
  - templates/pages/abtest/list.html.twig
  - templates/pages/abtest/detail.html.twig
  - src/Modules/ABTest/config/routes.php
  - templates/pages/dashboard/customer.html.twig
questions:
  - How do I A/B test my cookie banner?
  - Which banner design gets the most consents?
  - What is statistical confidence in an A/B test?
  - How long should I run a split test?
  - How do I pick the winner of an experiment?
  - What is a control and a challenger?
  - How do I add a variant?
---

# A/B Split Tests

## Where to find it

**Configuration → A/B Tests** in the sidebar. URL: `/ab-tests`; an experiment opens at
`/ab-tests/{id}`.

> **Cloud only.** Self-hosted installs do not have the A/B testing module.

## What it does

Runs two or more banner variants against live traffic, splits visitors between them, and reports
which produces more consent — with a confidence figure, so you know when the difference is real
rather than noise.

Every consent record stores which variant the visitor saw, which is how the attribution works.
See Knowledgebase: Consent - Document: consent-detail.md.

## Experiment list

Cards per experiment showing its name and status, with **New A/B Experiment** in the header.

| Modal | Fields |
|---|---|
| New A/B Experiment | Experiment name, e.g. "Bottom banner vs Popup" |
| Edit Experiment | Rename |
| Delete Experiment | Confirmation |

## Experiment detail

Variants, live results and the lifecycle actions.

| Action | What it does |
|---|---|
| **Start Experiment** | Begins splitting traffic. Confirmation modal |
| **Pause** | Stops splitting; everyone sees the control |
| **Select Winner** | Ends the experiment and makes the chosen variant the site's banner |
| **Add Variant** | Adds a challenger |
| **Edit Variant** / **Delete Variant** | Manage individual variants |

### Add Variant

| Field | Options |
|---|---|
| Layout | **Same as control (default)**, or one of the system layouts — Classic, Minimal, Stacked, Card, Sidebar, Hero — or any of your custom layouts |

Leaving the layout as "same as control" lets you test non-layout differences; picking a different
layout tests the design itself.

## Results

Shown on the experiment detail page and, once data exists, as an **A/B Test Performance** block
on the dashboard.

| Metric | Meaning |
|---|---|
| **Consent Rate by Variant** | Radial chart comparing acceptance per variant |
| **Consent Breakdown** | Accepted / Rejected / Partial per variant |
| **Total Interactions** | How many banner interactions the test has collected |
| **Control Consent Rate** | The baseline |
| **Challenger Consent Rate** | The variant being tested |
| **Uplift** | The relative difference, green when positive |
| **Confidence** | Statistical confidence that the difference is real |
| Winner badge | A trophy and "Statistically significant winner" at **95%+** confidence; otherwise "Gathering Data" |

## Reading the result

**95% confidence** is the conventional threshold. Below it the experiment is still noise —
"Gathering Data" means exactly that, not "no difference".

How long that takes depends on traffic and effect size. A site with a few hundred banner
interactions a day, testing a change worth a couple of percentage points, needs weeks. Stopping
early because one variant looks ahead is the classic mistake — early leads reverse routinely.

## What is worth testing

| Test | Typical effect |
|---|---|
| Banner type (bar vs box vs popup) | Large. Popups get more consents and more irritation |
| Position (top vs bottom) | Small to moderate |
| Layout template | Moderate |
| Wording of the title and message | Moderate, and often the cheapest win — explaining the value exchange beats restyling |
| Categories on the first layer | Varies; can raise partial consent at the cost of full acceptance |

What you must **not** test is anything that makes rejecting harder than accepting. Under GDPR
that invalidates the consent, and a "winning" variant that is a dark pattern is worse than
useless — the consents it collects are not lawful.

## Common questions

**How is traffic split?**
Visitors are assigned to a variant and the assignment is recorded on their consent record, so the
comparison is like-for-like.

**Does a running experiment override my banner settings?**
The variants define what differs; everything else comes from your normal banner settings.
Pausing or selecting a winner returns the site to a single configuration.

**Can I run several experiments on one site?**
Run one at a time. Concurrent experiments contaminate each other's results.

**What happens when I select a winner?**
The experiment ends and the winning variant's configuration becomes the site's banner.

**Where do I see the money impact rather than the rate?**
**General → Revenue Impact** converts consent rate into estimated revenue. See
Knowledgebase: Growth - Document: revenue-impact.md.

**The dashboard A/B card disappeared.**
It only renders when an experiment on the selected site has collected data.

## Related

- Knowledgebase: Growth - Document: revenue-impact.md — the revenue side of consent rate
- Knowledgebase: Consent - Document: consent-logs.md — the underlying rates
- Knowledgebase: Banner - Document: layouts-library.md — the layouts you can test
- Knowledgebase: Consent - Document: consent-detail.md — the per-record variant field
