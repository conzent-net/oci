/**
 * Next.js binding for @getconzent/consent.
 */

import Script from 'next/script';
import { snippetSrc } from './index';

export interface ConzentScriptProps {
  /** The site's website key. */
  siteKey: string;
  /** Conzent server origin. Default: https://app.getconzent.com */
  server?: string;
  /** CSP nonce (see docs/csp.md). */
  nonce?: string;
  /** Renamed GTM dataLayer (the embed's data-dl attribute). */
  dataLayer?: string;
}

/**
 * The one-tag Conzent embed as a Next.js component. beforeInteractive keeps
 * the blocking guarantees of the copy-paste install: the TCF stub, the
 * Consent Mode defaults and the pre-consent blocker all run before any
 * other tag.
 *
 * App router: render it in the root layout. Pages router: in _document.
 */
export function ConzentScript({ siteKey, server, nonce, dataLayer }: ConzentScriptProps) {
  return (
    <Script
      src={snippetSrc(server)}
      data-key={siteKey}
      data-dl={dataLayer}
      nonce={nonce}
      data-nonce={nonce}
      strategy="beforeInteractive"
    />
  );
}
