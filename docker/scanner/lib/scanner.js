/**
 * CookieScanner — Headless Chromium cookie detection engine.
 *
 * Navigates to URLs using Puppeteer, collects:
 *   - All cookies (first-party + third-party, HttpOnly, Secure, SameSite)
 *   - localStorage keys
 *   - Third-party network requests (beacons, tracking pixels, scripts)
 *
 * Manages a browser pool with configurable concurrency.
 */

const puppeteer = require('puppeteer-core');
const {
  PRE_CONSENT,
  POST_CONSENT,
  normalizePhases,
  buildBlockMatcher,
  buildSeedCookies,
  classifyOutcome,
} = require('./consent');

// Known tracking domains for beacon classification
const BEACON_PATTERNS = {
  'google-analytics.com': 'analytics',
  'googletagmanager.com': 'analytics',
  'analytics.google.com': 'analytics',
  'google.com/pagead': 'marketing',
  'googlesyndication.com': 'marketing',
  'googleadservices.com': 'marketing',
  'doubleclick.net': 'marketing',
  'facebook.com/tr': 'marketing',
  'facebook.net': 'marketing',
  'connect.facebook.net': 'marketing',
  'meta.com': 'marketing',
  'analytics.tiktok.com': 'marketing',
  'tiktok.com/i18n': 'marketing',
  'snap.licdn.com': 'marketing',
  'linkedin.com/px': 'marketing',
  'ads.linkedin.com': 'marketing',
  'bat.bing.com': 'marketing',
  'clarity.ms': 'analytics',
  'hotjar.com': 'analytics',
  'mouseflow.com': 'analytics',
  'heapanalytics.com': 'analytics',
  'mixpanel.com': 'analytics',
  'amplitude.com': 'analytics',
  'segment.io': 'analytics',
  'segment.com': 'analytics',
  'pinterest.com/ct': 'marketing',
  'ads.twitter.com': 'marketing',
  'analytics.twitter.com': 'marketing',
  't.co': 'marketing',
  'criteo.com': 'marketing',
  'criteo.net': 'marketing',
  'taboola.com': 'marketing',
  'outbrain.com': 'marketing',
  'adroll.com': 'marketing',
  'hubspot.com': 'analytics',
  'hs-analytics.net': 'analytics',
  'intercom.io': 'functional',
  'zendesk.com': 'functional',
  'freshdesk.com': 'functional',
  'cloudflare.com': 'necessary',
  'cdn.jsdelivr.net': 'necessary',
  'cdnjs.cloudflare.com': 'necessary',
  'stripe.com': 'necessary',
  'paypal.com': 'necessary',
  'recaptcha.net': 'necessary',
  'gstatic.com/recaptcha': 'necessary',
  'youtube.com': 'marketing',
  'youtube-nocookie.com': 'functional',
  'vimeo.com': 'marketing',
  'maps.googleapis.com': 'functional',
  'maps.google.com': 'functional',
};

class CookieScanner {
  constructor(options = {}) {
    this.maxConcurrent = options.maxConcurrent || 3;
    this.timeout = options.timeout || 60000;
    this.browser = null;
    this.activeCount = 0;
    this._queue = [];
    this._processing = false;
  }

  async initialize() {
    const chromiumPath = process.env.PUPPETEER_EXECUTABLE_PATH || process.env.CHROMIUM_PATH || '/usr/bin/chromium';

    this.browser = await puppeteer.launch({
      executablePath: chromiumPath,
      headless: 'new',
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-software-rasterizer',
        '--disable-extensions',
        '--disable-background-networking',
        '--disable-default-apps',
        '--disable-sync',
        '--disable-translate',
        '--metrics-recording-only',
        '--mute-audio',
        '--no-first-run',
        '--safebrowsing-disable-auto-update',
        '--no-zygote',
        '--disable-features=TranslateUI',
        '--window-size=1280,720',
      ],
    });

    console.log('Browser initialized');
  }

  async close() {
    if (this.browser) {
      await this.browser.close();
      this.browser = null;
    }
  }

  /**
   * Scan a single URL for cookies, localStorage, and beacons.
   *
   * @param {string} url - The URL to scan
   * @param {object} options - Scan options
   * @param {boolean} options.waitForNetworkIdle - Wait for network idle (default true)
   * @param {number} options.extraWait - Extra wait time in ms after load (default 3000)
   * @param {string[]} options.consentPhases - ['pre_consent'] (default) or ['pre_consent','post_consent']
   * @param {string[]} options.blockUrls - Endpoints answered by the scanner instead of sent (the app's own log/consent calls)
   * @param {object} options.consentContext - {banner_delay_ms, consent_sharing_enabled} for the after-consent pass
   * @returns {Promise<ScanResult>}
   */
  async scanUrl(url, options = {}) {
    // Throttle concurrent scans
    if (this.activeCount >= this.maxConcurrent) {
      await new Promise(resolve => this._queue.push(resolve));
    }

    this.activeCount++;
    const startTime = Date.now();

    try {
      return await this._doScan(url, options);
    } finally {
      this.activeCount--;
      // Release next queued scan
      if (this._queue.length > 0) {
        const next = this._queue.shift();
        next();
      }
    }
  }

  async _doScan(url, options) {
    const waitForNetworkIdle = options.waitForNetworkIdle !== false;
    const extraWait = options.extraWait || 3000;
    const startTime = Date.now();

    // With no phases and no block list requested, everything below behaves
    // exactly as it did before two-phase scans existed. ScannerProbe relies on
    // that: it reads a scan as "pre-consent by construction".
    const phases = normalizePhases(options.consentPhases);
    const twoPhase = phases.includes(POST_CONSENT);
    const blockUrls = Array.isArray(options.blockUrls) ? options.blockUrls : [];
    const shielded = twoPhase || blockUrls.length > 0;
    const shouldBlock = buildBlockMatcher(blockUrls, { failClosed: twoPhase });

    if (!this.browser || !this.browser.isConnected()) {
      await this.close();
      await this.initialize();
    }

    let context, page;
    try {
      context = await this.browser.createBrowserContext();
      page = await context.newPage();
    } catch (err) {
      // Browser may have crashed — restart and retry once
      console.error('Browser context creation failed, restarting browser:', err.message);
      await this.close();
      await this.initialize();
      context = await this.browser.createBrowserContext();
      page = await context.newPage();
    }

    // Each phase records into its own array; the handler writes to whichever
    // one is current, so an after-consent beacon list never inherits what the
    // page requested before consent.
    let thirdPartyRequests = [];
    let blockedRequests = 0;
    const siteDomain = new URL(url).hostname.replace(/^www\./, '');

    try {
      // Set a realistic user agent
      await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
      );

      // A site's service worker carries fetches past page-level interception,
      // which would let the runtime's consent and pageview calls through.
      if (shielded) {
        await page.setBypassServiceWorker(true);
      }

      // Enable request interception to track third-party requests
      await page.setRequestInterception(true);
      page.on('request', (request) => {
        const reqUrl = request.url();

        // Answer the app's own write endpoints ourselves. Never abort: the
        // runtime keeps an aborted call in localStorage and replays it with
        // sendBeacon, so an abort only postpones the leak.
        if (shielded && shouldBlock(request.method(), reqUrl)) {
          blockedRequests++;
          const origin = request.headers().origin;
          request.respond({
            status: 200,
            contentType: 'application/json',
            headers: origin
              ? { 'access-control-allow-origin': origin, 'access-control-allow-credentials': 'true' }
              : { 'access-control-allow-origin': '*' },
            body: '{"status":"ok"}',
          }).catch(() => {});
          return;
        }

        try {
          const reqDomain = new URL(reqUrl).hostname;
          if (!reqDomain.endsWith(siteDomain) && reqDomain !== siteDomain) {
            thirdPartyRequests.push({
              url: reqUrl,
              type: request.resourceType(),
              domain: reqDomain,
            });
          }
        } catch { /* invalid URL, skip */ }
        request.continue();
      });

      // Navigate to the page
      const waitUntil = waitForNetworkIdle ? 'networkidle2' : 'domcontentloaded';
      await page.goto(url, {
        waitUntil,
        timeout: this.timeout,
      });

      // Extra wait to let deferred scripts set cookies
      if (extraWait > 0) {
        await new Promise(r => setTimeout(r, extraWait));
      }

      const pre = await this._collect(page, thirdPartyRequests, siteDomain);

      // Optional live TCF probe in the same page — timing and field checks
      // against the __tcfapi implementation the page actually runs.
      let tcf;
      if (options.probeTcf) {
        try {
          tcf = await probeTcfInPage(page, options.tcfMaxWait || 6000);
        } catch (err) {
          tcf = { api_present: false, probe_error: err.message };
        }
      }

      const result = {
        url,
        scanned_at: new Date().toISOString(),
        duration_ms: 0,
        cookies: pre.cookies,
        localStorage: pre.localStorage,
        beacons: pre.beacons,
        ...(tcf !== undefined ? { tcf } : {}),
        stats: pre.stats,
      };

      if (twoPhase) {
        const finalUrl = page.url() || url;
        let post;
        // Entirely separate from the first pass: whatever goes wrong after
        // consent, the before-consent result is already safe in `pre`.
        try {
          const nextLog = [];
          post = await this._postConsent(page, {
            finalUrl,
            siteDomain,
            extraWait,
            consentContext: options.consentContext || {},
            beforeConsent: pre,
            startPhaseLog: () => { thirdPartyRequests = nextLog; return nextLog; },
          });
        } catch (err) {
          post = { error: err.message, consent_method: 'error', verified: false, reason: 'The after-consent pass failed: ' + err.message };
        }

        result.phases = {
          [PRE_CONSENT]: { ...pre, final_url: finalUrl },
          [POST_CONSENT]: post,
        };
        result.blocked_requests = blockedRequests;
      }

      result.duration_ms = Date.now() - startTime;

      return result;
    } catch (err) {
      return {
        url,
        scanned_at: new Date().toISOString(),
        duration_ms: Date.now() - startTime,
        error: err.message,
        cookies: [],
        localStorage: [],
        beacons: [],
        stats: { total_cookies: 0, first_party_cookies: 0, third_party_cookies: 0,
                 http_only_cookies: 0, local_storage_keys: 0, third_party_requests: 0, beacons: 0 },
      };
    } finally {
      // Closing a context fires pagehide, and the runtime answers pagehide by
      // re-sending everything still queued with sendBeacon — possibly after
      // interception is gone. Empty the queue and leave the page first.
      if (shielded) {
        await clearRuntimeQueue(page);
        await page.goto('about:blank', { timeout: 5000 }).catch(() => {});
      }
      await context.close();
    }
  }

  /**
   * Cookies, localStorage keys and beacons as the page holds them right now.
   * The shape is the one callers have always received.
   */
  async _collect(page, thirdPartyRequests, siteDomain) {
    // Collect cookies from the browser context (includes HttpOnly, Secure, etc.)
    const cdpSession = await page.createCDPSession();
    let browserCookies;
    try {
      ({ cookies: browserCookies } = await cdpSession.send('Network.getAllCookies'));
    } finally {
      await cdpSession.detach().catch(() => {});
    }

    // Collect localStorage
    const localStorageKeys = await page.evaluate(() => {
      try {
        return Object.keys(localStorage);
      } catch {
        return [];
      }
    });

    // Process cookies
    const cookies = browserCookies.map(cookie => ({
      name: cookie.name,
      domain: cookie.domain,
      path: cookie.path || '/',
      value_length: (cookie.value || '').length,
      expires: cookie.expires > 0
        ? new Date(cookie.expires * 1000).toISOString()
        : 'session',
      expiry_duration: cookie.expires > 0
        ? formatDuration(cookie.expires - Date.now() / 1000)
        : 'session',
      http_only: cookie.httpOnly || false,
      secure: cookie.secure || false,
      same_site: cookie.sameSite || 'None',
      is_first_party: isFirstParty(cookie.domain, siteDomain),
    }));

    // Classify beacons from third-party requests
    const beacons = classifyBeacons(thirdPartyRequests, siteDomain);

    return {
      cookies,
      localStorage: localStorageKeys,
      beacons,
      stats: {
        total_cookies: cookies.length,
        first_party_cookies: cookies.filter(c => c.is_first_party).length,
        third_party_cookies: cookies.filter(c => !c.is_first_party).length,
        http_only_cookies: cookies.filter(c => c.http_only).length,
        local_storage_keys: localStorageKeys.length,
        third_party_requests: thirdPartyRequests.length,
        beacons: beacons.length,
      },
    };
  }

  /**
   * The second pass: give consent the way a visitor would, reload as a
   * returning consented visitor, and collect again.
   *
   * Consent goes through our own banner whenever it can, because only the
   * runtime's Accept All writes a TCF string with vendor consent, and most
   * sites run TCF. Fallbacks are recorded honestly; `classifyOutcome` decides
   * whether the result can stand in for the site's inventory.
   */
  async _postConsent(page, { finalUrl, siteDomain, extraWait, consentContext, beforeConsent, startPhaseLog }) {
    const bannerDelay = Math.max(0, Math.min(parseInt(consentContext.banner_delay_ms, 10) || 0, 10000));
    const consentSharing = consentContext.consent_sharing_enabled === undefined
      ? true
      : Number(consentContext.consent_sharing_enabled) === 1;

    const diag = {
      runtime: await detectRuntime(page),
      clicked: null,
      seeded: false,
      consentAfterReload: null,
      tcf: false,
      tcString: false,
      bannerShown: false,
      consentGiven: null,
      blockedElements: null,
    };

    // No banner script to satisfy: what the first pass saw is already
    // everything the page sets.
    if (diag.runtime === 'absent' || diag.runtime === 'paused' || diag.runtime === 'loader_only') {
      const outcome = classifyOutcome(diag);
      const reuse = diag.runtime === 'loader_only' ? {} : beforeConsent;

      return { ...reuse, final_url: finalUrl, consent_method: outcome.method, verified: outcome.verified, reason: outcome.reason, diagnostics: diag };
    }

    diag.tcf = await page.evaluate(() => typeof window.__tcfapi === 'function').catch(() => false);

    // 1. Our own Accept All. It is rendered twice (banner and the hidden
    //    preference centre) and appears only after geo lookup, translations,
    //    the vendor list on TCF sites and the banner delay.
    const acceptTimeout = Math.min(bannerDelay + 10000, 25000);
    diag.bannerShown = await waitForBanner(page, acceptTimeout);
    if (diag.bannerShown && await clickVisible(page, '.btn-cookieAccept')) {
      diag.clicked = 'accept';
      await settleAfterConsent(page);
    }

    // 2. Accept All switched off: tick every category and save.
    if (await readCookie(page, 'conzentConsent') !== 'true' && diag.bannerShown) {
      const saved = await page.evaluate(() => {
        const save = document.querySelector('#cookieSavePreferences');
        if (!save) return false;
        document.querySelectorAll('input[name="gdprPrefItem"]').forEach((input) => {
          if (!input.disabled) input.checked = true;
        });
        save.click();
        return true;
      }).catch(() => false);
      if (saved) {
        diag.clicked = 'save_all';
        await settleAfterConsent(page);
      }
    }

    // 3. Nothing on the page to click: set consent directly.
    if (await readCookie(page, 'conzentConsent') !== 'true') {
      await page.setCookie(...buildSeedCookies({ finalUrl, siteDomain, consentSharing }));
      diag.seeded = true;
    }

    // Reload as a returning visitor who has consented: scripts that only look
    // at consent when the page starts are the ones a declaration has to list.
    await clearRuntimeQueue(page);
    const log = startPhaseLog();
    await page.goto(finalUrl, { waitUntil: 'domcontentloaded', timeout: this.timeout });
    await page.waitForNetworkIdle({ idleTime: 500, timeout: 15000 }).catch(() => {});
    if (extraWait > 0) {
      await new Promise(r => setTimeout(r, extraWait));
    }

    const post = await this._collect(page, log, siteDomain);

    diag.consentAfterReload = await readCookie(page, 'conzentConsent');
    diag.tcString = diag.tcf ? Boolean(await readCookie(page, 'euconsent-v2')) : false;
    Object.assign(diag, await page.evaluate(() => ({
      consentGiven: typeof window._cnzConsentGiven === 'undefined' ? null : window._cnzConsentGiven === true,
      blockedElements: Array.isArray(window._cnzBlockedEls) ? window._cnzBlockedEls.length : null,
    })).catch(() => ({})));

    const outcome = classifyOutcome(diag);

    return { ...post, final_url: finalUrl, consent_method: outcome.method, verified: outcome.verified, reason: outcome.reason, diagnostics: diag };
  }
}

// ── Consent pass helpers ────────────────────────────────

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

/**
 * Whether the page runs our banner, and in what state.
 * `_cnzVersion` is the bundle's first line; `_cnzConsentGiven` is set by the loader.
 */
async function detectRuntime(page) {
  return page.evaluate(() => {
    const loader = typeof window._cnzConsentGiven !== 'undefined';
    const bundle = typeof window._cnzVersion !== 'undefined';
    if (!loader && !bundle) return 'absent';
    // The pageview kill switch releases everything and never loads the bundle.
    if (loader && !bundle) return window._cnzConsentGiven === true ? 'paused' : 'loader_only';
    return 'present';
  }).catch(() => 'absent');
}

/**
 * True once our banner has rendered a consent control, within the timeout.
 * GDPR banners carry Accept All and Save; CCPA banners carry only their
 * opt-out controls, and for those the seeded path is the way in.
 */
async function waitForBanner(page, timeout) {
  try {
    await page.waitForFunction(
      () => document.querySelector('.btn-cookieAccept, #cookieSavePreferences, #cookieOptSavePreferences, #donotselllink') !== null,
      { timeout, polling: 250 },
    );
    return true;
  } catch {
    return false;
  }
}

/**
 * Click the visible copy of a control, or the first one. A DOM click, because
 * puppeteer's element click refuses the hidden preference-centre duplicate.
 */
async function clickVisible(page, selector) {
  return page.evaluate((sel) => {
    const all = Array.from(document.querySelectorAll(sel));
    if (all.length === 0) return false;
    const visible = all.find((el) => {
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
    });
    (visible || all[0]).click();
    return true;
  }, selector).catch(() => false);
}

/**
 * After a consent click the runtime may reload the page itself (`reload_on`),
 * so wait for whichever comes first rather than racing its navigation.
 */
async function settleAfterConsent(page) {
  await Promise.race([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 8000 }).catch(() => null),
    sleep(2500),
  ]);
  await sleep(500);
}

/** The exact cookie by name. The runtime's own check is a substring match. */
async function readCookie(page, name) {
  let session;
  try {
    session = await page.createCDPSession();
    const { cookies } = await session.send('Network.getAllCookies');
    const found = cookies.find(c => c.name === name);
    return found ? found.value : null;
  } catch {
    return null;
  } finally {
    if (session) await session.detach().catch(() => {});
  }
}

/** Drop the runtime's persisted send queue so nothing is replayed later. */
async function clearRuntimeQueue(page) {
  if (!page) return;
  await page.evaluate(() => {
    try {
      Object.keys(localStorage).filter(k => k.indexOf('cnzq:') === 0).forEach(k => localStorage.removeItem(k));
    } catch { /* storage unavailable */ }
  }).catch(() => {});
}

// ── Helpers ─────────────────────────────────────────────

/**
 * Evaluate the page's live IAB TCF CMP API: wait for cmpStatus 'loaded'
 * (bounded), then time a getTCData call and inspect the returned TCData
 * for Google's enableAdvertiserConsentMode flag. Everything runs inside
 * the page so the numbers are what a real Google tag would observe.
 */
async function probeTcfInPage(page, maxWaitMs) {
  return page.evaluate(async (maxWaitMs) => {
    const out = {
      api_present: typeof window.__tcfapi === 'function',
      cmp_status: null,
      event_status: null,
      tc_string_present: false,
      gdpr_applies: null,
      flag_present: false,
      flag_type: null,
      flag_value: null,
      response_ms: null,
      cmp_wait_ms: 0,
    };

    if (!out.api_present) return out;

    const ping = () => new Promise((resolve) => {
      const timer = setTimeout(() => resolve(null), 1000);
      try {
        window.__tcfapi('ping', 2, (p) => { clearTimeout(timer); resolve(p); });
      } catch { clearTimeout(timer); resolve(null); }
    });

    // Wait for the CMP to leave stub/loading, up to maxWaitMs.
    const t0 = performance.now();
    let p = await ping();
    while ((!p || p.cmpStatus !== 'loaded') && (performance.now() - t0) < maxWaitMs) {
      await new Promise(r => setTimeout(r, 150));
      p = await ping();
    }
    out.cmp_wait_ms = Math.round(performance.now() - t0);
    out.cmp_status = p && p.cmpStatus ? p.cmpStatus : null;

    // Time getTCData the way a tag would experience it.
    const t1 = performance.now();
    const answer = await new Promise((resolve) => {
      const timer = setTimeout(() => resolve(null), 5000);
      try {
        window.__tcfapi('getTCData', 2, (data, success) => {
          clearTimeout(timer);
          resolve({ data, success });
        });
      } catch { clearTimeout(timer); resolve(null); }
    });
    out.response_ms = Math.round(performance.now() - t1);

    if (answer && answer.data && typeof answer.data === 'object') {
      const d = answer.data;
      out.cmp_status = typeof d.cmpStatus === 'string' ? d.cmpStatus : out.cmp_status;
      out.event_status = typeof d.eventStatus === 'string' ? d.eventStatus : null;
      out.tc_string_present = typeof d.tcString === 'string' && d.tcString.length > 0;
      out.gdpr_applies = typeof d.gdprApplies === 'boolean' ? d.gdprApplies : null;
      out.flag_present = Object.prototype.hasOwnProperty.call(d, 'enableAdvertiserConsentMode');
      if (out.flag_present) {
        out.flag_type = typeof d.enableAdvertiserConsentMode;
        out.flag_value = d.enableAdvertiserConsentMode === true
          ? true
          : (d.enableAdvertiserConsentMode === false ? false : null);
      }
    }

    return out;
  }, maxWaitMs);
}

function isFirstParty(cookieDomain, siteDomain) {
  const clean = cookieDomain.replace(/^\./, '');
  return clean === siteDomain || siteDomain.endsWith('.' + clean) || clean.endsWith('.' + siteDomain);
}

function formatDuration(seconds) {
  if (seconds <= 0) return 'session';
  const days = Math.floor(seconds / 86400);
  if (days >= 365) return `${Math.floor(days / 365)} year${Math.floor(days / 365) > 1 ? 's' : ''}`;
  if (days >= 30) return `${Math.floor(days / 30)} month${Math.floor(days / 30) > 1 ? 's' : ''}`;
  if (days >= 1) return `${days} day${days > 1 ? 's' : ''}`;
  const hours = Math.floor(seconds / 3600);
  if (hours >= 1) return `${hours} hour${hours > 1 ? 's' : ''}`;
  const minutes = Math.floor(seconds / 60);
  return `${minutes} minute${minutes > 1 ? 's' : ''}`;
}

function classifyBeacons(requests, siteDomain) {
  const seen = new Map();

  for (const req of requests) {
    // Only track scripts, images (pixels), and XHR/fetch
    if (!['script', 'image', 'xhr', 'fetch', 'ping'].includes(req.type)) continue;

    const domain = req.domain;
    if (seen.has(domain)) continue;

    // Match against known beacon patterns
    let category = null;
    for (const [pattern, cat] of Object.entries(BEACON_PATTERNS)) {
      if (domain.includes(pattern) || req.url.includes(pattern)) {
        category = cat;
        break;
      }
    }

    // Unknown third-party script/pixel = potentially tracking
    if (!category && (req.type === 'script' || req.type === 'image')) {
      category = 'unclassified';
    }

    if (category) {
      seen.set(domain, {
        domain,
        url: req.url.substring(0, 500), // Truncate long URLs
        type: req.type,
        category,
      });
    }
  }

  return Array.from(seen.values());
}

module.exports = { CookieScanner };
