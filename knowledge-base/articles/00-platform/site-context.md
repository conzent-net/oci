---
id: platform.site-context
title: The current site — how site context and switching work
area: Platform
knowledgebase: Platform
url: /sites
menu_path: General > Sites
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [site-selector, switch-site, site-context, multi-site, wrong-site, site-id]
related: [sites.list, platform.navigation, banner.general]
source_files:
  - templates/components/macros.html.twig
  - src/Dashboard/Service/DashboardService.php
  - config/routes.php
  - templates/pages/sites/index.html.twig
questions:
  - How do I switch between my websites?
  - Why am I editing the wrong site?
  - I changed the banner but it applied to another domain
  - Where is the site selector?
  - Does each site have its own banner settings?
  - How do I copy settings from one site to another?
---

# The current site — how site context and switching work

## Where to find it

The site selector dropdown sits in the header of every site-scoped page — Dashboard, Banners,
Cookies, Scans, Consent Logs, Policies, Reports, Layouts, Frameworks, Revenue Impact. It shows
the domain of the site you are currently editing.

## What it does

Almost everything in Conzent belongs to one site. Banner settings, cookie lists, categories,
consent logs, scans, policies, languages and frameworks are all per-site. The site selector
decides which one you are looking at, and the choice sticks as you move between pages.

## How the current site is chosen

The app resolves the site in this order:

1. **`?site_id=` in the URL** — always wins. Changing the selector reloads the page with this
   parameter.
2. **The `site_id` cookie** — set whenever you pick a site, and kept for a year.
3. **Your first active site** — the fallback for a fresh session.
4. **No sites at all** — you are redirected to the site creation wizard at `/sites/create`.

Because step 1 writes the cookie in step 2, switching site on the Banners page also switches it
on the Cookies page and everywhere else.

## Ways to switch

| Method | Where |
|---|---|
| Site selector dropdown | Header of any site-scoped page |
| Clicking a domain in the sites table | `/sites` — also jumps to that site's dashboard |
| The dashboard icon on a site row | `/sites` |
| Adding `?site_id=123` to any URL | Manual / bookmarks |

## What is *not* per-site

| Scope | Examples |
|---|---|
| **Per account** | Profile and company details, password, billing and plan, agency relationships, policy templates, notifications |
| **Per site** | Banner settings and content, colours, layouts assigned, languages, privacy frameworks, cookies and categories, scans, consent logs, reports, policies |

Policy *templates* are account-level and reusable; the policy applied to a site is per-site.

## Copying configuration between sites

There is no global "copy site settings" action. What you can reuse:

- **Policies** — generate one, use **Promote to Template**, then **Apply to Sites** and tick
  the sites. See Knowledgebase: Compliance - Document: policies-overview.md.
- **Layouts** — duplicating a system layout creates a custom layout you can assign to any site
  from **Banners → Banner Layout**.
- Everything else (colours, content, frameworks, languages) is configured per site.

## Common questions

**I changed a setting and it applied to the wrong domain.**
The site selector was pointing elsewhere. Check the domain in the page header, switch to the
right site, and redo the change. The header on Banners also shows the site's frameworks and the
last-updated timestamp, which helps confirm you are in the right place.

**Why does the selector only show some of my sites?**
Deleted sites are excluded. Suspended and disabled sites still appear so you can fix them.

**I only have one site — do I still need this?**
No. With a single site the selector shows that site and nothing changes.

**Does the site selector affect my Account or Billing pages?**
No. Those are account-level and ignore it.

**How do I find a site's Website Key?**
`/sites` shows it in the **Website Key** column, and the Install Script step on the dashboard
embeds it in the copy-paste snippet.

## Related

- Knowledgebase: Sites - Document: sites-list.md — the sites table
- Knowledgebase: Sites - Document: sites-create.md — adding a site
- Knowledgebase: Platform - Document: navigation.md — which pages are site-scoped
