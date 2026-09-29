---
id: policies.cookie-wizard
title: Cookie Policy Wizard
area: Policies
knowledgebase: Compliance
url: /policies/cookie
menu_path: Compliance > Policies > Create/Edit Cookie Policy
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [cookie-policy, wizard, audit-table, embed, effective-date, revisit-widget, generate-policy]
related: [policies.overview, policies.privacy-wizard, cookies.list, banner.content]
source_files:
  - templates/pages/policies/cookie_wizard.html.twig
  - src/Policy/Controller/CookiePolicyWizardHandler.php
  - src/Policy/Controller/CookiePolicySaveHandler.php
  - src/Policy/Service/PolicyService.php
questions:
  - How do I generate a cookie policy?
  - How do I show my cookie list inside the policy?
  - What is the cookie audit table?
  - How do I add a Cookie Settings link to my policy page?
  - The policy heading is duplicated on my page
  - What is the effective date on a policy?
  - How do I edit a cookie policy template?
---

# Cookie Policy Wizard

## Where to find it

**Compliance → Policies** → **Create Cookie Policy** (or **Edit Cookie Policy**).
URL: `/policies/cookie`.

Opened with a template ID, the same wizard edits a template instead of a site's policy — a blue
notice at the top names the template and reminds you that changes only reach sites via
**Apply to Sites**.

## What it does

Builds a cookie policy for the selected site in three steps, combining the text you write with
a live table of the cookies actually detected on your site.

## Step 1 — Types of Cookies

| Field | What it does | Notes |
|---|---|---|
| **Type Heading** | The heading above the cookie types section | Free text |
| **Show cookie audit table in policy** | Embeds your scanned, categorised cookies into the policy via `<div class="cnz-cookie-table"></div>` | Recommended — it keeps the policy accurate as scans find new cookies |
| **Include heading in generated policy** | Whether the generated output carries its own top-level heading | Turn **off** when embedding in a CMS page that already has its own `<h1>`, otherwise the heading appears twice |

## Step 2 — Manage Preferences

| Field | What it does | Notes |
|---|---|---|
| **Preference Heading** | Heading for the section telling visitors how to change their choices | Free text |
| **Revisit Consent Widget Code** | HTML for the "Cookie Settings" link that reopens the banner | e.g. `<a class="conzent-revisit">Cookie Settings</a>` |
| **Preference Description** | The body text explaining how to manage cookie preferences | Textarea |
| **Effective Date** | The date this version of the policy takes effect | Date picker. Shown on the Policies page |

Including a working revisit link matters: GDPR requires withdrawing consent to be as easy as
giving it, and a policy page that only *describes* how to withdraw does not achieve that.

## Step 3 — Preview

Renders the finished policy from your answers. Review it, then save.

## After saving

1. Go to **Compliance → Policies**.
2. Copy the **Cookie Policy** embed code: `<div class="cnz-cookie-policy"></div>`.
3. Paste it into your cookie policy page. The page must load the Conzent script.
4. Set the site's **Privacy Policy URL** on `/sites` so the banner can link to your policy pages.

## Common questions

**What is the difference between the two embed codes?**
`cnz-cookie-policy` renders the whole generated cookie policy document. `cnz-cookie-table`
renders only the cookie table, and is what the audit-table checkbox inserts inside the policy.
Use the table snippet on its own if you want Conzent's cookie list inside a policy you wrote
yourself.

**My page shows the heading twice.**
Turn off **Include heading in generated policy** in step 1. Your CMS is already rendering a page
title.

**The cookie table is empty.**
No cookies have been detected yet. Run a scan under **Compliance → Scans**, and make sure the
detected cookies are classified.

**Does the policy update itself?**
The cookie *table* re-renders from your current cookie list, so it stays current. The text you
wrote stays as written — re-run the wizard when your processing changes.

**How do I use the same cookie policy on several sites?**
Generate it once, then **Promote to Template** on the Policies page and **Apply to Sites**. See
Knowledgebase: Compliance - Document: policies-overview.md.

**Should I set the effective date to today?**
Set it to the date the policy actually goes live. If you materially change how you use cookies,
update the date and consider **Renew User Consents** in Banner Advanced Settings so visitors
re-consent.

## Related

- Knowledgebase: Compliance - Document: policies-overview.md — embedding, templates, applying to sites
- Knowledgebase: Compliance - Document: privacy-policy-wizard.md — the privacy policy generator
- Knowledgebase: Cookies - Document: cookies-list.md — the data behind the audit table
- Knowledgebase: Cookies - Document: scans.md — populating the cookie list
