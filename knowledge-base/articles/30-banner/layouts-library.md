---
id: banner.layouts-library
title: Layouts — the layout library
area: Banner
knowledgebase: Banner
url: /layouts
menu_path: Configuration > Layouts
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [layouts, templates, duplicate, custom-layout, preview, classic, minimal, card, sidebar, hero, stacked]
related: [banner.layout-editor, banner.layout, growth.abtest, account.billing]
source_files:
  - templates/pages/layouts/list.html.twig
  - src/Banner/Controller/LayoutListHandler.php
  - src/Banner/Controller/LayoutDuplicateHandler.php
  - src/Banner/Controller/LayoutPreviewHandler.php
  - src/Banner/Controller/LayoutDeleteHandler.php
  - resources/consent/layouts/layouts.php
questions:
  - What banner layouts are available?
  - How do I create a custom layout?
  - How do I preview a layout before using it?
  - Why is the Duplicate button greyed out?
  - How do I delete a custom layout?
  - Can I use the same custom layout on several sites?
  - What is the difference between a system layout and a custom layout?
---

# Layouts — the layout library

## Where to find it

**Configuration → Layouts** in the sidebar. URL: `/layouts`.

## What it does

The library of banner designs. System layouts ship with Conzent and are read-only; duplicating
one produces a custom layout you can edit freely and assign to any of your sites.

## Page sections

### System Layouts

Cards with a thumbnail, name, description and the positions each supports.

| Layout | Character |
|---|---|
| **Classic** | The standard bar. Usually the default |
| **Minimal** | Stripped-back, smallest footprint |
| **Stacked** | Vertically stacked content and buttons |
| **Card** | Elevated card treatment |
| **Sidebar** | Slides in from the side |
| **Hero** | Large, prominent presentation |

Card actions:

| Action | What it does |
|---|---|
| **Preview** | Opens the layout rendered in a modal, in an iframe |
| **Duplicate** | Copies it into your Custom Layouts, where it becomes editable. Greyed out with an "Upgrade" tag when your plan does not allow more custom layouts |

### Custom Layouts

Cards for layouts you own, each showing the name, which system layout it was based on, and when
it was last updated.

| Action | What it does |
|---|---|
| **Edit** | Opens the layout editor at `/layouts/{id}/edit` |
| **Delete** | Removes the custom layout. A layout in use by a site should be swapped out first |

Clicking anywhere on a custom layout card also opens the editor. When you have none, an empty
state points you at duplicating a system layout.

## Header

The site selector scopes the page — custom layouts are shown for the selected site's account,
and duplication respects that site's plan limits.

## Plan limits

| | Entry plan (Cloud) | Business plan (Cloud) | Self-hosted |
|---|---|---|---|
| System layouts | All, usable | All, usable | All, usable |
| Custom layouts | Not available | Unlimited | Unlimited |
| Layouts assignable per site | 2 | Unlimited | Unlimited |

At the cap, Duplicate is replaced by a disabled control carrying an **Upgrade** tag.

## How to create a custom layout

1. **Configuration → Layouts**.
2. **Preview** a few system layouts and pick the closest starting point.
3. **Duplicate** it. It appears under Custom Layouts.
4. **Edit** to open the layout editor and change the markup. See
   Knowledgebase: Banner - Document: layout-editor.md.
5. Assign it: **Configuration → Banners → Banner Layout → Active Layout**, pick your custom
   layout, then Save.

## Common questions

**Can I edit a system layout directly?**
No — they are read-only so upgrades can improve them without overwriting your work. Duplicate
first.

**Can one custom layout be used on several sites?**
Yes. Custom layouts belong to your account, so any of your sites can select one under **Banner
Layout → Active Layout**.

**I deleted a layout that a site was using.**
That site falls back to a system layout. Reassign it explicitly under **Banners → Banner
Layout**.

**Preview looks different from my live banner.**
Preview renders the layout with sample content and default colours. Your site's own colours,
text and content toggles apply on top. Use the **Preview** button on the Banners page for a
render that includes your settings.

**Which layout gets the most consents?**
It depends on your audience. Test it rather than guess — **Configuration → A/B Tests** can run
two layouts against live traffic and report uplift and confidence. Cloud only.

## Related

- Knowledgebase: Banner - Document: layout-editor.md — editing custom layout markup
- Knowledgebase: Banner - Document: banner-layout.md — assigning a layout to a site
- Knowledgebase: Growth - Document: ab-tests.md — testing layouts against each other
