---
id: sites.frameworks
title: Privacy Frameworks — choosing which laws apply
area: Compliance
knowledgebase: Sites
url: /sites/frameworks
menu_path: Compliance > Privacy Frameworks
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [frameworks, gdpr, ccpa, cpra, lgpd, popia, eprivacy, opt-in, opt-out, gpc, geo, regulations]
related: [sites.create, banner.general, compliance.checklist, dashboard.customer]
source_files:
  - templates/pages/sites/frameworks.html.twig
  - src/Site/Controller/SiteFrameworksHandler.php
  - src/Compliance/Service/PrivacyFrameworkService.php
  - config/privacy-frameworks.json
questions:
  - Which privacy frameworks should I enable?
  - What is the difference between opt-in and opt-out?
  - Do I need CCPA if I only sell in Europe?
  - What does GPC mean on a framework card?
  - How many frameworks can I enable on my plan?
  - Does the banner change per visitor country?
  - What is ePrivacy and why is it enabled with GDPR?
  - Which laws does Conzent support?
---

# Privacy Frameworks — choosing which laws apply

## Where to find it

**Compliance → Privacy Frameworks** in the sidebar. URL: `/sites/frameworks`. The same choice is
also step 3 of the add-site and edit-site wizards.

## What it does

Tells Conzent which privacy laws apply to your visitors. The banner then adapts **per visitor
location**: which cookies are blocked before consent, which buttons appear, whether a Do Not
Sell link is required, and whether browser signals like GPC must be honoured.

Selecting a framework does not restrict who sees the banner — it changes how the banner behaves
for people the framework covers.

## Page layout

Framework cards grouped by region. Click a card (or its checkbox) to toggle it. The header shows
a live "N selected" count and a **Save Frameworks** button; there is a second save button at the
bottom.

## What each card tells you

| Badge | Meaning |
|---|---|
| `active` / `active_phased` | Whether the law is fully in force |
| `opt-in` | Nothing non-essential loads until the visitor agrees |
| `opt-out` | Tracking may run; the visitor must be able to stop it |
| `hybrid` | Opt-in for sensitive data, opt-out for the rest |
| `implied` | Notice is required; consent can be implied |
| `block-all` | Blocks all non-essential cookies before consent |
| `partial-block` | Blocks specific categories only |
| `no-block` | No pre-consent blocking required |
| `GPC` | The law requires honouring the Global Privacy Control browser signal |
| `DNS` | A "Do Not Sell or Share" link is required |
| `N countries` / state list | Which territories it covers |

## Supported frameworks

| Framework | Region | Consent model |
|---|---|---|
| GDPR | EU & EEA (30 countries) | opt-in |
| ePrivacy Directive | EU | opt-in |
| TDDDG / TTDSG | Germany | opt-in |
| UK GDPR + PECR | United Kingdom | opt-in |
| CCPA / CPRA | California | opt-out |
| VCDPA | Virginia | hybrid |
| PIPEDA | Canada | hybrid |
| Quebec Law 25 | Canada (Quebec) | opt-in |
| LGPD | Brazil | opt-in |
| POPIA | South Africa | opt-in |
| PIPL | China | opt-in |
| APPI | Japan | opt-in |
| PIPA | South Korea | opt-in |
| DPDPA | India | opt-in (phased) |
| PDPA | Singapore | hybrid |
| PDPA | Thailand | opt-in |
| UAE PDPL | United Arab Emirates | opt-in |
| Saudi Arabia PDPL | Saudi Arabia | opt-in |
| Australian Privacy Act | Australia | implied |
| NZ Privacy Act 2020 | New Zealand | implied |

Additional US state laws are covered by a shared template alongside CCPA/CPRA and VCDPA.

## Plan limits

On Cloud, the entry plan allows **2 frameworks**. When you hit the cap, unselected checkboxes
disable and a message points at the upgrade. The Agencies and E-commerce plan is unlimited, as
is every self-hosted install.

## How to choose

1. **Where are your visitors?** Not where your company is. If anyone in the EU can reach your
   site, enable GDPR.
2. **GDPR is two selections.** Ticking the GDPR card enables **GDPR + ePrivacy Directive**
   together — ePrivacy is the actual cookie rule, GDPR is the data-protection rule behind it.
   Removing the card removes both.
3. **Add US Privacy** if you have US visitors and sell or share personal information. It brings
   the Do Not Sell link and GPC handling.
4. **Add national laws** for territories where you have real traffic — LGPD for Brazil, POPIA for
   South Africa, and so on.
5. **Save Frameworks.** The banner script regenerates with the new rules.

Over-selecting has a cost: each framework adds requirements to the compliance score, so the
dashboard will flag items you have not configured.

## Common questions

**Does the banner look different per country?**
Behaviour changes, not necessarily appearance. An EU visitor gets opt-in blocking with equal
Accept and Reject buttons; a Californian visitor gets the opt-out model with a Do Not Sell link.
Which frameworks are consulted depends on the visitor's location, resolved by geolocation.

**Do I need CCPA if I only trade in Europe?**
Only if Californian residents visit your site and you sell or share their personal information.
If you have no US traffic, leave it off.

**Why did my compliance score drop after adding a framework?**
The new framework brought requirements you have not met yet — for example a missing Do Not Sell
link or GPC handling. The Site Status card on the dashboard lists the specific warnings.

**What does GPC actually do?**
When a visitor's browser sends the Global Privacy Control header, Conzent treats it as an
opt-out for the frameworks that require it — without showing anything. Enable
**Respect "Global Privacy Control"** under **Banners → Content Settings** for US frameworks.

**I want to restrict the banner to Europe.**
That is geo targeting, not frameworks. Set **Geo Targeting** in **Banners → General Settings**.
Frameworks control *behaviour*; geo targeting controls *whether the banner shows at all*.

**Are frameworks per site or per account?**
Per site. Set them for each site individually.

## Related

- Knowledgebase: Banner - Document: banner-general.md — geo targeting and consent expiry
- Knowledgebase: Compliance - Document: checklist.md — per-regulation task lists
- Knowledgebase: Sites - Document: sites-create.md — choosing frameworks at creation
- Knowledgebase: Platform - Document: glossary.md — opt-in, opt-out, GPC, DNS
