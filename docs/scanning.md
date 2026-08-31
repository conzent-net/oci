# Cookie Scanning

Conzent ships with its own scanner: a headless Chromium service that visits your sites, records every cookie,
localStorage entry, and tracking beacon it finds, and feeds them back into your cookie list. It runs as part of the
standard stack — nothing extra to install.

---

## How a scan runs

1. You trigger a scan from the dashboard, or the scheduler triggers a recurring one.
2. The app queues it and picks a registered scan server.
3. The scanner loads each URL in headless Chromium, waits for network idle, and collects cookies, localStorage, and
   outbound requests.
4. It POSTs the results back to `/api/v1/scan-webhook`.
5. Detected cookies are auto-categorised (necessary, analytics, marketing, functional) against the global cookie
   reference database, then merged into the site's cookie list.

Four containers share the work:

| Service | Role |
| --- | --- |
| `scanner` | The Chromium scanning server, listening on port 8300 inside the Compose network |
| `worker` | Pulls queued scan jobs from Redis and dispatches them |
| `scheduler` | Fires recurring scans and polls scanner health every 5 minutes |
| `beacon-worker` | Drains beacons reported by live consent banners into the database |

The scanner publishes **no host port** — the app reaches it at `http://scanner:8300` over the Compose network. Nothing
from outside can talk to it.

---

## Checking that it works

```bash
docker compose exec app php bin/oci scanner:health
```

```
ID   NAME                   URL                            STATUS    NOTE
--------------------------------------------------------------------------------------------
1    Default Scanner        http://scanner:8300            healthy
```

`OFFLINE` with an `http=` code means the app reached something but got an error; `http=0` means it could not connect at
all — usually the container is down (`docker compose ps scanner`) or the API key does not match.

If the table is empty, nothing is registered:

```bash
docker compose exec app php bin/oci scanner:register
```

That registers the bundled scanner using `SCANNER_URL` and `SCANNER_API_KEY` from `.env`. The installer runs it for
you; re-running is safe, because registration is an idempotent upsert keyed by URL.

---

## The callback URL

The scanner needs a URL to POST results back to. By default that is your `APP_URL` — which means the scanner container
has to resolve your public domain and loop back into the server. On many self-hosted setups that fails: split-horizon
DNS, hairpin NAT, or a proxy that rejects the request.

If scans start but never finish (status stuck at *running*, no cookies appearing), keep the round trip internal. In
`.env`:

```ini
SCANNER_CALLBACK_URL=http://nginx
```

Then `docker compose up -d`. The scanner now posts straight to the nginx container over the Compose network, never
touching public DNS.

Leave it empty for remote scanners — those genuinely need your public URL.

---

## Tuning

In `.env`:

| Variable | Default | Effect |
| --- | --- | --- |
| `SCANNER_MAX_CONCURRENT` | `3` | Parallel browser sessions per scanner. Each needs roughly 300–500 MB |
| `SCANNER_TIMEOUT` | `60000` | Per-page timeout in milliseconds |
| `SCAN_TIMEOUT` | `30` | App-side timeout in seconds when talking to the scanner |
| `SCANNER_ALERT_EMAIL` | *(falls back to `MAIL_FROM_ADDRESS`)* | Where downtime and recovery alerts go |
| `SCANNER_ALERT_FAILURES` | `2` | Consecutive failed health checks before the first alert |

Downtime alerts need working SMTP — see [credentials.md](credentials.md).

Apply changes with `docker compose up -d`.

### Sizing

Chromium is the memory-hungry part. Per scanner container: **512 MB minimum, 2 GB recommended**, one or more CPU
cores, and outbound HTTPS to the sites being scanned. Lower `SCANNER_MAX_CONCURRENT` to `1` on a small VPS.

---

## Adding more scanners

Useful for scanning from a specific region, or spreading load across machines.

### One-line install

**On the new server**, run:

```bash
curl -sSL https://getconzent.com/install-scanner | sh
```

It installs Docker if missing, downloads only the scanner, generates an API key, starts the container, waits for the
health check, and prints the exact registration command — something like:

```
  ✓ Scanner ready

  URL:      http://203.0.113.20:8300
  API key:  7f3a9c2e4b1d8a06

  Register it — run this on your Conzent server:

    docker compose exec app php bin/oci scanner:register \
      --url=http://203.0.113.20:8300 \
      --key=7f3a9c2e4b1d8a06 --max=5
```

**On your Conzent server**, paste that command, then confirm:

```bash
docker compose exec app php bin/oci scanner:health
```

That is the whole flow. The new scanner starts receiving jobs immediately.

#### Options

| Flag | Purpose |
| --- | --- |
| `--key KEY` | Use a specific shared secret instead of a generated one |
| `--port PORT` | Publish on a different host port (default `8300`) |
| `--max N` | Parallel browser sessions (default `5`) |
| `--name NAME` | Label shown in the scan-server list |
| `--timeout MS` | Per-page timeout (default `60000`) |
| `--dir DIR` | Install directory (default `./conzent-scanner`) |
| `--uninstall` | Stop and remove this scanner |

```bash
# Named, on a non-default port, with a key you already have
curl -sSL https://getconzent.com/install-scanner | sh -s -- \
  --name "EU Scanner" --port 9300 --key "$SCANNER_API_KEY"
```

### Manual install

If you would rather not pipe a script to a shell, copy `docker/scanner/` to the remote machine and run:

```bash
cd docker/scanner
cat > .env <<'EOF'
SCANNER_API_KEY=a-shared-secret
SCANNER_PORT=8300
MAX_CONCURRENT=5
EOF
docker compose -f docker-compose.scanner.yml up -d --build
```

Then register it from your Conzent server:

```bash
docker compose exec app php bin/oci scanner:register \
  --url=http://203.0.113.20:8300 \
  --key=a-shared-secret \
  --name="EU Scanner" \
  --region=eu \
  --max=5
```

### Networking

A remote scanner must be able to reach your `APP_URL` to deliver results, and your app must be able to reach its
port. Restrict that port to your app server's IP — the API key is the only thing protecting it:

```bash
ufw allow from <your-app-server-ip> to any port 8300 proto tcp
```

### Scaling on one machine

```bash
docker compose -f docker-compose.scanner.yml up -d --scale scanner=3
```

Note that `-f docker-compose.scanner.yml` means Compose does **not** auto-merge `docker-compose.override.yml`. Change
the host port with `SCANNER_PORT` in `.env` rather than an override file.

---

## Scanner API

Every scanner exposes the same HTTP API, authenticated with the `X-Api-Key` header (except `/health`).

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `GET` | `/health` | Status, active jobs, uptime — no auth |
| `POST` | `/scan` | Scan one URL synchronously |
| `POST` | `/scan/batch` | Scan several URLs asynchronously; returns a job ID |
| `GET` | `/status/:jobId` | Batch progress |

```bash
curl -X POST http://scanner:8300/scan \
  -H "X-Api-Key: $SCANNER_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://example.com","options":{"waitForNetworkIdle":true,"extraWait":3000}}'
```

---

## Troubleshooting

| Symptom | Where to look |
| --- | --- |
| Scan never leaves *pending* | The `worker` container is down or the queue is stuck: `docker compose logs worker` |
| Scan starts, never completes | The callback cannot reach your app — set `SCANNER_CALLBACK_URL=http://nginx` (above) |
| `scanner:health` shows OFFLINE, `http=0` | Scanner container down: `docker compose ps scanner`, `docker compose logs scanner` |
| `scanner:health` shows OFFLINE, `http=401` | `SCANNER_API_KEY` differs between app and scanner. Re-run `scanner:register` after aligning them |
| Scanner restarts under load | Out of memory. Lower `SCANNER_MAX_CONCURRENT`, or raise `shm_size` in `docker-compose.override.yml` |
| Cookies found but not categorised | They are not in the reference database yet. Categorise them once in **Cookies**; the choice sticks |
| No beacons from live sites | `beacon-worker` is down: `docker compose logs beacon-worker` |
