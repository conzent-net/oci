---
id: sites.languages
title: Languages — which languages the banner supports
area: Sites
knowledgebase: Sites
url: /languages
menu_path: Configuration > Languages
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [languages, multilingual, translation, default-language, i18n, locale, banner-text]
related: [banner.translations, sites.create, banner.advanced, account.billing]
source_files:
  - templates/pages/languages/index.html.twig
  - src/Site/Controller/LanguageListHandler.php
  - src/Site/Controller/LanguageAddHandler.php
  - src/Site/Controller/LanguageRemoveHandler.php
  - src/Site/Controller/LanguageDefaultHandler.php
questions:
  - How do I add a language to my banner?
  - How does Conzent decide which language to show?
  - How do I change the default banner language?
  - How do I remove a language?
  - How many languages can I have on my plan?
  - Does Conzent translate the banner automatically?
  - Where do I edit the text for each language?
---

# Languages — which languages the banner supports

## Where to find it

**Configuration → Languages** in the sidebar. URL: `/languages`. Scoped to the currently
selected site — the header shows which domain you are editing.

## What it does

Controls which languages this site's banner is available in. Adding a language creates a
translatable copy of every banner text field; the banner then picks the visitor's language
automatically and falls back to the default.

## Columns

| Column | What it shows |
|---|---|
| Language | The language name |
| Code | Its two-letter code, e.g. `da`, `de` |
| Default | A **Default** badge, or a **Set default** button |
| Banner Content | **Edit Content**, which opens the banner translations editor |
| Actions | Remove (hidden for the default language) |

## Actions

| Action | What it does |
|---|---|
| **Add Language** | Opens a modal with a dropdown of every language not already added |
| **Set default** | Makes that language the fallback for visitors whose language you do not support |
| Remove (trash) | Removes the language **and deletes all its banner translations**. Confirmation required. The default language cannot be removed |
| **Edit Content** | Jumps to **Banners → Banner Content & Translations** for editing |

## How language is chosen for a visitor

1. The page's own language (`<html lang>`, hreflang, meta), if you support it.
2. Otherwise the visitor's browser language, if you support it. Norwegian browsers reporting
   `no` are matched to `nb` automatically.
3. Otherwise the site's **default** language.

To broaden coverage without adding every language by hand, turn on **Load All Languages** in
**Banners → Advanced Settings** — the banner then matches against the full pre-translated set.

Languages no longer cost page weight: only the site's default language ships inside the consent
script, and any other language is fetched as a small (~9 KB) side file, only by visitors who
need it. If that fetch ever fails, the banner falls back to the default language rather than
showing broken text.

## Plan limits

On Cloud, the entry plan allows **2 languages** per site. At the cap, **Add Language** is
replaced by **Upgrade**, with the limit shown beneath. The Agencies and E-commerce plan and all
self-hosted installs are unlimited.

## How to add a language

1. **Configuration → Languages**, with the right site selected.
2. **Add Language** → pick from the dropdown → **Add Language**.
3. Go to **Banners → Banner Content & Translations**, select the new language tab, and either:
   - **Copy from Default** and translate by hand, or
   - **Auto Translate** to have it translated by AI (only shown when the server has an
     OpenRouter key configured).
4. **Save Content**.

## How to change the default

Click **Set default** on the language's row. The old default becomes an ordinary language and
can then be removed if you want.

## Common questions

**Does Conzent translate automatically?**
Two different things. Conzent ships pre-translated banner copy for **86 languages** — including
category names and descriptions — used when **Load All Languages** is on. Separately, the
**Auto Translate** button in the banner content editor uses AI to translate *your custom* text
into a selected language — available only when the server has `OPENROUTER_API_KEY` set.

**Do many languages slow the banner down?**
No. Visitors download only the language they actually see — the default language is built into
the script and every other language loads as a small side file on demand. Adding languages does
not grow the script visitors download.

**I removed a language by accident.**
The translations for it are gone. Re-add the language, then use **Copy from Default** or
**Auto Translate** to rebuild the text.

**Why can't I remove my only language?**
The default cannot be removed. Set another language as default first.

**Does adding a language change the cookie descriptions too?**
Cookie category names and descriptions are translated separately under
**Compliance → Cookies → Categories**. Global cookie descriptions ship pre-translated.

**Is this per site?**
Yes. Each site has its own language set. The site selector in other pages' headers controls
which site you are editing; this page shows the domain as a tag in its header.

## Related

- Knowledgebase: Banner - Document: banner-translations.md — editing the text per language
- Knowledgebase: Banner - Document: banner-advanced.md — the Load All Languages switch
- Knowledgebase: Cookies - Document: categories.md — category translations
