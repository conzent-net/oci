#!/usr/bin/env node
/**
 * Inline docker/nginx/maintenance.html into the maintenance Worker and print
 * the deployable script to stdout.
 *
 *   node scripts/cloudflare/build-maintenance-worker.mjs > /tmp/worker.js
 *
 * The page has one source of truth (nginx serves the same file), so the
 * Worker source carries a placeholder and this fills it. Backticks, `${` and
 * backslashes in the HTML are escaped so the result is a valid template
 * literal whatever the page contains.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

const html = readFileSync(resolve(root, 'docker/nginx/maintenance.html'), 'utf8')
  .replace(/\\/g, '\\\\')
  .replace(/`/g, '\\`')
  .replace(/\$\{/g, '\\${');

const source = readFileSync(resolve(root, 'docker/cloudflare/maintenance-worker.js'), 'utf8');

if (!source.includes('__MAINTENANCE_HTML__')) {
  process.stderr.write('placeholder __MAINTENANCE_HTML__ not found in the Worker source\n');
  process.exit(1);
}

process.stdout.write(source.replace('__MAINTENANCE_HTML__', html));
