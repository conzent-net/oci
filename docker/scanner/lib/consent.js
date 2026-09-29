/**
 * Consent helpers for two-phase scans.
 *
 * A scan used to load every page with no consent, which after a customer
 * installs Conzent shows exactly what the blocker lets through: our own two
 * cookies. That is a compliance answer ("what does a visitor get before
 * consenting?"), not the inventory the cookie declaration has to list. So a
 * scan can now take a second pass per URL after Accept All.
 *
 * Everything here is pure and has no browser in it, so the rules that decide
 * what gets blocked, what gets seeded and what counts as verified can be
 * tested on their own.
 */

'use strict';

/** Every category slug a site can carry. Extra slugs are harmless: the runtime reads prefs with `.includes`. */
const ALL_CATEGORIES = ['necessary', 'functional', 'preferences', 'analytics', 'performance', 'marketing', 'unclassified'];

const PRE_CONSENT = 'pre_consent';
const POST_CONSENT = 'post_consent';

/**
 * The runtime's own server calls. A scan must never reach them: `log`
 * counts a pageview against the customer's quota, `consent` writes a consent
 * record, `scan_data` writes scan cookies. Matched by path so a wrong or
 * missing `blockUrls` still cannot let a scanner click Accept All into
 * someone's consent log.
 */
const RUNTIME_WRITE_PATH = /\/api\/v1\/(log|consent|scan_data)\/?$/;

/**
 * The phases a scan should run, in order. Before-consent always runs first:
 * it is today's scan, and the after-consent pass reuses its final URL.
 *
 * @param {unknown} value
 * @returns {string[]}
 */
function normalizePhases(value) {
  const requested = Array.isArray(value) ? value.map(String) : [];

  return requested.includes(POST_CONSENT) ? [PRE_CONSENT, POST_CONSENT] : [PRE_CONSENT];
}

/**
 * Build the predicate that decides whether a request is answered by the
 * scanner instead of being sent.
 *
 * `blockUrls` are absolute endpoint URLs (query ignored). With `failClosed`
 * any POST whose path is one of the runtime's write endpoints is caught too,
 * on any host, because the price of a wrong block list during an Accept All
 * pass is a fabricated consent in a customer's records.
 *
 * Only POSTs are ever caught. The runtime also GETs `geo_ip`, `cookies`, the
 * vendor list and its own bundle from the same host, and those must load or
 * the banner the scan is measuring never renders.
 *
 * @param {unknown} blockUrls
 * @param {{failClosed?: boolean}} [opts]
 * @returns {(method: string, url: string) => boolean}
 */
function buildBlockMatcher(blockUrls, opts = {}) {
  const exact = new Set();
  for (const raw of Array.isArray(blockUrls) ? blockUrls : []) {
    const key = endpointKey(String(raw));
    if (key !== null) exact.add(key);
  }
  const failClosed = opts.failClosed === true;

  return (method, url) => {
    if (String(method).toUpperCase() !== 'POST') return false;

    const key = endpointKey(url);
    if (key === null) return false;
    if (exact.has(key)) return true;

    if (failClosed) {
      try {
        return RUNTIME_WRITE_PATH.test(new URL(url).pathname);
      } catch {
        return false;
      }
    }

    return false;
  };
}

/** origin + path, without query or trailing slash; null when unparseable. */
function endpointKey(url) {
  try {
    const u = new URL(url);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;

    return u.origin.toLowerCase() + u.pathname.replace(/\/+$/, '');
  } catch {
    return null;
  }
}

/**
 * The consent cookies a visitor has after Accept All, for the case where no
 * control on the page could be clicked.
 *
 * Written the way `Conzent_Cookie.set` writes them: path `/`, SameSite Lax,
 * Secure on https, `conzentConsentPrefs` as `encodeURIComponent(JSON)`. With
 * consent sharing on (the default for new sites) the runtime writes to the
 * apex with a leading dot; otherwise the cookie is host-only on the page the
 * visitor is actually on, which after a redirect is the final URL.
 *
 * @param {{finalUrl: string, siteDomain?: string, consentSharing?: boolean, now?: number}} input
 * @returns {object[]} puppeteer `page.setCookie` parameters
 */
function buildSeedCookies({ finalUrl, siteDomain, consentSharing = true, now = Date.now() }) {
  const u = new URL(finalUrl);
  const host = u.hostname.toLowerCase();
  const secure = u.protocol === 'https:';
  const expires = Math.floor(now / 1000) + 365 * 24 * 3600;

  const base = { path: '/', secure, sameSite: 'Lax', expires, httpOnly: false };
  const where = consentSharing
    ? { domain: '.' + apexFor(host, siteDomain) }
    : { url: u.origin + '/' };

  return [
    { name: 'conzentConsent', value: 'true', ...base, ...where },
    { name: 'conzentConsentPrefs', value: encodeURIComponent(JSON.stringify(ALL_CATEGORIES)), ...base, ...where },
  ];
}

/**
 * The runtime's `getRootDomain()`: the current host without `www.` when it
 * belongs to the site, otherwise the site's own domain.
 */
function apexFor(host, siteDomain) {
  const bare = host.replace(/^www\./, '');
  const site = String(siteDomain || '').toLowerCase().replace(/^www\./, '');
  if (site === '' || bare === site || bare.endsWith('.' + site)) return bare;

  return site;
}

/**
 * What the after-consent pass achieved, and whether its cookies can be
 * trusted as the site's inventory.
 *
 * `verified` is the load-bearing field: an unverified pass is still shown to
 * the admin, but it never replaces a site's cookie list or public
 * declaration. On a TCF site only a real click writes a TC string with vendor
 * consent, so anything short of that leaves ad-tech vendors unconsented and
 * the inventory incomplete.
 *
 * @param {object} d diagnostics
 * @param {'absent'|'loader_only'|'paused'|'present'} d.runtime
 * @param {'accept'|'save_all'|null} [d.clicked]
 * @param {boolean} [d.seeded]
 * @param {string|null} [d.consentAfterReload] value of `conzentConsent` once the page reloaded
 * @param {boolean} [d.tcf]
 * @param {boolean} [d.tcString]
 * @param {boolean} [d.bannerShown]
 * @returns {{method: string, verified: boolean, reason: string}}
 */
function classifyOutcome(d) {
  switch (d.runtime) {
    case 'absent':
      return { method: 'not_detected', verified: true, reason: 'No Conzent runtime on the page, so the page as loaded is the inventory.' };
    case 'paused':
      return { method: 'cmp_paused', verified: true, reason: 'Consent management is paused for this site, so nothing was blocked.' };
    case 'loader_only':
      return { method: 'runtime_failed', verified: false, reason: 'The loader ran but the banner script did not, so blocking stayed on.' };
    default:
      break;
  }

  const consented = d.consentAfterReload === 'true';
  const method = d.clicked === 'accept' ? 'click' : (d.clicked === 'save_all' ? 'save_all' : (d.seeded ? 'seed' : 'banner_not_shown'));

  if (method === 'banner_not_shown') {
    return { method, verified: false, reason: 'No Accept control was shown, so consent could not be given.' };
  }
  // A banner that never rendered (geo targeting outside the scanner's region,
  // disable_on_pages) means the runtime never initialised, and seeded consent
  // does not release what the loader blocked. The cookie would read "true"
  // while the page still hid most of its trackers.
  if (method === 'seed' && !d.bannerShown) {
    return { method, verified: false, reason: 'The banner is not shown to the scanner, so blocked scripts may not have been released.' };
  }
  if (!consented) {
    return { method, verified: false, reason: 'Consent was given but did not survive the reload.' };
  }
  if (d.tcf && (method !== 'click' || !d.tcString)) {
    return {
      method,
      verified: false,
      reason: method === 'click'
        ? 'Accepted, but no TCF consent string was written, so ad-tech vendors stayed unconsented.'
        : 'This site uses TCF and only the Accept All button grants vendor consent, so ad-tech cookies may be missing.',
    };
  }

  const how = { click: "Accepted with the banner's Accept All.", save_all: 'Accepted every category in the preference centre.', seed: 'Consent set directly, because no Accept control was shown.' };

  return { method, verified: true, reason: how[method] };
}

module.exports = {
  ALL_CATEGORIES,
  PRE_CONSENT,
  POST_CONSENT,
  normalizePhases,
  buildBlockMatcher,
  buildSeedCookies,
  classifyOutcome,
};
