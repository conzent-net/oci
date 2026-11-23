/**
 * @getconzent/consent — node:test units against the BUILT ESM output.
 * `npm test` in sdk/ builds first, then runs these.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const dist = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'dist');
const { loadConzent, snippetSrc, readConsentCookie } = await import(
  new URL('file://' + resolve(dist, 'index.js').replaceAll('\\', '/')).href
);

test('importing in node (no DOM) does not throw, loadConzent rejects cleanly', async () => {
  await assert.rejects(
    () => loadConzent({ key: 'abc' }),
    /needs a browser/,
    'SSR guard gone — importing the SDK in a server component would crash the render',
  );
});

test('snippetSrc builds the canonical loader URL', () => {
  assert.equal(snippetSrc(), 'https://app.getconzent.com/c/consent.js');
  assert.equal(snippetSrc('https://cmp.example.com/'), 'https://cmp.example.com/c/consent.js');
  assert.equal(snippetSrc('https://cmp.example.com///'), 'https://cmp.example.com/c/consent.js');
});

test('readConsentCookie parses the STABLE cookie contract', () => {
  const cookie = 'foo=1; conzentConsentPrefs=' + encodeURIComponent(JSON.stringify(['necessary', 'analytics'])) + '; bar=2';
  assert.deepEqual(readConsentCookie(cookie), ['necessary', 'analytics']);
  assert.equal(readConsentCookie('foo=1'), null);
  assert.equal(readConsentCookie('conzentConsentPrefs=%7Bbroken'), null, 'malformed cookie must return null, not throw');
});

test('build artifacts are complete (all three entries, types, browser global)', () => {
  for (const f of ['index.js', 'index.cjs', 'index.d.ts', 'react.js', 'react.d.ts', 'next.js', 'next.d.ts', 'conzent-sdk.global.js']) {
    assert.ok(existsSync(resolve(dist, f)), `dist/${f} missing`);
  }
});
