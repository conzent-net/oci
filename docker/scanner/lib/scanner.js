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
   * @param {boolean} options.acceptCookies - Try to click cookie accept buttons (default false)
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

    const thirdPartyRequests = [];
    const siteDomain = new URL(url).hostname.replace(/^www\./, '');

    try {
      // Set a realistic user agent
      await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
      );

      // Enable request interception to track third-party requests
      await page.setRequestInterception(true);
      page.on('request', (request) => {
        const reqUrl = request.url();
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

      // Collect cookies from the browser context (includes HttpOnly, Secure, etc.)
      const cdpSession = await page.createCDPSession();
      const { cookies: browserCookies } = await cdpSession.send('Network.getAllCookies');

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

      const duration = Date.now() - startTime;

      return {
        url,
        scanned_at: new Date().toISOString(),
        duration_ms: duration,
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
      await context.close();
    }
  }
}

// ── Helpers ─────────────────────────────────────────────

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
