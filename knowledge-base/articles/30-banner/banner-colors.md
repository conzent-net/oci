---
id: banner.colors
title: Banner Settings — Color Settings
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Color Settings
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [colors, colours, theme, dark-mode, light-mode, branding, buttons, toggle, styling, customize, contrast, wcag, accessibility]
related: [banner.custom-css, banner.layout, banner.layout-editor]
source_files:
  - templates/pages/banners/index.html.twig
  - src/Banner/Controller/BannerUpdateHandler.php
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - How do I change the banner colours?
  - How do I match the banner to my brand?
  - Does the banner have a dark mode?
  - How do I change the Accept button colour?
  - Why did my colour change not appear?
  - What colour does the toggle switch use?
  - How do I style the blocked content placeholder?
  - What is the contrast warning in colour settings?
  - Are the default banner colours accessible?
---

# Banner Settings — Color Settings

## Where to find it

**Configuration → Banners** (`/banners`), the **Color Settings** section.

## What it does

Sets every colour in the banner, separately for **Light Theme** and **Dark Theme**. The banner
picks a theme from the visitor's system preference, so both need to look right.

Two tabs at the top of the section switch which theme you are editing. Colours you never touch
inherit the layout template's defaults — only values you actually set are stored. The default
palette passes WCAG AA contrast out of the box.

A **contrast check** runs live as you pick colours: any text/background pair below the WCAG AA
minimum of 4.5:1 is listed in a warning box with its measured ratio. It warns — it never blocks
saving. Your brand colours are your call; the warning makes the accessibility trade-off visible.

## Colour groups

Each group is a row of colour pickers. Which groups appear depends on the site's banner type
(GDPR, CCPA, or both).

### Always shown

| Group | Fields |
|---|---|
| **Cookie Notice** | Background, Border, Title, Message |
| **Revisit Consent Button** | Background |
| **Blocked Content Alt Text** | Background, Border, Text — the placeholder shown where a blocked embed (e.g. a YouTube video) would be |

### GDPR banner types

| Group | Fields |
|---|---|
| **"Accept All" Button** | Background, Border, Text |
| **"Reject All" Button** | Background, Border, Text |
| **"Customize" Button** | Background, Border, Text |
| **Preference Center** | Toggle Enabled, Toggle Disabled |
| **"Save My Preferences" Button** | Background, Border, Text |

### CCPA banner types

| Group | Fields |
|---|---|
| **"Do Not Sell" Link** | Text |
| **Opt-out Center** | Checkbox Enabled, Checkbox Disabled |
| **Cancel Button** | Background, Border, Text |
| **Save Button** | Background, Border, Text |

Every picker is a standard colour input — click the swatch to open your OS colour picker, or
type a hex value.

## How to match your brand

1. Pick the **Light Theme** tab.
2. Set **Cookie Notice → Background** to your page background and **Title** / **Message** to your
   body text colour.
3. Set **"Accept All" Button → Background** to your brand's primary colour, with **Text** at a
   contrast that passes accessibility (aim for 4.5:1).
4. Give **"Reject All"** equal visual weight — a same-size outlined or secondary-filled button.
   Making Reject visibly weaker than Accept is a dark pattern under GDPR and regulators have
   acted on it.
5. Switch to **Dark Theme** and repeat. Dark mode is not derived automatically.
6. Use **Preview** in the page header to check both, then **Save All Changes**.

## Common questions

**What is the contrast warning box?**
A live WCAG check on your colour pairs (each button's text vs background, banner title and
message vs banner background). Pairs below 4.5:1 are listed with their measured ratio. It is a
warning only — saving is never blocked — but colours that fail it are hard to read for
low-vision visitors and fail WCAG 2.1 AA audits.

**Are the default colours accessible?**
Yes. The default palette meets WCAG AA contrast (4.5:1 for text, 3:1 for the toggle switch),
and the banner itself passes automated WCAG 2.1 AA checks. If you change colours, keep an eye
on the contrast warning to stay there.

**Is this the same as the app's dark mode toggle?**
No. The moon/sun icon in the top bar changes the *admin app* theme. These tabs change the
*banner your visitors see*.

**Why did my colour change not show up on my site?**
Save first, then purge: **Advanced Settings → Purge & Regenerate**, and clear your own browser
and CDN cache. The generated script is cached aggressively by content hash. See
Knowledgebase: Banner - Document: banner-advanced.md.

**Some elements ignore my colour.**
Custom layouts can hardcode styles. Check what the layout template does in the layout editor, or
override it with **Custom CSS**.

**Can I use gradients, shadows or fonts here?**
No — these are flat colour pickers. For anything else use **Custom CSS**, which is injected into
the banner. See Knowledgebase: Banner - Document: banner-custom-css.md.

**What is "Blocked Content Alt Text"?**
When a YouTube embed or similar third-party iframe is blocked before consent, Conzent shows a
placeholder in its place explaining why and offering to accept. These colours style that
placeholder.

**Do colours apply to all my sites?**
No — per site, like every banner setting. Check the site selector in the page header.

## Related

- Knowledgebase: Banner - Document: banner-custom-css.md — anything the pickers cannot do
- Knowledgebase: Banner - Document: banner-layout.md — shape and position
- Knowledgebase: Banner - Document: banner-advanced.md — purging the cache after a change
- Knowledgebase: Banner - Document: layout-editor.md — editing the template itself
