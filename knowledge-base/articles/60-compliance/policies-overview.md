---
id: policies.overview
title: Policies — cookie and privacy policies
area: Policies
knowledgebase: Compliance
url: /policies
menu_path: Compliance > Policies
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [policies, cookie-policy, privacy-policy, embed, template, promote, apply-to-sites, clear, generate]
related: [policies.cookie-wizard, policies.privacy-wizard, cookies.list, banner.content]
source_files:
  - templates/pages/policies/index.html.twig
  - templates/pages/policies/_templates_table.html.twig
  - src/Policy/Controller/PolicyListHandler.php
  - src/Policy/Controller/PolicyTemplateSaveHandler.php
  - src/Policy/Controller/PolicyTemplateApplyHandler.php
  - src/Policy/Controller/PolicyTemplateRenameHandler.php
  - src/Policy/Controller/PolicyTemplateDeleteHandler.php
  - src/Policy/Controller/PolicyClearHandler.php
questions:
  - How do I create a cookie policy?
  - How do I add my privacy policy to my website?
  - What is the policy embed code?
  - How do I reuse the same policy across my sites?
  - What does "Promote to Template" do?
  - How do I apply a policy template to several websites?
  - How do I start my policy over?
  - Does the cookie policy update when new cookies are found?
---

# Policies — cookie and privacy policies

## Where to find it

**Compliance → Policies** in the sidebar. URL: `/policies`. The two wizards are at
`/policies/cookie` and `/policies/privacy`.

## What it does

Generates a cookie policy and a privacy policy for the selected site, keeps reusable templates
so agencies and multi-site owners do not rewrite them per site, and gives you embed codes that
render the finished policy on your own website.

## Policy status cards

Two cards side by side — **Cookie Policy** and **Privacy Policy** — each showing:

| Element | Meaning |
|---|---|
| Status tag | **Configured** (green) or **Not generated** (amber) |
| Effective date | When the current version took effect, once generated |
| **Create / Edit … Policy** | Opens the wizard. The label switches once a policy exists |
| **Promote to Template** | Saves the current policy as a reusable template |
| **Clear** | Wipes this site's policy so you can start over or apply a template |

Promote and Clear only appear once a policy exists.

## Embed Codes

Read-only snippets to paste where you want the policy to appear. The Conzent script fills them
in.

| Policy | Snippet |
|---|---|
| Cookie Policy | `<div class="cnz-cookie-policy"></div>` |
| Privacy Policy | `<div class="cnz-audit-privacy-policy"></div>` |

Click a field to select and copy it. The page holding the snippet must load the Conzent script.

The cookie policy embed re-renders from your current cookie list, so it stays accurate as scans
find new cookies — that is the main reason to embed rather than paste static HTML.

## Policy Templates

A table of saved templates, each with actions:

| Action | What it does |
|---|---|
| **Apply to Sites** | Opens a modal listing all your sites with tick boxes. Sites already using this template are pre-selected, and each row shows whether it currently has a policy and which template it uses |
| **Rename** | Changes the template's name |
| **Delete** | Removes the template. Sites already using it keep their applied policy |

Applying **overwrites** the target site's existing policy of that type.

## How to

**Create a cookie policy** — **Create Cookie Policy** → three-step wizard → save. See
Knowledgebase: Compliance - Document: cookie-policy-wizard.md.

**Create a privacy policy** — **Create Privacy Policy** → five-step wizard → save. See
Knowledgebase: Compliance - Document: privacy-policy-wizard.md.

**Publish it** — copy the embed code and paste it into a page on your site (typically
`/cookie-policy` and `/privacy-policy`). Then set the site's **Privacy Policy URL** on `/sites`
so the banner links to it.

**Reuse across sites** — perfect the policy on one site → **Promote to Template** and name it →
**Apply to Sites** and tick the others. Sites where the details genuinely differ still need their
own pass.

**Start over** — **Clear**, then either run the wizard again or apply a template.

## Common questions

**Does the cookie policy update itself when a scan finds new cookies?**
The embedded cookie table renders from your current cookie list, so new cookies appear once they
are scanned and classified. The surrounding policy text is what you wrote in the wizard and does
not change on its own.

**Can I edit the generated text directly?**
Edit it through the wizard, which regenerates the output from your answers. There is no free-text
override of the generated document — if you need bespoke legal wording, publish your own page and
embed only the cookie table.

**Is this legal advice?**
No. The wizards produce a solid, structured starting point built from your actual configuration
and detected cookies. Have a lawyer review it if your processing is unusual or high-risk.

**Applying a template overwrote a site's policy.**
That is what Apply does — the modal warns before you confirm. Re-run the wizard for that site to
rebuild it.

**Are templates per site?**
No. Templates are account-level and reusable; the policy *applied* to a site is per site.

**My embed shows nothing.**
The Conzent script must load on that page, and the policy must be generated (the card should say
Configured). Check with the site selector that you generated it for the right site.

**Where does the banner's cookie policy link point?**
To the site's **Privacy Policy URL** field, set on `/sites` in the create or edit wizard. Whether
the link shows at all is controlled by **Banners → Content Settings → Cookie Policy Link**.

## Related

- Knowledgebase: Compliance - Document: cookie-policy-wizard.md — the cookie policy generator
- Knowledgebase: Compliance - Document: privacy-policy-wizard.md — the privacy policy generator
- Knowledgebase: Cookies - Document: cookies-list.md — the data behind the cookie table
- Knowledgebase: Banner - Document: banner-content.md — linking policies from the banner
