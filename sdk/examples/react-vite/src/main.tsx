import { createRoot } from 'react-dom/client';
import { loadConzent } from '@getconzent/consent';
import { App } from './App';

// Point at your Conzent server + website key. Against the local dev stack:
// key a1b2c3d4e5f6a1b2c3d4e5f6, server http://localhost:8100 — and run this
// example on http://localhost (the fixture site's registered domain).
loadConzent({
  key: 'a1b2c3d4e5f6a1b2c3d4e5f6',
  server: 'http://localhost:8100',
}).catch((e) => console.warn('Conzent not available:', e.message));

createRoot(document.getElementById('root')!).render(<App />);
