import { defineConfig } from 'tsup';

export default defineConfig([
  {
    entry: { index: 'src/index.ts', react: 'src/react.ts', next: 'src/next.tsx' },
    format: ['esm', 'cjs'],
    dts: true,
    sourcemap: true,
    clean: true,
    external: ['react', 'next'],
  },
  {
    // Browser global for the e2e fixture page (docker/testsite/sdk.html)
    entry: { 'conzent-sdk': 'src/index.ts' },
    format: ['iife'],
    globalName: 'ConzentSDK',
    sourcemap: false,
    clean: false,
  },
]);
