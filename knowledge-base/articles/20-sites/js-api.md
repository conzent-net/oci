---
id: sites.js-api
title: JavaScript API — reading consent from your own code
area: Sites
knowledgebase: Sites
url: /
menu_path: (developer integration — no in-app page)
edition: [cloud, self-hosted]
audience: [customer, agency, admin]
plan: any
tags: [javascript, api, window.conzent, events, consent-state, cookie-contract, sdk, npm, react, nextjs, developer]
related: [sites.install-script, integrations.gtm]
source_files:
  - resources/consent/js/conzent.script.js
  - docs/js-api.md
  - public/c/consent.js
questions:
  - How do I check if a visitor gave consent from my own JavaScript?
  - How do I run code only after the visitor accepts analytics?
  - Is there an event when consent changes?
  - Can I read the consent cookie server-side?
  - Is there an npm package or React integration?
  - How do I reopen the banner from my own button?
---

# JavaScript API — reading consent from your own code

Once the consent script is installed, your own code can read and react to
consent through `window.Conzent` — a small, frozen, documented API.

## Check consent

```js
window.Conzent.isAccepted();                    // has the visitor chosen?
window.Conzent.isPreferenceAccepted('analytics'); // is one category granted?
window.Conzent.getPreferences();                // ["necessary","analytics",...]
window.Conzent.getConsent();                    // full payload, all categories
```

## React to consent changes

```js
window.Conzent.on('consent_saved', function (detail) {
  // detail.action: "accept_all" | "reject_all" | "save_preferences"
  // detail.categories: granted slugs
  if (detail.categories.includes('analytics')) startAnalytics();
});
```

Other events: `banner_shown`, `preference_center_opened`,
`preference_center_closed`. Unsubscribe with `Conzent.off(name, cb)`.

## Reopen the banner or preference center

```js
window.Conzent.reinit();        // reopen the banner
window.revisitCnzConsent();     // open the preference center
```

## Server-side: the cookie contract

Two first-party cookies are a stable contract you may read on your server:
`conzentConsent` (`"true"` once a choice exists) and `conzentConsentPrefs`
(URL-encoded JSON array of granted category slugs).

## npm package

`@getconzent/consent` wraps all of this with TypeScript types, a
promise-based loader, a React hook (`useConsent`) and a Next.js component
(`<ConzentScript>`).

## Strict Content-Security-Policy

Pages with a nonce-based CSP add `nonce` (and `data-nonce` for frameworks
that strip it) to the embed tag; Conzent relays it to everything it
injects. The policy recipe lives in the repository at `docs/csp.md`.

The full API reference lives in the repository at `docs/js-api.md`.
