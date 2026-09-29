# Conzent embed snippet — canonical source

This file is the **source of truth** for the Conzent install snippet. Every
integration (in-app snippet generators, CMS plugins, docs, test fixtures) must
mirror it. When it changes here, update the mirrors listed at the bottom.

## The snippet

```html
<script src="{SERVER}/c/consent.js" data-key="{KEY}"></script>
```

One tag. Replace `{SERVER}` with the Conzent server URL (e.g.
`https://app.getconzent.com`, or your own domain on OCI self-hosted) and `{KEY}`
with the site's website key.

Place it **as early as possible in `<head>`** — before any Google tag, ad tag,
analytics snippet, or other third-party script.

Everything is handled inside `/c/consent.js`. Do not add a second tag.

## What the one tag does, in order

All of this runs synchronously, before the browser reaches the next tag:

1. **Installs the IAB TCF stub** (`__tcfapi`) and queues vendor calls until the
   real CMP takes over.
2. **Registers the Google Consent Mode defaults** (everything `denied` except
   `security_storage`, with `wait_for_update: 500`).
3. **Starts the pre-consent blocker** — the cookie-name interceptor and the
   MutationObserver that neutralises third-party scripts and iframes.

Then asynchronously: fetches `version.json`, and loads the site's `script.js`,
which replaces the TCF stub with the full CMP.

## Why there is no inline stub any more

Earlier versions of this snippet carried an inline Google Consent Mode stub
alongside the loader. That existed for one reason: the loader was `async`, so its
own `consent default` push could land *after* a Google tag had initialised, and
Google silently discards a late default — it shows as neither a default nor an
update in Tag Assistant, and the tag behaves as if consent had been granted.

The loader is parser-blocking now, so its own push (`_g("consent","default",…)`
in `public/c/consent.js`) always wins that race. The inline stub became
redundant, and it was also strictly *worse* than the loader's push:

- The stub hardcoded `dataLayer`. The loader honours `data-dl`, so it reaches a
  renamed GTM dataLayer that a static snippet cannot know about.
- Publishers on hosted platforms routinely had it mangled. Blogger, for one,
  HTML-escapes quotes inside its gadget editor, turning `g('consent','default',…)`
  into `g(&#39;consent&#39;,…)` — a syntax error, so the defaults never ran at all
  and the site was silently non-compliant.

Neither failure mode is possible when the code lives in a file we serve.

The loader deliberately does **not** define a global `gtag` function. Google's own
snippet does, but defining it risks clobbering a tag helper the site defines
itself. Pushing the `arguments` object onto `dataLayer` is all Consent Mode needs.

## Why the loader is not `async`

The loader carries the IAB TCF stub on its first line. Vendors — GPT, Prebid, ad
exchanges — probe for `__tcfapi` the moment they initialise and do not retry: GPT
logs *"An IAB TCF signal was not received"* and drops to non-personalised ads. The
stub has to be in place before any ad tag runs.

`async` makes that a race between two third-party downloads — exactly the kind of
intermittent failure that fails a CMP certification audit. A blocking tag in
`<head>` makes the ordering deterministic, and is what certified CMPs do.

Do not add `async` or `defer` back. Both reorder the loader behind the ad tags and
reintroduce the failure, *and* they reintroduce the Consent Mode race that the
inline stub used to paper over.

## Async install (advanced)

The one-tag blocking install above is the recommended default — do not
switch without a reason. For sites whose performance budget genuinely
cannot carry a parser-blocking tag (measured, not assumed), there is a
supported async variant: a ~1&nbsp;KB synchronous inline snippet keeps the
two guarantees that must not race (the Consent Mode denied-before-load
default and `__tcfapi` presence for ad tags), and the loader itself gets
`async`:

```html
<script>
(function(){var l="dataLayer";window[l]=window[l]||[];function g(){window[l].push(arguments)}
g("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"denied",personalization_storage:"denied",security_storage:"granted",wait_for_update:500});
if(typeof window.fbq==="function")fbq("consent","revoke");
var T="__tcfapiLocator",f=0,w=window;for(;w;){try{if(w.frames[T]){f=1;break}}catch(e){}if(w===window.top)break;w=w.parent}
if(typeof window.__tcfapi!=="function"&&!f){var q=[],ga;
window.__tcfapi=function(){var a=arguments;if(!a.length)return q;
if(a[0]==="setGdprApplies"){if(a.length>3&&parseInt(a[1],10)===2&&typeof a[3]==="boolean"){ga=a[3];if(typeof a[2]==="function")a[2]("set",true)}}
else if(a[0]==="ping"){if(typeof a[2]==="function")a[2]({gdprApplies:ga,cmpLoaded:false,cmpStatus:"stub"})}
else q.push(a)};
window.__tcfapi.cnzStub=1;
(function m(){var d=document;if(window.frames[T])return;if(d.body){var i=d.createElement("iframe");i.style.cssText="display:none";i.name=T;d.body.appendChild(i)}else setTimeout(m,5)})();
window.addEventListener("message",function(e){var s=typeof e.data==="string",p={};if(s){try{p=JSON.parse(e.data)}catch(x){}}else p=e.data;
var c=p&&p.__tcfapiCall;if(!c)return;if(typeof window.__tcfapi!=="function")return;
window.__tcfapi(c.command,c.version,function(v,ok){var r={__tcfapiReturn:{returnValue:v,success:ok,callId:c.callId}};if(e.source&&e.source.postMessage)e.source.postMessage(s?JSON.stringify(r):r,"*")},c.parameter)},false)}})();
</script>
<script async src="{SERVER}/c/consent.js" data-key="{KEY}"></script>
```

How it works: the inline stub marks itself `cnzStub=1`; the loader
recognises the marker and **adopts** it — same live call queue, same
teardown on non-TCF sites — instead of installing a second stub. The GCM
default push is duplicated when the loader arrives; Google ignores the
redundant one.

The honest tradeoff you accept with `async`: the **pre-consent
script/iframe blocker starts only when the loader executes**, so a
third-party tag that both appears above the loader in the document AND
executes before the async loader lands can slip through un-blocked. The
blocking install closes that window completely; the async install shrinks
it to the loader's fetch time. Sites using `data-dl` must also change the
`l="dataLayer"` variable in the inline snippet to their dataLayer name —
the static snippet cannot read the attribute.

Every certified-CMP ordering caveat in the sections above still applies;
this variant exists because naming the tradeoff honestly beats pretending
`async` is free.

## Custom dataLayer names

Sites that renamed their GTM dataLayer (the `gtm_data_layer` setting in banner
settings, which maps to GTM's `&l=` parameter) add one attribute:

```html
<script src="{SERVER}/c/consent.js" data-key="{KEY}" data-dl="MY_DL"></script>
```

The loader pushes the defaults to both `MY_DL` and the standard `dataLayer`. The
in-app snippet generator emits `data-dl` automatically when `gtm_data_layer` is
set.

## Caching

`/c/consent.js` has a fixed URL with no version hash, so the browser copy cannot
be busted by rotating the URL the way `/sites_data/{key}/script.js?v={hash}` is.
It is served with a short `max-age` plus `stale-while-revalidate` — see the
`location /c/` block in `docker/nginx/production.conf` for the reasoning.

Any deploy that changes the loader **must** purge it from the edge.
`bin/regenerate-all-scripts.php` (which `deploy.sh` runs) calls `purgeLoader()`
for this reason; `php bin/oci cache:purge-loader` does it on demand.

## Mirrors to keep in sync

| Location | Notes |
|---|---|
| `templates/pages/banners/index.html.twig` | Copy-paste snippet (JS string) |
| `templates/pages/dashboard/customer.html.twig` | Copy-paste snippet + static display |
| `templates/components/preview-modal.html.twig` | Static display |
| `plugins/getconzent_wp/conzent.php` | `wp_head` priority 0 |
| `plugins/getconzent_joomla/conzent_joomla.php` | `addCustomTag()` |
| `plugins/getconzent_drupal/conzent_drupal.module` | `html_head` entry |
| `plugins/getconzent_typo3/conzent_typo3.php` | + `Classes/Middleware/ConzentBannerMiddleware.php` |
| `plugins/getconzent_umbraco/Conzent.Umbraco/ConzentScriptTagHelper.cs` | `SetHtmlContent` |
| `plugins/getconzent_wix/conzent_wix.js` | Two endpoints; injects the tag from JS |
| `README.md`, `docs/custom-domain.md` | Install docs |
| `knowledge-base/articles/20-sites/install-script.md` | End-user help |
| `www/templates/help-installation.html.twig`, `www/templates/base.html.twig` | Marketing site |
| `www/content/pages*/integrations/index.md`, `www/content/pages*/docs/custom-domain.md` | + localized copies |
| `docker/testsite/*.html` | Local E2E fixtures |
| `docs/js-api.md` | Developer API doc references the embed |
| `docs/csp.md` | CSP variant of the snippet (nonce attributes) |
| `sdk/src/index.ts` | `loadConzent()` injects the snippet programmatically |
