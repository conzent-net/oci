---
id: selfhost.scanner
title: Cookie scanner — setup, scaling and troubleshooting
area: Self-hosting
knowledgebase: Self-Hosting
url: /scans
menu_path: (server) — results appear under Compliance > Scans
edition: [self-hosted]
audience: [admin]
plan: any
tags: [scanner, puppeteer, chromium, scan-server, register, health, callback, scaling, worker, beacon-worker]
related: [cookies.scans, selfhost.cli, selfhost.configuration, selfhost.install]
source_files:
  - README.md
  - docker-compose.yml
  - bin/oci
  - .env.example
questions:
  - How do I set up cookie scanning on self-hosted Conzent?
  - Scans are stuck in queued state
  - How do I register a scan server?
  - How do I add more scanners?
  - What are the scanner's system requirements?
  - Scanner health check is failing
  - How do I scan from a different country?
  - What is SCANNER_CALLBACK_URL?
---

# Cookie scanner — setup, scaling and troubleshooting

## Where to find it

The scanner runs as a container in your stack. Its results appear in the app under
**Compliance → Scans**. Registration and health are CLI operations.

## What it does

Visits your websites with headless Chromium (via Puppeteer) and records cookies, localStorage and
network beacons. Results are posted back to the app, stored, and auto-categorised against the
global cookie reference database.

**It ships in the default stack.** Both the one-line installer and Docker Compose include it, and
the installer registers it. Nothing extra to install for a normal setup.

## How a scan flows

1. You trigger a scan from the app, or the scheduler does.
2. The app queues it and dispatches to a registered scan server.
3. The scanner loads each page with headless Chromium, collecting cookies, localStorage and
   beacons.
4. Results are posted back to `/api/v1/scan-webhook`.
5. Detected cookies are auto-categorised using the global reference database and pattern
   matching.

## Services in the stack

| Service | Purpose |
|---|---|
| `scanner` | Headless Chromium scanning server, internal port 8300 |
| `worker` | Processes scan jobs from the Redis queue |
| `scheduler` | Triggers scheduled and recurring scans |
| `beacon-worker` | Processes client-side beacon data in batches |

The scanner publishes **no host port** — the app reaches it over the Compose network at
`http://scanner:8300`.

## Configuration

| Variable | Default | Purpose |
|---|---|---|
| `SCANNER_API_KEY` | *(required)* | Shared secret between app and scanner. Must match on both sides |
| `SCANNER_URL` | `http://scanner:8300` | Where the app reaches the scanner |
| `SCANNER_CALLBACK_URL` | *(empty → `APP_URL`)* | Where the scanner posts results. **For the bundled scanner set `http://nginx`** so the round trip stays inside the Compose network. The `APP_URL` default requires the scanner to reach your public domain and loop back, which fails behind split-horizon DNS, hairpin NAT, or a proxy that blocks it. Remote scanners must use the public URL |
| `SCANNER_ALERT_EMAIL` | falls back to `MAIL_FROM_ADDRESS` | Where downtime and recovery alerts go |
| `SCANNER_ALERT_FAILURES` | `2` | Consecutive failed health checks before the first alert. The scheduler polls every 5 minutes |
| `MAX_CONCURRENT` | `5` | Parallel browser sessions per scanner container |
| `SCAN_TIMEOUT` | `60000` | Per-page timeout in milliseconds |

## Registering

Registration is required — an unregistered scanner receives no jobs. The installer does it for
you; re-run it after changing the URL or key:

```bash
docker compose exec app php bin/oci scanner:register
docker compose exec app php bin/oci scanner:health
```

Registration is **idempotent, keyed by URL**, so it is safe to re-run.

## Adding scanners

For faster scans or geographic distribution:

**1. On the new server**

```bash
curl -sSL https://getconzent.com/install-scanner | sh
```

Installs Docker if missing, fetches only the scanner, generates an API key, starts it, verifies
health and prints the registration command.

**2. On your Conzent server** — paste what it printed:

```bash
docker compose exec app php bin/oci scanner:register \
  --url=http://<server-ip>:8300 \
  --key=<generated-key> \
  --name="EU Scanner" \
  --region=eu
```

Confirm with `scanner:health`. The new scanner starts receiving jobs automatically.

Further options (`--key`, `--port`, `--max`, `--name`, `--uninstall`) and a manual,
no-pipe-to-shell alternative are in `docs/scanning.md`.

## Scaling on one server

```bash
docker compose -f docker-compose.scanner.yml up -d --scale scanner=3
```

## Requirements per scanner container

| Resource | Requirement |
|---|---|
| Memory | 512 MB minimum, 2 GB recommended |
| CPU | 1+ cores |
| Network | Outbound HTTPS to scan targets; inbound HTTP from the app on port 8300 |

## Scanner API

Authenticated with an `X-Api-Key` header.

| Method | Endpoint | Purpose |
|---|---|---|
| `GET` | `/health` | Health check, no auth. Returns status, active jobs, uptime |
| `POST` | `/scan` | Scan one URL synchronously; returns cookies, localStorage, beacons |
| `POST` | `/scan/batch` | Scan multiple URLs asynchronously; returns a job ID (202) |
| `GET` | `/status/:jobId` | Batch job progress — completed/failed counts and results |

Batch requests carry `scan_id`, `urls`, `callback_url` and options such as
`waitForNetworkIdle` and `extraWait`.

## Monitoring

The scheduler polls `/health` every 5 minutes. After `SCANNER_ALERT_FAILURES` consecutive
failures it emails `SCANNER_ALERT_EMAIL`, and emails again on recovery. This only works if the
scheduler is on cron and SMTP is configured.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| Scans stuck in **queued** | No registered scanner, or `worker` is not running. `scanner:health`, then check the worker container |
| Scans start but never complete | `SCANNER_CALLBACK_URL` unreachable from the scanner. For the bundled scanner set it to `http://nginx` |
| Health check fails | The scanner container is down, or `SCANNER_API_KEY` differs between app and scanner |
| Scans return no cookies | The target site blocks the scanner, is behind auth, or the domain does not resolve publicly |
| Scans time out | Raise `SCAN_TIMEOUT`, or lower `MAX_CONCURRENT` if the container is starved |
| Scanner runs out of memory | Chromium is memory-hungry. Give it 2 GB, or reduce `MAX_CONCURRENT` |

## Common questions

**Do I need to install the scanner separately?**
No. It is in the default stack and the installer registers it.

**Can I scan from a specific country?**
Deploy a scanner in that region and register it with `--region=eu` (or similar). Useful when your
site geo-redirects or serves different trackers per region.

**Does the scanner need a public IP?**
Not the bundled one — it talks to the app over the Compose network. A remote scanner needs the app
to reach it on port 8300, and needs to reach the app's public callback URL.

**How do I remove a scanner?**
Run the installer's `--uninstall` on that server; the app stops dispatching once health checks
fail. See `docs/scanning.md`.

**What does the beacon-worker do?**
It processes the cookie observations reported by real visitors' browsers through the consent
script — the `client`-tagged entries in the cookie list, separate from scan results.

## Related

- Knowledgebase: Cookies - Document: scans.md — running scans from the app
- Knowledgebase: Self-Hosting - Document: cli.md — `scanner:register`, `scanner:health`
- Knowledgebase: Self-Hosting - Document: configuration.md — the scanner variables
- Knowledgebase: Cookies - Document: cookies-list.md — what scans populate
