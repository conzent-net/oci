---
id: banner.custom-css
title: Banner Settings — Custom CSS
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Custom CSS
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [css, custom-css, styling, fonts, responsive, override, design, mobile]
related: [banner.colors, banner.layout-editor, banner.layout]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - How do I add custom CSS to the banner?
  - How do I change the banner font?
  - How do I make the banner look different on mobile?
  - My CSS is not applying to the banner
  - Can I add rounded corners or a shadow to the banner?
  - How do I hide an element on the banner?
---

# Banner Settings — Custom CSS

## Where to find it

**Configuration → Banners** (`/banners`), the **Custom CSS** section at the bottom of the page.

## What it does

A code textarea whose contents are injected into the consent banner. Use it for anything the
colour pickers cannot express: fonts, spacing, borders, radii, shadows, responsive rules, or
hiding an element outright.

## Fields

| Field | What it does | Notes |
|---|---|---|
| **Custom CSS** | Monospace textarea, 8 rows. Plain CSS, no preprocessor | Injected into the banner iframe. Saved with **Save All Changes** |

## What you can do with it

| Goal | Approach |
|---|---|
| Custom font | Set `font-family` on the banner root. The font must already be loaded by your page — an `@import` inside the banner may be blocked before consent |
| Rounded corners, shadows | Standard `border-radius` / `box-shadow` on the banner container |
| Different layout on mobile | Standard `@media (max-width: …)` queries |
| Hide an element | `display: none` on its class |
| Tighter or looser spacing | `padding` / `margin` overrides |

## How to find the right selector

1. Open your site with the banner showing.
2. Right-click the element → Inspect.
3. Read the class off the element. Conzent's own classes are prefixed `cnz-`.
4. Write the rule, paste it into Custom CSS, **Save All Changes**, then
   **Advanced Settings → Purge & Regenerate**.
5. Hard-refresh your site.

Use the **Preview** button in the page header to iterate without touching your live site.

## Common questions

**My CSS is not applying.**
In order: save the page; purge with **Advanced Settings → Purge & Regenerate**; hard-refresh
(Ctrl/Cmd+Shift+R); purge any CDN. If it still loses, your selector is being outranked — raise
specificity (`.cnz-banner .cnz-btn-accept { … }`) rather than reaching for `!important`.

**Should I use `!important`?**
As a last resort. It makes later changes harder to reason about, and layout templates already
carry their own styles. Prefer a more specific selector.

**Can I load a Google Font here?**
An `@import` or a font request from inside the banner is a third-party request that may itself be
subject to consent — and under GDPR, Google Fonts loaded from Google's servers has been ruled
problematic in some EU jurisdictions. Self-host the font, load it from your own page, and just
reference the family name here.

**Custom CSS vs the layout editor — which do I want?**
Custom CSS restyles the existing markup and is per site. The layout editor changes the markup
itself, produces a reusable custom layout, and needs the Business plan on Cloud. Restyling →
Custom CSS. Restructuring → layout editor.

**Does Custom CSS carry over to my other sites?**
No — per site, like every banner setting.

**Can I break the banner with this?**
Yes. Hiding the Accept or Reject button, or making one visually weaker than the other, breaks
consent and is treated as a dark pattern under GDPR. Preview before saving.

## Related

- Knowledgebase: Banner - Document: banner-colors.md — colours without CSS
- Knowledgebase: Banner - Document: layout-editor.md — changing the markup
- Knowledgebase: Banner - Document: banner-advanced.md — purging after a change
