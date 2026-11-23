/**
 * Cloudflare Worker: a branded page while the origin is down.
 *
 * Sits in front of the app hostname and passes every request straight
 * through. When the origin answers 5xx to a page navigation — or cannot be
 * reached at all, which Cloudflare reports to a Worker as a 52x — it swaps
 * the raw error for the same maintenance page nginx serves, as a 503 with
 * Retry-After. Nothing else is touched.
 *
 * Why it exists: nginx already serves `docker/nginx/maintenance.html` when
 * PHP-FPM is down, but it cannot help when nginx itself is being recreated
 * during a deploy or the proxy has lost its backend. Those are exactly the
 * moments customers were shown Traefik's "No server available" and
 * Cloudflare's bare 502 page (2026-09-05, twenty minutes of it).
 *
 * What it deliberately does NOT do:
 *
 * - It never rewrites non-HTML responses. API calls, scripts, JSON and
 *   monitors get the origin's real status, so the banner's offline queue
 *   (which keys on status < 500) and uptime checks keep working.
 * - It is not bound to the banner paths at all. `/c/*`, `/sites_data/*`,
 *   `/iab/*`, `/cmp/*` and `/api/*` carry explicit no-Worker routes, so the
 *   high-volume traffic from every customer site never invokes it and the
 *   edge cache in front of those paths is untouched.
 *
 * `?cnz-maintenance-preview=1` on any bound URL renders the page without an
 * outage, so it can be checked after a change. It serves a static page and
 * nothing else, so there is nothing to abuse.
 *
 * The HTML is inlined at build time from docker/nginx/maintenance.html by
 * scripts/cloudflare/build-maintenance-worker.mjs, so there is one copy of
 * the page. Deploy the BUILT output, never this file.
 */

const MAINTENANCE_HTML = `__MAINTENANCE_HTML__`;

const RETRY_AFTER_SECONDS = 30;

function wantsHtml(request) {
  if (request.method !== 'GET') {
    return false;
  }

  const accept = request.headers.get('accept') || '';

  return accept.includes('text/html');
}

function maintenanceResponse(reason) {
  return new Response(MAINTENANCE_HTML, {
    status: 503,
    headers: {
      'content-type': 'text/html; charset=utf-8',
      'cache-control': 'no-store',
      'retry-after': String(RETRY_AFTER_SECONDS),
      'x-conzent-maintenance': reason,
    },
  });
}

export default {
  async fetch(request) {
    const url = new URL(request.url);

    if (url.searchParams.get('cnz-maintenance-preview') === '1') {
      return maintenanceResponse('preview');
    }

    let response;

    try {
      response = await fetch(request);
    } catch (error) {
      // The origin could not be reached at all.
      return wantsHtml(request)
        ? maintenanceResponse('origin unreachable')
        : new Response('Bad gateway', { status: 502, headers: { 'cache-control': 'no-store' } });
    }

    // 501 is "not implemented", a real answer, not an outage.
    if (response.status < 500 || response.status === 501 || !wantsHtml(request)) {
      return response;
    }

    return maintenanceResponse('origin ' + response.status);
  },
};
