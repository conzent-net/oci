/**
 * OCI Cookie Scanner — HTTP API Server
 *
 * Endpoints:
 *   GET  /health           — Health check (no auth)
 *   POST /scan             — Scan a URL for cookies (auth required)
 *   POST /scan/batch       — Scan multiple URLs (auth required)
 *   GET  /status/:jobId    — Check scan job status (auth required)
 *
 * Auth: X-Api-Key header must match SCANNER_API_KEY env var.
 *
 * Environment:
 *   PORT             — HTTP port (default 8300)
 *   SCANNER_API_KEY  — Required API key for authentication
 *   MAX_CONCURRENT   — Max concurrent browser scans (default 3)
 *   SCAN_TIMEOUT     — Per-page timeout in ms (default 60000)
 */

const http = require('http');
const { CookieScanner } = require('./lib/scanner');

const PORT = parseInt(process.env.PORT || '8300', 10);
const API_KEY = process.env.SCANNER_API_KEY || '';
const MAX_CONCURRENT = parseInt(process.env.MAX_CONCURRENT || '3', 10);
const SCAN_TIMEOUT = parseInt(process.env.SCAN_TIMEOUT || '60000', 10);

// In-memory job store (jobs are ephemeral — results pushed to main app via callback)
const jobs = new Map();
let jobCounter = 0;

// Scanner instance (manages browser pool)
const scanner = new CookieScanner({ maxConcurrent: MAX_CONCURRENT, timeout: SCAN_TIMEOUT });

// ── HTTP Server ─────────────────────────────────────────

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const method = req.method;

  // CORS headers for flexibility
  res.setHeader('Content-Type', 'application/json');

  try {
    // Health check — no auth
    if (method === 'GET' && url.pathname === '/health') {
      return respond(res, 200, {
        status: 'ok',
        scanner: 'oci-scanner',
        version: '1.0.0',
        activeJobs: scanner.activeCount,
        maxConcurrent: MAX_CONCURRENT,
        uptime: Math.floor(process.uptime()),
      });
    }

    // Auth check for all other endpoints
    if (!authenticateRequest(req)) {
      return respond(res, 401, { error: 'Invalid or missing API key' });
    }

    // POST /scan — Single URL scan (synchronous)
    if (method === 'POST' && url.pathname === '/scan') {
      const body = await readBody(req);
      if (!body.url) {
        return respond(res, 400, { error: 'url is required' });
      }

      const result = await scanner.scanUrl(body.url, body.options || {});
      return respond(res, 200, { success: true, data: result });
    }

    // POST /scan/batch — Multi-URL scan (async with job ID)
    if (method === 'POST' && url.pathname === '/scan/batch') {
      const body = await readBody(req);
      if (!body.urls || !Array.isArray(body.urls) || body.urls.length === 0) {
        return respond(res, 400, { error: 'urls array is required' });
      }

      const jobId = String(++jobCounter);
      const callbackUrl = body.callback_url || null;
      const scanId = body.scan_id || null;

      jobs.set(jobId, {
        id: jobId,
        scanId,
        status: 'running',
        total: body.urls.length,
        completed: 0,
        failed: 0,
        results: [],
        startedAt: new Date().toISOString(),
      });

      // Process asynchronously
      processBatchScan(jobId, body.urls, body.options || {}, callbackUrl).catch(err => {
        console.error(`Batch job ${jobId} error:`, err.message);
      });

      return respond(res, 202, {
        success: true,
        job_id: jobId,
        total: body.urls.length,
        message: 'Scan queued',
      });
    }

    // GET /status/:jobId — Check job status
    if (method === 'GET' && url.pathname.startsWith('/status/')) {
      const jobId = url.pathname.split('/status/')[1];
      const job = jobs.get(jobId);

      if (!job) {
        return respond(res, 404, { error: 'Job not found' });
      }

      return respond(res, 200, {
        success: true,
        data: {
          id: job.id,
          scan_id: job.scanId,
          status: job.status,
          total: job.total,
          completed: job.completed,
          failed: job.failed,
          results: job.status === 'completed' ? job.results : [],
          started_at: job.startedAt,
          completed_at: job.completedAt || null,
        },
      });
    }

    // 404
    return respond(res, 404, { error: 'Not found' });

  } catch (err) {
    console.error('Request error:', err);
    return respond(res, 500, { error: 'Internal server error' });
  }
});

// ── Batch Processing ────────────────────────────────────

async function processBatchScan(jobId, urls, options, callbackUrl) {
  const job = jobs.get(jobId);

  for (const url of urls) {
    try {
      const result = await scanner.scanUrl(url, options);
      job.results.push(result);
      job.completed++;
    } catch (err) {
      job.results.push({
        url,
        error: err.message,
        cookies: [],
        localStorage: [],
        beacons: [],
      });
      job.failed++;
      job.completed++;
    }
  }

  job.status = 'completed';
  job.completedAt = new Date().toISOString();

  // Send results to callback URL if provided
  if (callbackUrl) {
    try {
      await postCallback(callbackUrl, {
        job_id: jobId,
        scan_id: job.scanId,
        status: 'completed',
        total: job.total,
        completed: job.completed,
        failed: job.failed,
        results: job.results,
      });
    } catch (err) {
      console.error(`Callback to ${callbackUrl} failed:`, err.message);
    }
  }

  // Clean up job after 1 hour
  setTimeout(() => jobs.delete(jobId), 3600000);
}

// ── Helpers ─────────────────────────────────────────────

function authenticateRequest(req) {
  if (!API_KEY) return true; // No key configured = open (dev mode)
  const provided = req.headers['x-api-key'] || '';
  return provided === API_KEY;
}

function respond(res, status, data) {
  res.writeHead(status);
  res.end(JSON.stringify(data));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    req.on('data', chunk => chunks.push(chunk));
    req.on('end', () => {
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString()));
      } catch {
        reject(new Error('Invalid JSON body'));
      }
    });
    req.on('error', reject);
  });
}

async function postCallback(url, data) {
  const payload = JSON.stringify(data);
  const parsed = new URL(url);

  const options = {
    hostname: parsed.hostname,
    port: parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
    path: parsed.pathname + parsed.search,
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Content-Length': Buffer.byteLength(payload),
    },
  };

  const lib = parsed.protocol === 'https:' ? require('https') : require('http');

  return new Promise((resolve, reject) => {
    const req = lib.request(options, (res) => {
      res.resume();
      resolve();
    });
    req.on('error', reject);
    req.setTimeout(10000, () => { req.destroy(); reject(new Error('Callback timeout')); });
    req.write(payload);
    req.end();
  });
}

// ── Startup ─────────────────────────────────────────────

(async () => {
  await scanner.initialize();

  server.listen(PORT, '0.0.0.0', () => {
    console.log(`OCI Scanner listening on port ${PORT}`);
    console.log(`Max concurrent scans: ${MAX_CONCURRENT}`);
    console.log(`Scan timeout: ${SCAN_TIMEOUT}ms`);
    console.log(`API key: ${API_KEY ? 'configured' : 'NOT SET (open mode)'}`);
  });
})();

// Graceful shutdown
process.on('SIGTERM', async () => {
  console.log('Shutting down...');
  server.close();
  await scanner.close();
  process.exit(0);
});

process.on('SIGINT', async () => {
  console.log('Shutting down...');
  server.close();
  await scanner.close();
  process.exit(0);
});
