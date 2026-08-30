# Running Conzent under a Content-Security-Policy

Conzent works on pages with a strict, nonce-based CSP with zero
configuration on the Conzent side: the loader reads the nonce off its own
`<script>` tag and applies it to every script and stylesheet the CMP
injects.

## The policy

Add the Conzent server to your existing policy — nothing more:

```
Content-Security-Policy:
  script-src 'nonce-{RANDOM}' https://app.getconzent.com;
  connect-src https://app.getconzent.com;
  style-src 'nonce-{RANDOM}' 'self';
  style-src-attr 'unsafe-inline';
```

Self-hosted installations use their own origin instead of
`app.getconzent.com`.

- **script-src** — the embed tag carries your page nonce; every script the
  CMP injects (the consent bundle, restored third-party scripts after
  consent, GTM when configured) inherits it automatically.
- **connect-src** — the consent audit log, pageview beacons and the offline
  replay queue post to the Conzent server.
- **style-src** — the banner's stylesheet is injected as a nonce-carrying
  `<style>` element.
- **style-src-attr 'unsafe-inline'** — required. Banner theming (your
  colors, per-button styling) is delivered as inline `style` attributes.
  Style attributes cannot execute script; the residual risk is CSS-only.
  This is a deliberate, documented trade-off — converting the theming
  pipeline to generated classes would change every deployed banner.

## The embed

```html
<script src="https://app.getconzent.com/c/consent.js"
        data-key="YOUR_WEBSITE_KEY"
        nonce="{RANDOM}"></script>
```

That is the whole integration. The loader reads `script.nonce` (the IDL
property — under CSP the content attribute is hidden) and relays it to the
bundle via `window._cnzNonce`.

**Framework note:** some frameworks strip `nonce` attributes during
hydration. If yours does, add `data-nonce="{RANDOM}"` as a fallback — the
loader checks it second:

```html
<script src="https://app.getconzent.com/c/consent.js"
        data-key="YOUR_WEBSITE_KEY"
        nonce="{RANDOM}" data-nonce="{RANDOM}"></script>
```

## What is covered

Nonce propagation is applied at every injection point:

- the consent bundle (`script.js`) loaded by the loader
- the banner stylesheet (`<style id="conzentCss">`)
- third-party scripts restored after consent (both blocker paths)
- whitelisted scripts released before consent
- Google Tag Manager injection (when configured)
- the Amazon ad consent library (when enabled)

## Proving it

The repository ships a strict-CSP fixture: `docker/testsite/csp.html` is
served with the policy above (static nonce `cnztest123` — fixtures may;
production nonces must be random per request). The CI e2e job loads it and
fails on ANY `securitypolicyviolation` event:

```
CONZENT_E2E=1 node --test tests/js/csp-nonce.test.mjs
```
