---
id: banner.accessibility
title: Accessibility — WCAG 2.1 AA, keyboard and screen readers
area: Banner
knowledgebase: Banner
url: /banners
menu_path: Configuration > Banners
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [accessibility, wcag, a11y, keyboard, screen-reader, focus, contrast, aria, european-accessibility-act, axe]
related: [banner.colors, banner.layout, banner.custom-css]
source_files:
  - resources/consent/layouts/gdpr/base.html.twig
  - resources/consent/css/banner.css
  - resources/consent/js/conzent.script.js
  - src/Banner/Service/ScriptGenerationService.php
questions:
  - Is the banner WCAG compliant?
  - Is the banner accessible?
  - Can the banner be used with a keyboard only?
  - Does the banner work with screen readers?
  - Does the banner meet the European Accessibility Act?
  - Can I close the banner with the Escape key?
  - How is the accessibility claim verified?
---

# Accessibility — WCAG 2.1 AA, keyboard and screen readers

## Where to find it

There is nothing to configure — accessibility is built into every banner layout. The one
related control is the contrast warning in **Configuration → Banners → Color Settings**
(`/banners`).

## What it does

The consent banner and preference center meet **WCAG 2.1 AA**, verified with automated
axe-core checks against every built-in layout — and re-verified continuously, not claimed
once. This matters for the European Accessibility Act and for any customer whose own site is
audited: the consent banner is part of the page being audited.

## What is covered

| Area | Behaviour |
|---|---|
| **Screen readers** | The banner announces itself as a named dialog with its title and message. The preference center is a proper modal dialog. Category toggles announce as switches with the category name; accordion buttons announce their expanded state |
| **Keyboard** | Everything is operable without a mouse. Focus moves to the banner when it appears (announcing the whole notice, not just one button). Tab reaches every control; the preference center traps focus while open and returns it where it was on close |
| **Escape key** | In the preference center, Escape closes it. On the banner, Escape activates the close control **only if one is enabled** in Content Settings — dismissing never records consent, it just leaves the choice ungiven with blocking still active |
| **Focus visibility** | Keyboard focus shows a visible outline in the control's own text colour; mouse clicks stay outline-free |
| **Contrast** | Default colours pass AA (4.5:1 text, 3:1 for the toggle). Custom colours are checked live by the contrast warning in Color Settings |
| **IAB TCF view** | The Cookie Categories / Purposes / Vendors tabs are real accessible tabs, operable with arrow keys |

## Neutrality by design

Initial keyboard/screen-reader focus lands on the banner container — the visitor hears the
whole notice first. It deliberately does **not** land on the Accept button: announcing only
the affirmative option would nudge the choice, which regulators treat as a dark pattern.

## How the claim is verified

Every built-in layout is scanned with **axe-core against the WCAG 2.1 AA ruleset** — the same
tool auditors use — on the real rendered banner and preference center, and the checks run
automatically on every change to the product. If your auditor wants to verify, they can run
axe on any page with the banner open; it should report zero violations for the banner.

## What can break it

- **Custom colours** below the contrast minimum — watch the warning in Color Settings.
- **Custom layouts** created before the accessibility work carry their original markup. Rebuild
  a custom layout by duplicating a current built-in layout to inherit the accessible structure.
- **Custom CSS** that hides focus outlines or removes controls.

## Common questions

**Is the banner WCAG 2.1 AA compliant?**
Yes, on every built-in layout, verified with automated axe-core checks that run continuously —
not a one-time statement.

**Can visitors use it keyboard-only?**
Yes — full operability: focus management, a focus trap in the preference center, Escape
handling, arrow-key tabs in the TCF view, and visible focus outlines throughout.

**Does Escape dismiss the banner without consent?**
Escape maps to the configured close control, if you enabled one. Dismissal is never recorded
as consent — blocking stays active and the visitor can decide later. With no close control
configured, Escape does nothing on the banner (the visitor can still Tab past it freely).

**We use a custom layout — is it covered?**
Custom layouts keep the markup they were saved with. If yours predates the accessibility work,
duplicate a current built-in layout and re-apply your changes to inherit the accessible
structure.

## Related

- Knowledgebase: Banner - Document: banner-colors.md — the contrast warning
- Knowledgebase: Banner - Document: banner-layout.md — the built-in layouts
- Knowledgebase: Banner - Document: banner-custom-css.md — styling without breaking focus
