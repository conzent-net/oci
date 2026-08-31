import { useConsent } from '@getconzent/consent/react';

export function App() {
  const { consent, isAccepted, isGranted, openPreferences } = useConsent();

  return (
    <main style={{ fontFamily: 'sans-serif', maxWidth: 640, margin: '40px auto' }}>
      <h1>Conzent SDK — React example</h1>

      <p>
        Consent given: <strong>{String(isAccepted)}</strong>
      </p>
      <p>
        Granted categories: <code>{consent ? consent.join(', ') : '(none yet)'}</code>
      </p>

      {isGranted('analytics') ? (
        <p>✅ Analytics is granted — tracking code may run.</p>
      ) : (
        <p>🚫 Analytics is not granted — nothing loads.</p>
      )}

      <button onClick={openPreferences}>Cookie preferences</button>
    </main>
  );
}
