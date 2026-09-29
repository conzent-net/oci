---
id: platform.navigation
title: Navigation — the complete menu map
area: Platform
knowledgebase: Platform
url: /
menu_path: General > Dashboard
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [navigation, menu, sidebar, where-is, navbar, dark-mode, notifications, tour]
related: [platform.overview, platform.roles, platform.site-context]
source_files:
  - templates/components/sidebar.html.twig
  - templates/components/navbar.html.twig
  - config/routes.php
  - src/Modules/ABTest/module.php
  - src/Modules/Agency/module.php
  - src/Modules/Billing/module.php
  - src/Modules/Impact/module.php
  - src/Modules/Admin/module.php
questions:
  - Where do I find the banner settings?
  - Where is the cookie list?
  - How do I navigate the Conzent app?
  - Where is the menu item for consent logs?
  - How do I switch to dark mode?
  - Where are my notifications?
  - How do I take the guided tour?
  - Why can't I see the Agency menu?
  - Where do I log out?
---

# Navigation — the complete menu map

## Where to find it

The sidebar on the left of every page. The top bar carries the account menu, notifications, dark
mode and the guided tour.

## What it does

The sidebar groups every page into four fixed sections — General, Configuration, Compliance,
Account — plus sections that appear only when the matching module is installed and your role
allows it. Menu items are hidden, not disabled, when they do not apply to you.

## Sidebar map

### General

| Menu item | URL | Notes |
|---|---|---|
| Dashboard | `/` | Setup wizard, stats, compliance score, recommendations |
| Revenue Impact | `/impact` | Cloud only |
| Sites | `/sites` | All your websites |
| Consent Logs | `/consents` | Consent audit trail |

### Configuration

| Menu item | URL | Notes |
|---|---|---|
| Banners | `/banners` | All banner settings and translations |
| A/B Tests | `/ab-tests` | Cloud only |
| Layouts | `/layouts` | Layout library and custom layout editor |
| Languages | `/languages` | Which languages this site's banner supports |
| GTM Wizard | `/gtm/wizard` | Move existing tags into Google Tag Manager |
| Matomo TM Wizard | `/matomo/wizard` | Same, for Matomo Tag Manager |
| Compliance Checklist | `/compliance` | Per-regulation task lists |

### Compliance

| Menu item | URL | Notes |
|---|---|---|
| Privacy Frameworks | `/sites/frameworks` | Which laws apply to this site |
| Scans | `/scans` | Cookie scan history and scheduling |
| Cookies | `/cookies` | Detected cookies by category |
| Policies | `/policies` | Cookie and privacy policy generators |
| Reports | `/reports` | Generated and scheduled compliance reports |

### Account

| Menu item | URL | Notes |
|---|---|---|
| Profile | `/account` | Personal details, password, company, delete account |
| Billing | `/billing` | Cloud only |
| Agency Invites | `/agency/invites` | Only for customers who have been invited by an agency |

### Agency (agency and admin roles, Cloud only)

| Menu item | URL |
|---|---|
| Dashboard | `/agency` |
| Customers | `/agency/customers` |

### Admin (admin role only)

A single **Admin** entry pinned to the bottom of the sidebar. Hovering it opens a flyout with
the platform tools; clicking goes to `/admin` and swaps in the full admin menu: Dashboard,
Users, Scan Servers, Cookie Requests, Unclassified Cookies, Beacon Requests, Unclassified
Beacons, Plans, Billing, News, News Sources, Knowledge Base, Chat Transcripts, Audit Log.

## Top bar

| Control | What it does |
|---|---|
| Sidebar toggle (☰) | Collapses the sidebar |
| Guided tour (route icon) | Replays the onboarding walkthrough for the current page |
| Theme toggle (moon/sun) | Switches the app between light and dark. This is the *app* theme — the banner's own light/dark colours are set separately in **Banners → Color Settings** |
| Notifications (bell) | Product announcements; the badge shows unread count. Click one to read it in a modal |
| Account menu (your name) | Profile, Billing (Cloud), Admin (admins), Logout |

When an agency is impersonating a customer, a banner with a "return" arrow appears so you can
end the impersonation.

## Pages with no menu entry

Reachable by clicking through, not from the sidebar:

| Page | URL | Reached from |
|---|---|---|
| Add site wizard | `/sites/create` | **Add Site** on `/sites`, or automatically on first login |
| Banner content editor | `/banners/content` | Banner settings |
| Layout editor | `/layouts/{id}/edit` | Edit on a custom layout card |
| Cookie policy wizard | `/policies/cookie` | Policies page |
| Privacy policy wizard | `/policies/privacy` | Policies page |
| Consent detail (proof) | `/consents/{id}` | A row in Consent Logs |
| Scan detail | `/scans/{id}` | A row in the scan history |
| Report detail | `/reports/{id}` | A row in the reports table |
| A/B test detail | `/ab-tests/{id}` | An experiment card |
| Cookie Categories | `/categories` | Linked from Cookies |
| Account setup | `/account/setup` | Shown once, right after registration |

## Common questions

**Why don't I see the Agency menu?**
It needs the agency or admin role *and* Conzent Cloud. Self-hosted installs have no agency
module. See Knowledgebase: Platform - Document: roles.md.

**Why is there no Billing entry?**
You are on a self-hosted install. Billing only exists on Cloud. See
Knowledgebase: Platform - Document: editions.md.

**I changed a setting on one page but another page shows a different site.**
Every site-scoped page has its own site selector, and switching there sets the site for the
whole app via a cookie. See Knowledgebase: Platform - Document: site-context.md.

**Where is the dark mode for the banner itself?**
Not in the top bar. The banner has its own Light Theme / Dark Theme tabs under
**Configuration → Banners → Color Settings**.

## Related

- Knowledgebase: Platform - Document: roles.md — what each role can see
- Knowledgebase: Platform - Document: site-context.md — how the current site is chosen
- Knowledgebase: Platform - Document: editions.md — which menus exist per edition
