/**
 * @getconzent/consent — typed loader for the Conzent CMP.
 *
 * A thin contract mirror of the one-tag embed (docs/embed-snippet.md) and
 * the window.Conzent API (docs/js-api.md). It injects the same single
 * script tag the copy-paste install uses and resolves when the API is up.
 */

export interface ConsentPayload {
  necessary: boolean;
  functional: boolean;
  analytics: boolean;
  /** The documented key for the marketing category. */
  marketing: boolean;
  /** @deprecated Historical alias of `marketing` — always identical. */
  advertisement: boolean;
  preferences: boolean;
  performance: boolean;
  unclassified: boolean;
  /** Meta pixel state. */
  meta: 'grant' | 'revoke';
  [category: string]: boolean | string;
}

export type ConzentEventName =
  | 'banner_shown'
  | 'consent_saved'
  | 'preference_center_opened'
  | 'preference_center_closed';

export interface ConsentSavedDetail {
  action: 'accept_all' | 'reject_all' | 'save_preferences';
  categories: string[];
}

export interface ConzentApi {
  version: string;
  /** Reopen the consent banner. */
  reinit(): void;
  isAccepted(): boolean;
  getPreferences(): string[] | null;
  isPreferenceAccepted(slug: string): boolean;
  getConsent(): ConsentPayload;
  on(event: ConzentEventName | string, cb: (detail: unknown, e: Event) => void): void;
  off(event: ConzentEventName | string, cb: (detail: unknown, e: Event) => void): void;
}

export interface LoadOptions {
  /** The site's website key (Conzent dashboard → Install Script). */
  key: string;
  /** Conzent server origin. Default: https://app.getconzent.com */
  server?: string;
  /** CSP nonce — applied as both `nonce` and `data-nonce` (see docs/csp.md). */
  nonce?: string;
  /** Renamed GTM dataLayer (the embed's data-dl attribute). */
  dataLayer?: string;
  /** How long to wait for the CMP before rejecting. Default 20000 ms. */
  timeoutMs?: number;
}

declare global {
  interface Window {
    Conzent?: ConzentApi;
  }
}

const DEFAULT_SERVER = 'https://app.getconzent.com';

/** The loader URL for a given server origin (exported for tests). */
export function snippetSrc(server?: string): string {
  return (server || DEFAULT_SERVER).replace(/\/+$/, '') + '/c/consent.js';
}

let pending: Promise<ConzentApi> | null = null;

/**
 * Inject the Conzent embed (idempotently) and resolve with the
 * window.Conzent API once the bundle is up.
 *
 * SSR-safe to import; calling it outside a browser rejects — call it from
 * an effect. Note the tag is injected at runtime, AFTER hydration: for the
 * strongest blocking guarantees on content-heavy pages, prefer putting the
 * one-tag embed (or <ConzentScript> on Next.js) in the document head and
 * use this loader just to await the API.
 */
export function loadConzent(opts: LoadOptions): Promise<ConzentApi> {
  if (typeof document === 'undefined' || typeof window === 'undefined') {
    return Promise.reject(
      new Error('loadConzent() needs a browser — call it from an effect, not during SSR'),
    );
  }
  if (!opts || !opts.key) {
    return Promise.reject(new Error('loadConzent() needs a website key'));
  }
  if (window.Conzent) return Promise.resolve(window.Conzent);
  if (pending) return pending;

  pending = new Promise<ConzentApi>((resolve, reject) => {
    const existing = document.querySelector('script[src*="/c/consent.js"]');
    if (!existing) {
      const s = document.createElement('script');
      s.src = snippetSrc(opts.server);
      s.setAttribute('data-key', opts.key);
      if (opts.dataLayer) s.setAttribute('data-dl', opts.dataLayer);
      if (opts.nonce) {
        s.nonce = opts.nonce;
        s.setAttribute('data-nonce', opts.nonce);
      }
      s.onerror = () => {
        pending = null;
        reject(new Error('Conzent loader failed to load from ' + s.src));
      };
      (document.head || document.documentElement).appendChild(s);
    }

    const started = Date.now();
    const timeoutMs = opts.timeoutMs ?? 20000;
    const tick = () => {
      if (window.Conzent) {
        resolve(window.Conzent);
        return;
      }
      if (Date.now() - started > timeoutMs) {
        pending = null;
        reject(new Error('Conzent did not become ready within ' + timeoutMs + 'ms'));
        return;
      }
      setTimeout(tick, 50);
    };
    tick();
  });

  return pending;
}

/** Granted category slugs from the STABLE cookie contract (docs/js-api.md). */
export function readConsentCookie(cookieString?: string): string[] | null {
  const source =
    cookieString ?? (typeof document !== 'undefined' ? document.cookie : '');
  const match = /(?:^|;\s*)conzentConsentPrefs=([^;]*)/.exec(source || '');
  if (!match) return null;
  try {
    const parsed = JSON.parse(decodeURIComponent(match[1]));
    return Array.isArray(parsed) ? parsed : null;
  } catch {
    return null;
  }
}
