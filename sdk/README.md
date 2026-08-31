# @getconzent/consent

[![npm version](https://img.shields.io/npm/v/%40getconzent%2Fconsent)](https://www.npmjs.com/package/@getconzent/consent)

Typed loader, React hook and Next.js component for
[Conzent](https://getconzent.com) — the open consent management platform.

Zero dependencies. React and Next.js are optional peer dependencies used
only by their respective entry points.

## Install

```sh
npm install @getconzent/consent
```

## Plain JavaScript / TypeScript

```ts
import { loadConzent } from '@getconzent/consent';

const conzent = await loadConzent({
  key: 'YOUR_WEBSITE_KEY',
  // server: 'https://cmp.your-domain.com',   // self-hosted
  // nonce: cspNonce,                         // strict CSP pages
});

conzent.isAccepted();
conzent.getConsent().marketing;
conzent.on('consent_saved', ({ action, categories }) => {
  if (categories.includes('analytics')) startAnalytics();
});
```

`loadConzent()` injects the same one-tag embed the copy-paste install uses
(idempotently — an existing tag is reused) and resolves once the
`window.Conzent` API is up.

## React

```tsx
import { useConsent } from '@getconzent/consent/react';

function AnalyticsGate({ children }) {
  const { isGranted, openPreferences } = useConsent();
  if (!isGranted('analytics')) {
    return <button onClick={openPreferences}>Enable analytics cookies</button>;
  }
  return children;
}
```

The hook reads the stable consent cookie and re-renders on every consent
save — it works even before the CMP bundle finishes loading.

## Next.js

```tsx
// app/layout.tsx (app router) or pages/_document.tsx (pages router)
import { ConzentScript } from '@getconzent/consent/next';

<ConzentScript siteKey="YOUR_WEBSITE_KEY" />
```

`beforeInteractive` keeps the blocking guarantees of the copy-paste
install: the IAB TCF stub, the Google Consent Mode defaults and the
pre-consent blocker run before any other tag.

**Prefer `<ConzentScript>` (or the plain head tag) over `loadConzent()`**
when you can: a runtime install runs after hydration, so very early
third-party tags could fire before the blocker on content-heavy pages.

## Server-side

The consent state is a stable first-party cookie contract:

```ts
import { readConsentCookie } from '@getconzent/consent';

const granted = readConsentCookie(req.headers.cookie); // string[] | null
```

## Docs

- JavaScript API: `docs/js-api.md` in the [repository](https://github.com/conzent-net/oci)
- Strict CSP: `docs/csp.md`
- The embed contract: `docs/embed-snippet.md`
