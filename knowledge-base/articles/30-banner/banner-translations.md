---
id: banner.translations
title: Banner Settings — Banner Content and Translations
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners > Banner Content & Translations
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [text, wording, translations, languages, auto-translate, copy-from-default, banner-title, message, button-text]
related: [sites.languages, banner.content, banner.advanced]
source_files:
  - templates/pages/banners/index.html.twig
  - templates/pages/banners/content.html.twig
  - src/Banner/Controller/BannerContentHandler.php
  - src/Banner/Controller/BannerContentUpdateHandler.php
  - src/Banner/Controller/TranslateContentHandler.php
questions:
  - How do I change the text on my cookie banner?
  - How do I change the Accept button label?
  - How do I translate the banner into another language?
  - What is the Auto Translate button?
  - Why is Auto Translate missing?
  - How do I copy text from my default language?
  - Where do I edit the preference center text?
  - The banner shows English for German visitors
---

# Banner Settings — Banner Content and Translations

## Where to find it

**Configuration → Banners** (`/banners`), the **Banner Content & Translations** section. There is
also a standalone page at `/banners/content` with the same editor.

## What it does

Every piece of text the banner shows — titles, body copy, button labels, category descriptions,
preference centre wording — for every language the site supports. One tab per language, with the
default language marked.

## Layout of the editor

**Language tabs** across the top, one per language configured for the site under
**Configuration → Languages**. The default carries a green **Default** badge.

**Field groups** below, one block per category of text (cookie notice, preference centre, and so
on). Each field is either a single-line input or a textarea, labelled with the field name and
showing the shipped default as placeholder text.

When you are editing a **non-default** language, the editor splits into two columns: the
left shows the default language's value as read-only reference, the right is your editable
translation. That side-by-side only appears on non-default tabs.

## Controls

| Control | What it does | Availability |
|---|---|---|
| Language tabs | Switch which language you are editing | One per configured language |
| **Copy from Default** | Fills every empty field with the default language's text, ready to translate by hand | Non-default tabs only |
| **Auto Translate** | Translates the default language's content into this language using AI, then saves | Non-default tabs only, and only when the server has `OPENROUTER_API_KEY` set |
| **Save Content** | Saves the current language's fields | Always |

**Save Content** is separate from the page's **Save All Changes** — translations save on their
own button.

## Typical field groups

The exact set is driven by the banner's field configuration, but it covers:

- Cookie notice title and message
- Accept All / Reject All / Customize / Save My Preferences button labels
- Preference centre title and overview text
- Category names and descriptions (Necessary, Functional, Analytics, Marketing)
- Opt-out centre text on CCPA banner types
- Blocked-content placeholder text

Leaving a field empty falls back to the shipped default shown in the placeholder — you do not
have to fill in everything.

## How to translate the banner

1. Add the language first under **Configuration → Languages**. It will not appear as a tab
   otherwise.
2. Open **Banners → Banner Content & Translations** and click the new language's tab.
3. Either:
   - **Auto Translate** — one click, translates everything and saves. Review the result; legal
     wording benefits from a human check.
   - **Copy from Default** — copies the English (or whichever default) text in so you can
     translate it yourself.
4. **Save Content**.
5. Repeat per language.

## Common questions

**Where is the Auto Translate button?**
It only appears on non-default language tabs, and only when the server has an OpenRouter API key
configured. Self-hosters need to set `OPENROUTER_API_KEY` in `.env`.

**Is Auto Translate good enough for legal text?**
It produces usable copy quickly, but consent wording is legally operative. Have a native speaker
review it, particularly the descriptions of what each cookie category does.

**German visitors still see English.**
Three checks: German is added under **Configuration → Languages**; its tab has saved content;
and the banner script has been regenerated (**Advanced Settings → Purge & Regenerate**). If you
want automatic coverage for languages you have not added by hand, turn on **Load All Languages**
in Advanced Settings.

**How do I change just the Accept button label?**
Find the accept-button field in the field groups, edit it, **Save Content**. Whether the button
exists at all is controlled in **Content Settings**.

**My changes save but the site shows old text.**
The generated script is cached. Use **Purge & Regenerate**, then hard-refresh.

**Can I use HTML in these fields?**
The message fields are rich-text and support basic markup; short label fields are plain text.
For structural changes, edit the layout template instead.

**Do translations copy between sites?**
No. Content is per site. Duplicating a layout copies markup, not text.

## Related

- Knowledgebase: Sites - Document: languages.md — adding languages first
- Knowledgebase: Banner - Document: banner-content.md — which elements exist
- Knowledgebase: Banner - Document: banner-advanced.md — Load All Languages, cache purge
