/**
 * React bindings for @getconzent/consent.
 */

import { useCallback, useMemo, useSyncExternalStore } from 'react';
import { readConsentCookie } from './index';
import type { ConzentApi } from './index';

const CONSENT_EVENTS = ['conzent:consent_saved', 'conzentck_consent_update'];

function subscribe(onStoreChange: () => void): () => void {
  for (const ev of CONSENT_EVENTS) document.addEventListener(ev, onStoreChange);
  return () => {
    for (const ev of CONSENT_EVENTS) document.removeEventListener(ev, onStoreChange);
  };
}

// The snapshot must be referentially stable between consent changes, so it
// is the raw cookie slice, not a fresh object per call.
function getSnapshot(): string {
  const m = /(?:^|;\s*)conzentConsentPrefs=([^;]*)/.exec(document.cookie || '');
  return m ? m[1] : '';
}

function getServerSnapshot(): string {
  return '';
}

export interface UseConsentResult {
  /** Granted category slugs, null before the visitor has chosen. */
  consent: string[] | null;
  /** Whether a consent choice exists. */
  isAccepted: boolean;
  /** Whether one category is granted. */
  isGranted: (slug: string) => boolean;
  /** Open the preference center (revisit consent). */
  openPreferences: () => void;
}

/**
 * Live consent state. Re-renders when the visitor saves a choice.
 * Reads the STABLE cookie contract, so it works even before the API loads.
 */
export function useConsent(): UseConsentResult {
  const raw = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);

  const consent = useMemo(
    () => (raw === '' ? null : readConsentCookie('conzentConsentPrefs=' + raw)),
    [raw],
  );

  const isGranted = useCallback(
    (slug: string) => (consent ? consent.includes(slug) : false),
    [consent],
  );

  const openPreferences = useCallback(() => {
    const w = window as Window & { revisitCnzConsent?: () => void; Conzent?: ConzentApi };
    if (typeof w.revisitCnzConsent === 'function') w.revisitCnzConsent();
    else if (w.Conzent) w.Conzent.reinit();
  }, []);

  return { consent, isAccepted: consent !== null, isGranted, openPreferences };
}
