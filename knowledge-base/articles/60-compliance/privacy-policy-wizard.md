---
id: policies.privacy-wizard
title: Privacy Policy Wizard
area: Policies
knowledgebase: Compliance
url: /policies/privacy
menu_path: Compliance > Policies > Create/Edit Privacy Policy
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [privacy-policy, wizard, data-collection, disclosure, dpo, eu-representative, retention, gdpr-article-13]
related: [policies.overview, policies.cookie-wizard, account.profile]
source_files:
  - templates/pages/policies/privacy_wizard.html.twig
  - src/Policy/Controller/PrivacyPolicyWizardHandler.php
  - src/Policy/Controller/PrivacyPolicySaveHandler.php
questions:
  - How do I generate a privacy policy?
  - What information do I need for the privacy policy wizard?
  - Do I need a Data Protection Officer?
  - What is an EU representative?
  - How long should I keep personal data?
  - How do I publish my privacy policy?
  - Can I edit a privacy policy template?
---

# Privacy Policy Wizard

## Where to find it

**Compliance → Policies** → **Create Privacy Policy** (or **Edit Privacy Policy**).
URL: `/policies/privacy`.

Opened with a template ID it edits a template rather than a site's policy, flagged by a notice
at the top.

## What it does

Builds a GDPR-shaped privacy policy over five steps. Each step asks about your actual data
practices; the generated document reflects your answers. Company details you entered on
**Account → Profile** pre-fill where they can.

## Step 1 — Website Info

| Field | What it does |
|---|---|
| Website URL | The site the policy covers |
| Company Name | Named as the data controller |
| Email | Contact address published in the policy |
| Phone | Contact number |
| Purpose of Website | E-Commerce / Blog / Landing Page / Other. Shapes the generated wording |
| Address, ZIP Code, State, Country | The controller's registered address |

## Step 2 — Data Collection

| Question | Reveals |
|---|---|
| Can users create accounts on your website? | — |
| Newsletter opt-out capability? | Opt-out methods: unsubscribe link in the footer, preferences collected at registration, users can update account settings, contact-us route |
| Do you collect personal information? | Data types: Name, Email, Mobile, Social media profile, Date of birth, Residential address, Work address, Payment information, plus a free-text "Other personal info collected" |
| Do you collect from users under 18? | Triggers children's-data wording (relevant to GDPR Article 8 and COPPA) |
| Do you track IP, device, or country? | — |

## Step 3 — Disclosure

**Purposes** — tick every one that applies: Marketing/Promotional, Creating user accounts,
Testimonials, Customer feedback, Enforce T&C, Processing payment, Support, Administration,
Targeted advertising, Manage customer orders, Site protection, User-to-user comments, Dispute
resolution, Manage user account.

**Do you share data with third-party services?** — when Yes, tick the categories: Ad services,
Sponsors, Marketing agencies, Legal entities, Analytics, Payment recovery services, Data
collection & processing.

**Data retention period** — Less than 1 year / 1–3 years / 3–5 years / 5+ years / Other (with a
free-text field).

Under GDPR you must state a retention period or the criteria used to set one — "as long as
necessary" alone is not sufficient.

## Step 4 — Tracking Technologies

| Field | What it does |
|---|---|
| **Cookie Policy Link** | URL of your cookie policy page, referenced from the privacy policy's Cookies section |

A note explains that Necessary cookies are always enabled and the other types are governed by
your banner configuration — so the two documents stay consistent without duplicating detail.

## Step 5 — Data Protection

| Field | What it does |
|---|---|
| Contact Name / Contact Email / Contact Address | Your general privacy contact |
| **EU Data Protection Officer?** | Yes reveals DPO Name, Email and Address |
| **EU Representative?** | Yes reveals Rep Name, Email and Address |
| **Effective Date** | When this version takes effect |
| **Include heading in generated policy** | Turn off when your CMS page already renders its own heading |

**Do you need a DPO?** GDPR Article 37 requires one if you are a public authority, if your core
activities involve large-scale regular and systematic monitoring, or large-scale processing of
special-category data. Most small sites do not.

**Do you need an EU representative?** Article 27 requires one if you are established outside the
EU but offer goods or services to, or monitor, people in the EU.

## After saving

1. **Compliance → Policies** → copy the **Privacy Policy** embed code:
   `<div class="cnz-audit-privacy-policy"></div>`.
2. Paste it into your privacy policy page. The page must load the Conzent script.
3. Set the site's **Privacy Policy URL** on `/sites` so the banner links to it.

## Common questions

**Is the generated policy legally sufficient?**
It is a structured starting point covering the disclosures GDPR Articles 13–14 expect, built from
your own answers. It is not legal advice. Have it reviewed if you process special-category data,
children's data, or transfer data outside the EU.

**My company details are wrong in the policy.**
They come from **Account → Profile**. Fix them there, then re-run the wizard.

**How do I reuse it across sites?**
Generate it once, **Promote to Template** on the Policies page, then **Apply to Sites**. Watch
out for details that genuinely differ per site — website URL, purpose, cookie policy link.

**I answered a question wrong.**
Re-open the wizard; it loads your saved answers. Change the step and save through to the end.

**Should retention be as short as possible?**
State what you actually do. An unrealistically short period you do not honour is worse than an
honest longer one with a stated reason.

## Related

- Knowledgebase: Compliance - Document: policies-overview.md — embedding, templates, applying
- Knowledgebase: Compliance - Document: cookie-policy-wizard.md — the cookie policy
- Knowledgebase: Account - Document: profile.md — the company details that pre-fill step 1
