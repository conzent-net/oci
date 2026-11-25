/**
 * Consent Mode timing probe — the rules, without a browser.
 *
 * Tag Assistant's "A tag read consent state before a default was set" is the
 * symptom customers report; the cause is almost never visible in Tag Assistant
 * itself. Google keeps the truth on `google_tag_data.ics`: every consent type's
 * default and update, plus `wasSetLate`, which it flips when a `consent default`
 * arrives after a tag has already asked for the state. This module turns two
 * snapshots of that registry, the dataLayer and the page's script tags — one
 * taken as the page loaded, one after a simulated first interaction — into a
 * verdict the diagnostics page can explain.
 *
 * The interaction matters: "Delay JavaScript" optimizers (LiteSpeed Cache,
 * WP Rocket, Perfmatters, …) hold every script, the consent loader included,
 * until the visitor moves the mouse or presses a key. Before that moment the
 * page has no consent defaults at all, and Tag Assistant, which only connects
 * once the container runs, shows the defaults as late.
 *
 * Everything here is pure so it can be tested on its own; the in-page
 * snapshot function is serialized into the browser by scanner.js and must
 * therefore reference nothing outside itself.
 */

'use strict';

/**
 * Runs INSIDE the page (puppeteer serializes it): the consent registry, the
 * dataLayer in compact form, which Google globals exist, whether our loader
 * and bundle ran, and how many script tags an optimizer rewrote.
 *
 * Self-contained on purpose: no closures, no module constants.
 *
 * @returns {object}
 */
function snapshotConsentMode() {
  var out = {
    data_layer_length: 0,
    data_layer: [],
    consent_default_index: -1,
    consent_update_index: -1,
    first_tag_init_index: -1,
    gtm_js_index: -1,
    ics: null,
    gtm_loaded: false,
    gtm_ids: [],
    gtag_defined: typeof window.gtag === 'function',
    cnz: {
      loader: typeof window._cnzConsentGiven !== 'undefined',
      bundle: typeof window._cnzVersion !== 'undefined',
      version: typeof window._cnzVersion === 'string' ? window._cnzVersion : null,
      consent_given: window._cnzConsentGiven === true,
      // The `data-plugin="<cms>/<version>"` marker a Conzent CMS plugin puts
      // on the loader it injects; visible here even when the loader was
      // added from JavaScript and never appears in the static HTML.
      plugin: null,
    },
    delayed_scripts: { count: 0, types: [], google: 0, loader: 0 },
    google_scripts: 0,
    timeline: null,
  };

  var dl = Array.isArray(window.dataLayer) ? window.dataLayer : [];
  out.data_layer_length = dl.length;
  for (var i = 0; i < dl.length && out.data_layer.length < 200; i++) {
    var e = dl[i];
    var d = null;
    var isArgs = e && typeof e === 'object' && typeof e.length === 'number' && typeof e[0] === 'string';
    if (isArgs) {
      var cmd = String(e[0]);
      var arg = typeof e[1] === 'string' ? e[1] : (e[1] && typeof e[1] === 'object' ? 'object' : String(e[1]));
      d = { i: i, cmd: cmd, arg: arg };
      if (cmd === 'consent' && e[2] && typeof e[2] === 'object') {
        d.keys = Object.keys(e[2]).slice(0, 12);
        if (typeof e[2].wait_for_update === 'number') d.wait_for_update = e[2].wait_for_update;
      }
      if (cmd === 'consent' && arg === 'default' && out.consent_default_index < 0) out.consent_default_index = i;
      if (cmd === 'consent' && arg === 'update' && out.consent_update_index < 0) out.consent_update_index = i;
      if ((cmd === 'config' || cmd === 'js') && out.first_tag_init_index < 0) out.first_tag_init_index = i;
    } else if (e && typeof e === 'object') {
      var ev = typeof e.event === 'string' ? e.event : null;
      d = { i: i, event: ev, keys: Object.keys(e).slice(0, 8) };
      if (ev === 'gtm.js' && out.gtm_js_index < 0) out.gtm_js_index = i;
    } else {
      d = { i: i, other: typeof e };
    }
    out.data_layer.push(d);
  }

  var gtd = window.google_tag_data;
  var ics = gtd && gtd.ics;
  if (ics && typeof ics === 'object') {
    out.ics = {
      entries: {},
      was_set_late: ics.wasSetLate === true,
      accessed_any: ics.accessedAny === true,
      used_default: ics.usedDefault === true,
      used_update: ics.usedUpdate === true,
      wait_period_timed_out: ics.waitPeriodTimedOut === true,
    };
    var entries = ics.entries && typeof ics.entries === 'object' ? ics.entries : {};
    for (var k in entries) {
      if (Object.prototype.hasOwnProperty.call(entries, k) && entries[k] && typeof entries[k] === 'object') {
        out.ics.entries[k] = {
          default: typeof entries[k].default === 'boolean' ? entries[k].default : null,
          update: typeof entries[k].update === 'boolean' ? entries[k].update : null,
        };
      }
    }
  }

  var gtm = window.google_tag_manager;
  if (gtm && typeof gtm === 'object') {
    out.gtm_loaded = true;
    out.gtm_ids = Object.keys(gtm).filter(function (key) { return /^(GTM|G|AW|DC|GT)-/.test(key); }).slice(0, 10);
  }

  var scripts = document.getElementsByTagName('script');
  var types = {};
  var delayed = 0;
  var google = 0;
  for (var s = 0; s < scripts.length; s++) {
    var sc = scripts[s];
    var t = (sc.getAttribute('type') || '').toLowerCase();
    var src = sc.getAttribute('src') || sc.getAttribute('data-src') || sc.getAttribute('data-rocket-src') || '';
    // Only the loader's own marker counts: data-plugin is a common attribute
    // name in theme and vendor markup.
    if (out.cnz.plugin === null && /\/c\/consent\.js/.test(src) && sc.hasAttribute('data-plugin')) {
      out.cnz.plugin = String(sc.getAttribute('data-plugin')).slice(0, 64);
    }
    var jsType = t === '' || t === 'text/javascript' || t === 'application/javascript' || t === 'module';
    var dataType = t === 'text/plain' || t === 'application/json' || t === 'application/ld+json' || t === 'importmap' || t === 'speculationrules' || t === 'text/template';
    var heldSrc = sc.getAttribute('data-pmdelayedscript') || '';
    // A label per product, so the summary can name it.
    var label = null;
    if (t === 'litespeed/javascript' || t === 'pmdelayedscript' || t === 'javascript/blocked' || t === 'wphb-delay-type' || t === 'text/psajs') label = t;
    else if (/rocketlazyloadscript$/.test(t)) label = 'rocketlazyloadscript';
    else if (/-text\/javascript$/.test(t)) label = t;
    else if (sc.hasAttribute('data-rocket-src')) label = 'rocketlazyloadscript';
    else if (heldSrc !== '') label = 'pmdelayedscript';
    else if (!sc.hasAttribute('src') && sc.hasAttribute('data-src') && String(sc.getAttribute('data-lazy') || '').toLowerCase() === 'w3tc') label = 'w3tc';
    else if (!sc.hasAttribute('src') && sc.hasAttribute('data-src') && sc.hasAttribute('data-loading-method')) label = 'flying-press';
    else if (sc.hasAttribute('data-src') && !jsType && !dataType) label = t !== '' ? t : 'data-src';
    if (label !== null) {
      delayed++;
      types[label] = (types[label] || 0) + 1;
      var heldAddress = src || heldSrc || sc.getAttribute('data-wpmeteor-src') || '';
      var code = typeof sc.textContent === 'string' ? sc.textContent : '';
      if (/googletagmanager\.com|gtm\.js|gtag\/js/.test(heldAddress) || /googletagmanager\.com|\bGTM-[A-Z0-9]{4,}/.test(code)) out.delayed_scripts.google++;
      if (/\/c\/consent\.js|sites_data\//.test(heldAddress)) out.delayed_scripts.loader++;
    }
    if (/googletagmanager\.com/.test(src)) google++;
  }
  out.delayed_scripts.count = delayed;
  out.delayed_scripts.types = Object.keys(types);
  out.google_scripts = google;

  var tl = window.__cnzDiagTimeline;
  if (tl && typeof tl === 'object') {
    out.timeline = {
      dcl: typeof tl.dcl === 'number' ? tl.dcl : -1,
      load: typeof tl.load === 'number' ? tl.load : -1,
      default_at: typeof tl.default_at === 'number' ? tl.default_at : -1,
      gtm_at: typeof tl.gtm_at === 'number' ? tl.gtm_at : -1,
      loader_at: typeof tl.loader_at === 'number' ? tl.loader_at : -1,
      now: Math.round(performance.now()),
    };
  }

  return out;
}

/** Whether a snapshot shows any consent default, from the registry or the dataLayer. */
function hasDefaults(snapshot) {
  if (!snapshot) return false;
  if (snapshot.consent_default_index >= 0) return true;
  var entries = snapshot.ics && snapshot.ics.entries ? snapshot.ics.entries : {};
  return Object.keys(entries).some(function (k) { return entries[k] && entries[k].default !== null; });
}

/** Whether a snapshot shows a Google tag or container at all. */
function hasGoogle(snapshot) {
  if (!snapshot) return false;
  return snapshot.gtm_loaded === true
    || snapshot.gtag_defined === true
    || snapshot.first_tag_init_index >= 0
    || snapshot.gtm_js_index >= 0
    || (snapshot.ics !== null && snapshot.ics !== undefined);
}

/**
 * Map the optimizer-rewritten script types to the product that did it, so the
 * fix can name the setting screen instead of saying "an optimizer".
 *
 * @param {string[]} types
 * @returns {string}
 */
function optimizerFromTypes(types) {
  var names = [];
  (types || []).forEach(function (t) {
    if (t === 'litespeed/javascript') names.push('LiteSpeed Cache');
    else if (/rocketlazyloadscript$/.test(t)) names.push('WP Rocket');
    else if (t === 'pmdelayedscript') names.push('Perfmatters');
    else if (t === 'javascript/blocked') names.push('WP Meteor');
    else if (t === 'wphb-delay-type') names.push('Hummingbird');
    else if (t === 'text/psajs') names.push('PageSpeed');
    else if (t === 'w3tc') names.push('W3 Total Cache');
    else if (t === 'flying-press') names.push('FlyingPress');
    else if (/-text\/javascript$/.test(t)) names.push('Cloudflare Rocket Loader');
  });
  return names.filter(function (n, i) { return names.indexOf(n) === i; }).join(', ');
}

/**
 * The verdict and the facts behind it.
 *
 * Verdicts:
 *   no_google_tags            — nothing Google ran, nothing to time
 *   delayed_unresolved        — an optimizer still held Google or Conzent
 *                               scripts after the simulated interaction
 *   gtm_delayed               — the loader set the defaults at once; only
 *                               the container waited for the interaction
 *   delayed_by_timeout        — the defaults appeared seconds after the page
 *                               was ready without any interaction (a timer)
 *   delayed_until_interaction — Google and/or the defaults only appeared after
 *                               the simulated interaction (Delay JS optimizer)
 *   late_default              — Google's registry says a tag read consent before
 *                               a default, or a tag initialised before the default
 *   no_defaults               — Google tags ran and no default was ever registered
 *   ok                        — defaults were in place before any tag read them
 *
 * @param {object|null} before snapshot as the page loaded
 * @param {object|null} after  snapshot after the interaction (same as before when none was needed)
 * @param {boolean} nudged     whether an interaction was simulated
 */
/** Whether the Conzent loader has run in a snapshot. */
function loaderAfterRan(snapshot) {
  return !!(snapshot && snapshot.cnz && snapshot.cnz.loader);
}

function summarizeConsentMode(before, after, nudged) {
  var b = before || null;
  var a = after || b;
  var defaultsBefore = hasDefaults(b);
  var defaultsAfter = hasDefaults(a);
  var googleBefore = hasGoogle(b);
  var googleAfter = hasGoogle(a);
  var delayedBefore = b && b.delayed_scripts ? b.delayed_scripts.count : 0;
  var delayedTypes = b && b.delayed_scripts ? b.delayed_scripts.types : [];
  var wasSetLate = !!(a && a.ics && a.ics.was_set_late);
  var defaultIndex = a ? a.consent_default_index : -1;
  var initIndex = a ? a.first_tag_init_index : -1;
  var loaderBefore = !!(b && b.cnz && b.cnz.loader);
  var loaderAfter = !!(a && a.cnz && a.cnz.loader);
  var heldGoogle = b && b.delayed_scripts ? (b.delayed_scripts.google || 0) : 0;
  var heldLoader = b && b.delayed_scripts ? (b.delayed_scripts.loader || 0) : 0;
  // What is still held after the interaction (the after-snapshot).
  var stillHeldGoogle = a && a.delayed_scripts ? (a.delayed_scripts.google || 0) : 0;
  var stillHeldLoader = a && a.delayed_scripts && !loaderAfterRan(a) ? (a.delayed_scripts.loader || 0) : 0;
  // How long after the page was ready the first consent default appeared,
  // from the recorder scanner.js installs before the page's own scripts.
  var tl = (a && a.timeline) || (b && b.timeline) || null;
  var defaultDelay = tl && tl.default_at >= 0 && tl.dcl >= 0 ? tl.default_at - tl.dcl : -1;

  var verdict;
  if (!a) {
    verdict = 'inconclusive';
  } else if (!googleAfter) {
    // Nothing Google ran, even after the interaction. When an optimizer is
    // still holding Google or Conzent scripts that is not "no tags" but a
    // delay the simulated interaction did not release.
    if (nudged && (stillHeldGoogle > 0 || stillHeldLoader > 0)) {
      verdict = 'delayed_unresolved';
    } else if (nudged && !loaderBefore && loaderAfter) {
      // The loader was held and the interaction released it; no Google tags.
      verdict = 'delayed_until_interaction';
    } else {
      verdict = 'no_google_tags';
    }
  } else if (nudged && !googleBefore && (!loaderBefore || !defaultsBefore)) {
    verdict = 'delayed_until_interaction';
  } else if (nudged && !googleBefore) {
    // The loader ran at once and set the defaults; only the container
    // waits for the interaction. Safe for consent.
    verdict = 'gtm_delayed';
  } else if (nudged && !defaultsBefore && defaultsAfter && (delayedBefore > 0 || (!loaderBefore && loaderAfter))) {
    verdict = 'delayed_until_interaction';
  } else if (wasSetLate) {
    verdict = 'late_default';
  } else if (!defaultsAfter) {
    verdict = 'no_defaults';
  } else if (defaultIndex >= 0 && initIndex >= 0 && defaultIndex > initIndex) {
    verdict = 'late_default';
  } else if (defaultDelay > 2500 && (delayedBefore > 0 || heldLoader > 0 || (tl && tl.loader_at >= 0 && tl.dcl >= 0 && tl.loader_at - tl.dcl > 2500))) {
    // Only with evidence of an optimizer: defaults that simply arrive with a
    // slow container (Tag Manager template, Wix, Matomo) are consent-safe.
    // Defaults arrived seconds after the page was ready with no
    // interaction: an optimizer released the loader on a timer.
    verdict = 'delayed_by_timeout';
  } else {
    verdict = 'ok';
  }

  return {
    verdict: verdict,
    nudged: !!nudged,
    google_before_interaction: googleBefore,
    google_after_interaction: googleAfter,
    defaults_before_interaction: defaultsBefore,
    defaults_after_interaction: defaultsAfter,
    was_set_late: wasSetLate,
    accessed_any: !!(a && a.ics && a.ics.accessed_any),
    consent_default_index: defaultIndex,
    consent_update_index: a ? a.consent_update_index : -1,
    first_tag_init_index: initIndex,
    gtm_js_index: a ? a.gtm_js_index : -1,
    gtm_ids: a && Array.isArray(a.gtm_ids) ? a.gtm_ids : [],
    optimizer: {
      delayed_scripts: delayedBefore,
      types: delayedTypes,
      name: optimizerFromTypes(delayedTypes),
      held_google: heldGoogle,
      held_loader: heldLoader,
    },
    default_delay_ms: defaultDelay,
    timeline: tl,
    cmp: {
      loader_before: !!(b && b.cnz && b.cnz.loader),
      bundle_before: !!(b && b.cnz && b.cnz.bundle),
      loader_after: !!(a && a.cnz && a.cnz.loader),
      bundle_after: !!(a && a.cnz && a.cnz.bundle),
      version: a && a.cnz ? a.cnz.version : null,
      plugin: a && a.cnz && typeof a.cnz.plugin === 'string' ? a.cnz.plugin : null,
    },
    ics_entries: a && a.ics ? a.ics.entries : {},
  };
}

/**
 * Runs INSIDE the page before any of its own scripts (puppeteer's
 * evaluateOnNewDocument): records when the page was ready and when the first
 * consent default, the container and the Conzent loader appeared, so an
 * optimizer that releases scripts on a timer — before the snapshot is taken —
 * still shows up. Read-only: it polls, it never wraps or freezes anything.
 *
 * Self-contained on purpose: no closures, no module constants.
 */
function installConsentTimeline() {
  if (window.__cnzDiagTimeline) return;
  var tl = { dcl: -1, load: -1, default_at: -1, gtm_at: -1, loader_at: -1 };
  window.__cnzDiagTimeline = tl;
  var now = function () { return Math.round(performance.now()); };
  document.addEventListener('DOMContentLoaded', function () { if (tl.dcl < 0) tl.dcl = now(); });
  window.addEventListener('load', function () { if (tl.load < 0) tl.load = now(); });
  var hasDefault = function () {
    var dl = window.dataLayer;
    if (dl && typeof dl.length === 'number') {
      for (var i = 0; i < dl.length; i++) {
        var e = dl[i];
        if (e && typeof e === 'object' && e[0] === 'consent' && e[1] === 'default') return true;
      }
    }
    var ics = window.google_tag_data && window.google_tag_data.ics;
    var entries = ics && ics.entries;
    if (entries && typeof entries === 'object') {
      for (var k in entries) {
        if (Object.prototype.hasOwnProperty.call(entries, k) && entries[k] && typeof entries[k].default === 'boolean') return true;
      }
    }
    return false;
  };
  var timer = setInterval(function () {
    var t = now();
    try {
      if (tl.default_at < 0 && hasDefault()) tl.default_at = t;
      if (tl.gtm_at < 0 && window.google_tag_manager) tl.gtm_at = t;
      if (tl.loader_at < 0 && typeof window._cnzConsentGiven !== 'undefined') tl.loader_at = t;
    } catch (e) { /* a hostile page never breaks the recorder */ }
    if (t > 30000 || (tl.default_at >= 0 && tl.gtm_at >= 0 && tl.loader_at >= 0)) clearInterval(timer);
  }, 50);
}

module.exports = {
  installConsentTimeline,
  snapshotConsentMode,
  summarizeConsentMode,
  hasDefaults,
  hasGoogle,
  optimizerFromTypes,
};
