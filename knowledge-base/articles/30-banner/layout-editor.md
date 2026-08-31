---
id: banner.layout-editor
title: Layout editor — editing a custom layout
area: Banner
knowledgebase: Banner
url: /layouts/{id}/edit
menu_path: Configuration > Layouts > Edit (on a custom layout)
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: business
tags: [layout-editor, twig, html, template, variables, validation, custom-layout, code]
related: [banner.layouts-library, banner.custom-css, banner.layout]
source_files:
  - templates/pages/layouts/editor.html.twig
  - src/Banner/Controller/LayoutEditorHandler.php
  - src/Banner/Controller/LayoutSaveHandler.php
questions:
  - How do I edit the HTML of my banner?
  - What variables can I use in a custom layout?
  - Why can't I save my layout?
  - What does "Missing required variables" mean?
  - How do I add my logo to the banner?
  - Can I write my own banner markup?
  - What is branding_html?
---

# Layout editor — editing a custom layout

## Where to find it

**Configuration → Layouts**, then **Edit** on a custom layout card (or click the card).
URL: `/layouts/{id}/edit`. System layouts are read-only — duplicate one first.

> Custom layouts need the **Business plan** on Cloud. Self-hosted installs are unlimited.

## What it does

A split-screen HTML/Twig editor with live preview. You write the banner's markup; Conzent
substitutes the variables that carry the buttons, category toggles, text and colours.

## Screen layout

| Area | What it is |
|---|---|
| **HTML / Twig Source** (left) | Syntax-highlighted code editor |
| **Live Preview** (right) | Renders as you type, in a sandboxed iframe |
| **Validation bar** (below) | Green when valid; red listing missing required variables; amber listing recommended ones |
| **Variable Reference** | A collapsible panel documenting every available variable |
| Header | Layout name, the system layout it was based on, a Valid/N errors badge, **Save** and **Back** |

**Save is disabled while required variables are missing.** This is deliberate — a layout without
its buttons would render a banner nobody can act on.

## Variables

### Banner — required

| Variable | What it inserts |
|---|---|
| `{{ buttons_html\|raw }}` | The action buttons (Accept / Reject / Customize), per your Content Settings |
| `{{ branding_html\|raw }}` | The "Powered by Conzent" branding. Required in the markup; whether it renders is controlled by the branding toggle and your plan |
| `[conzent_cookie_notice_banner_title]` | The banner title, in the visitor's language |
| `[conzent_cookie_notice_message]` | The banner body text, in the visitor's language |

### Preference Center — required

| Variable | What it inserts |
|---|---|
| `{{ cookie_categories_html\|raw }}` | The category toggle rows |
| `{{ pref_buttons_html\|raw }}` | The save/accept buttons for the preference centre |
| `{{ close_button_html\|raw }}` | The preference centre's close control |
| `[conzent_preference_center_preference_title]` | Preference centre title |
| `[conzent_preference_center_overview]` | Preference centre overview text |

### Optional

| Variable | What it inserts |
|---|---|
| `{{ revisit_html\|raw }}` | The floating revisit-consent button |
| `{{ privacy_policy_link\|raw }}` | Link to your privacy policy |
| `{{ logo_html\|raw }}` | Your site logo |
| `{{ banner_cookie_list\|raw }}` | The detected cookie table |
| `{{ google_privacy_policy\|raw }}` | Google DMA disclosure text |

### Styling

| Variable | Value |
|---|---|
| `{{ colors.notice_bg }}` | Cookie notice background, from Color Settings |
| `{{ colors.notice_border }}` | Notice border |
| `{{ colors.notice_title }}` | Title colour |
| `{{ colors.notice_description }}` | Message colour |
| `{{ banner_type }}` | CSS class for the current banner type |
| `{{ display_position }}` | The configured position |

Square-bracket tokens like `[conzent_cookie_notice_message]` are text placeholders, replaced with
whatever you set in **Banner Content & Translations** for the visitor's language. Twig `{{ }}`
variables are rendered HTML — always pipe them through `|raw`.

## How to edit a layout

1. **Configuration → Layouts** → **Duplicate** a system layout.
2. **Edit** the copy.
3. Change the markup. Watch the preview and the validation bar as you type.
4. Keep every required variable present — the bar names any you drop.
5. **Save** (enabled only when valid).
6. Assign it under **Configuration → Banners → Banner Layout → Active Layout**, then save the
   banner and purge with **Advanced Settings → Purge & Regenerate**.

## Common questions

**Save is greyed out.**
Required variables are missing. The red validation bar lists them by name; hover a tag for its
description. Paste them back and Save enables.

**Can I remove the branding variable?**
`{{ branding_html|raw }}` must stay in the markup. Whether it renders is decided by the **Remove
"Powered by Conzent" Branding** toggle in Content Settings, which needs the Business plan on
Cloud.

**Can I add my own JavaScript?**
The preview iframe is sandboxed and the banner runs in a constrained context. Keep layouts to
markup and CSS; put behaviour changes in the app's settings instead.

**My layout looks right in preview but wrong on my site.**
Preview uses sample content and default colours. Your real colours, text, banner type and
position apply on top. Check with the **Preview** button on the Banners page, which uses the
site's actual settings.

**How do I add my logo?**
Insert `{{ logo_html|raw }}` where you want it.

**Layout editor or Custom CSS?**
Editor for structural change and a reusable design; Custom CSS for restyling one site's existing
markup. Custom CSS is available on any plan.

## Related

- Knowledgebase: Banner - Document: layouts-library.md — duplicating and managing layouts
- Knowledgebase: Banner - Document: banner-custom-css.md — restyling without editing markup
- Knowledgebase: Banner - Document: banner-translations.md — what the `[conzent_…]` tokens resolve to
