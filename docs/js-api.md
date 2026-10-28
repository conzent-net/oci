# Conzent JavaScript API

`window.Conzent` is the supported integration surface for reading consent
state and reacting to consent changes from your own code. It is a frozen
object, available as soon as the consent bundle has loaded.

The lowercase `window.conzent` namespace is **internal** — do not touch it.

## Waiting for the API

The bundle loads asynchronously behind the one-tag embed. Either use the
`@getconzent/consent` npm package (`loadConzent()` resolves when the API is
ready), or listen for the load event:

```js
if (window.Conzent) {
  ready(window.Conzent);
} else {
  document.addEventListener('conzentck_cookie_banner_load', function () {
    ready(window.Conzent);
  });
}
```

## Methods

| Method | Returns | Description |
|---|---|---|
| `Conzent.isAccepted()` | `boolean` | Whether the visitor has made a consent choice. |
| `Conzent.getPreferences()` | `string[] \| null` | Granted category slugs (e.g. `["necessary","analytics"]`), `null` before a choice. |
| `Conzent.isPreferenceAccepted(slug)` | `boolean` | Whether one category is granted. |
| `Conzent.getConsent()` | `object` | Full consent payload — see below. |
| `Conzent.reinit()` | — | Reopens the consent banner. |
| `Conzent.on(event, cb)` | — | Subscribe to a consent event. `cb(detail, event)`. |
| `Conzent.off(event, cb)` | — | Unsubscribe the exact callback passed to `on`. |
| `Conzent.version` | `string` | Bundle version. |

`window.revisitCnzConsent()` (long-standing global) reopens the preference
center and remains supported.

### getConsent()

```js
{
  necessary: true,
  functional: false,
  analytics: true,
  marketing: false,       // documented name
  advertisement: false,   // deprecated alias of marketing — kept for
                          // deployed integrations, always identical
  preferences: false,
  performance: false,
  unclassified: false,
  meta: "revoke"          // Meta pixel state: "grant" | "revoke"
}
```

## Events

Subscribe via `Conzent.on(name, cb)` or
`document.addEventListener('conzent:' + name, e => e.detail)`.

| Event | Detail | Fires when |
|---|---|---|
| `banner_shown` | `{}` | The consent banner becomes visible. |
| `consent_saved` | `{action, categories}` | The visitor saves a choice. `action` is `accept_all`, `reject_all` or `save_preferences`; `categories` is the granted slug array. |
| `preference_center_opened` | `{}` | The preference center opens. |
| `preference_center_closed` | `{}` | The preference center closes. |

Legacy events keep firing and stay supported:

| Event | Detail |
|---|---|
| `conzentck_cookie_banner_load` | `getConsent()` payload, on load when consent exists |
| `conzentck_consent_update` | `getConsent()` payload, on every consent save |

## Cookie contract — STABLE

These two first-party cookies are a stable, documented contract. Server-side
code (and the `@getconzent/consent` SDK) may read them; changes follow a
deprecation policy, never a silent break.

| Cookie | Value | Lifetime |
|---|---|---|
| `conzentConsent` | the string `"true"` once any consent choice exists | consent expiration setting (default 12 months) |
| `conzentConsentPrefs` | `encodeURIComponent(JSON.stringify([...slugs]))` — the granted category slugs | same |

Example server-side read (PHP):

```php
$granted = isset($_COOKIE['conzentConsentPrefs'])
    ? json_decode(urldecode($_COOKIE['conzentConsentPrefs']), true)
    : [];
$analyticsAllowed = in_array('analytics', $granted ?? [], true);
```

## IAB TCF

On TCF-enabled sites the standard `__tcfapi` command surface is available
per the IAB spec — use it for vendor-level signals; `window.Conzent` covers
the category level.

## See also

- `docs/embed-snippet.md` — the one-tag install (canonical source)
- `docs/csp.md` — running under a strict Content-Security-Policy
- `@getconzent/consent` on npm — typed loader + React/Next.js bindings
