---
id: banner.layout
title: Banner Settings — Banner Layout and Layout Settings
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Banner Layout / Layout Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [layout, position, banner-type, box, popup, preference-center, sidebar, push-down, template, categories-first-layer]
related: [banner.layouts-library, banner.general, banner.colors, growth.abtest]
source_files:
  - templates/pages/banners/index.html.twig
  - resources/consent/layouts/layouts.php
  - src/Banner/Controller/LayoutListHandler.php
questions:
  - How do I move the banner to the top of the page?
  - How do I make the banner a popup instead of a bar?
  - What layouts are available?
  - How do I show cookie categories directly on the banner?
  - Where do I change how the preference center appears?
  - What is the difference between a banner, a box and a popup?
  - How do I use a custom layout?
  - What positions can the banner have?
---

# Banner Settings — Banner Layout and Layout Settings

## Where to find it

**Configuration → Banners** (`/banners`), two adjacent sections:

- **Banner Layout** — which layout template renders the banner.
- **Layout Settings** — the shape, position and second-layer behaviour.

## What it does

Banner Layout picks the *design*; Layout Settings picks the *placement*. Together they decide
what the visitor actually sees on first load and when they open the preference centre.

## Banner Layout

| Field | What it does | Notes |
|---|---|---|
| **Active Layout** | Dropdown of every system layout plus any custom layouts you own | System layouts: Classic, Minimal, Stacked, Card, Sidebar, Hero. One is marked `(Default)` |
| **Manage Layouts** | Link to `/layouts` | Where you preview, duplicate and edit layouts |

Custom layouts appear in the same dropdown, listed after the system ones. Creating them requires
the Business plan on Cloud; self-hosted is unlimited. See
Knowledgebase: Banner - Document: layouts-library.md.

## Layout Settings

| Field | What it does | Options | Default |
|---|---|---|---|
| **Banner Type** | The shape of the first layer | Banner (full-width bar) / Box (corner card) / Popup (centred modal) | Banner |
| **Display Position** | Where it sits. The options change with Banner Type | Banner: Top, Bottom · Box: Bottom Left, Bottom Right, Top Left, Top Right · Popup: Center | Bottom |
| **Preference Center Display** | How the second layer opens | Center / Sidebar / Push Down | Center |
| **Show categories on cookie notice** | Puts the category toggles on the first-layer banner, so visitors can pick without opening the preference centre | On / Off | Off |
| **Opt-out Center Display** | How the US-privacy opt-out centre opens | Center / Sidebar / Push Down | Center |

Preference Center Display and the categories toggle only appear on GDPR and GDPR+CCPA banner
types. Opt-out Center Display only appears on CCPA and GDPR+CCPA types.

## Choosing a banner type

| Type | Looks like | Good for |
|---|---|---|
| **Banner** | A bar across the top or bottom | Least intrusive. The safest default and the highest consent rates on content sites |
| **Box** | A card in one corner | Compact; leaves the page mostly visible |
| **Popup** | A centred modal, usually dimming the page | Highest visibility and consent rate, most intrusive. Check it does not read as a dark pattern under GDPR — Accept and Reject must be equally prominent |

## Preference centre display modes

| Mode | Behaviour |
|---|---|
| **Center** | Opens as a centred modal over the page |
| **Sidebar** | Slides in from the side, page stays visible |
| **Push Down** | Pushes page content down rather than overlaying it |

## How to

**Move the banner to the top** — Banner Type `Banner`, then Display Position `Top`. Save.

**Switch to a popup** — Banner Type `Popup`. Position collapses to `Center`, which is the only
option. Save.

**Let visitors choose categories without a second click** — turn on **Show categories on cookie
notice**. Best with Box or Popup; a full-width bar gets tall.

**Use a custom design** — go to `/layouts`, duplicate a system layout, edit it, then come back
and select it under **Active Layout**.

Every change here is previewable without saving: use the **Preview** button in the page header.

## Common questions

**What is the difference between "Banner Layout" and "Layout Settings"?**
Banner Layout chooses the HTML template (Classic, Minimal, Card…). Layout Settings chooses where
that template is positioned and how the second layer behaves. Changing the layout does not change
your position, colours or text.

**Can I have different layouts for desktop and mobile?**
Not as separate selections — the layouts are responsive. If you need genuinely different markup,
duplicate a layout and add your own media queries in the layout editor, or use Custom CSS.

**Which layout converts best?**
Rather than guess, run a split test: **Configuration → A/B Tests** compares two variants on live
traffic and reports uplift and confidence. Cloud only. See Knowledgebase: Growth - Document: ab-tests.md.

**The position dropdown lost my choice when I changed banner type.**
Each type has its own valid positions — a popup has only Center. Re-pick the position after
changing type.

**Where do I change the banner's colours?**
The **Color Settings** section further down the same page, with separate Light Theme and Dark
Theme tabs. See Knowledgebase: Banner - Document: banner-colors.md.

## Related

- Knowledgebase: Banner - Document: layouts-library.md — the layout library and duplication
- Knowledgebase: Banner - Document: layout-editor.md — editing custom layout HTML
- Knowledgebase: Banner - Document: banner-colors.md — colours per theme
- Knowledgebase: Banner - Document: banner-content.md — which buttons appear
