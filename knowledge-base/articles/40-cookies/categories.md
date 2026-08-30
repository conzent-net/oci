---
id: cookies.categories
title: Cookie Categories
area: Cookies
knowledgebase: Cookies
url: /categories
menu_path: Compliance > Cookies > Categories
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [categories, necessary, functional, analytics, marketing, default-consent, sort-order, category-description]
related: [cookies.list, banner.translations, banner.content]
source_files:
  - templates/pages/categories/index.html.twig
  - src/Cookie/Controller/CategoryListHandler.php
  - src/Cookie/Controller/CategoryAddHandler.php
  - src/Cookie/Controller/CategoryUpdateHandler.php
  - src/Cookie/Controller/CategoryDeleteHandler.php
questions:
  - What are the cookie categories?
  - Can I add my own cookie category?
  - How do I change a category description?
  - Why can't I delete the Necessary category?
  - What does "Default: Ask user" mean?
  - How do I change the order categories appear in the banner?
  - What text do visitors see for each category?
---

# Cookie Categories

## Where to find it

`/categories`, reached from **Compliance → Cookies**. Scoped to the site chosen in the header
dropdown.

## What it does

Categories are the buckets visitors toggle in the preference centre. Each one groups cookies by
purpose, carries a description shown to visitors, and decides whether its cookies load before
consent.

## Standard categories

| Category | Purpose | Blocked before consent | Deletable |
|---|---|---|---|
| **Necessary** | Required for the site to work — session, security, load balancing | No, always allowed | No |
| **Functional** | Remembers preferences: language, region, layout | Yes | Yes |
| **Analytics** | Traffic and behaviour measurement | Yes | Yes |
| **Marketing** | Advertising and cross-site tracking | Yes | Yes |

**Unclassified** is not a real category — it is where cookies sit until someone assigns them. It
is blocked before consent and cannot be chosen as a target.

## What each card shows

| Element | Meaning |
|---|---|
| Category badge | Name with a colour and icon per type |
| Cookie count | How many cookies are in it for this site |
| Description | The text shown to visitors, truncated to 150 characters on the card |
| Sort order | Position in the preference centre |
| Default badge | `Default: Accepted`, `Default: Rejected`, or `Default: Ask user` |
| Edit / Delete | Delete is hidden for Necessary |

## Add Category modal

| Field | Required | What it does |
|---|---|---|
| **Global Category** | No | Pick from Conzent's global category list. Selecting one prefills the name and description |
| **Display Name** | Yes | What visitors see in the preference centre |
| **Description** | No | Explains to visitors what this category is used for |

## Edit Category modal

Same Display Name and Description fields, prefilled.

## Default consent

The badge on each card reflects how the category behaves before the visitor chooses:

| Value | Meaning |
|---|---|
| Accepted | On by default. Only lawful for Necessary under an opt-in framework |
| Rejected | Off by default |
| Ask user | No pre-selection — the visitor decides |

Under GDPR, pre-ticked non-essential categories are invalid consent. Keep everything except
Necessary off or unset.

## Translating category text

Category names and descriptions appear in the banner, so they need translating for every
language the site supports. Do that in **Banners → Banner Content & Translations**, where each
language tab includes the category fields. Adding a category here creates the fields; the
translations are filled in there.

## How to

**Add a category** — **Add Category** → optionally pick a Global Category to prefill → set the
Display Name → Save. Then classify cookies into it from **Compliance → Cookies**.

**Change what visitors read** — Edit the category and rewrite the Description. Then update the
per-language text in Banner Content & Translations for every other language.

**Delete a category** — Delete on its card. Cookies that were in it move to Unclassified, which
is blocked before consent, so reclassify them.

## Common questions

**Why can't I delete Necessary?**
It is the only category that is always allowed, and cookies must have somewhere to sit that does
not require consent. Every consent framework assumes it exists.

**Should I create custom categories?**
Usually not. The four standard ones map to how regulators and the IAB think about purposes, and
more categories mean more decisions per visitor and lower consent rates. Add one only when you
genuinely have a purpose that does not fit — for example "Social Media" if you want it separated
from Marketing.

**Are categories shared across my sites?**
No. They are per site. The **Global Category** dropdown lets you start from Conzent's shared
definitions so wording stays consistent, but the resulting category belongs to this site.

**Is the sort order editable?**
The order is shown on each card. Categories render in the preference centre in that order, with
Necessary conventionally first.

**A cookie I classified is showing under the wrong category.**
Reclassifying an already-classified cookie is a request to Conzent, not an immediate edit — see
Knowledgebase: Cookies - Document: cookies-list.md.

## Related

- Knowledgebase: Cookies - Document: cookies-list.md — classifying cookies into categories
- Knowledgebase: Banner - Document: banner-translations.md — per-language category text
- Knowledgebase: Banner - Document: banner-layout.md — showing categories on the first layer
