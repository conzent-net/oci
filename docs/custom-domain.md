# Custom Domain & HTTPS

A fresh install answers on `http://localhost` (or `http://<server-ip>`). This guide moves it to your own domain over
HTTPS — for example `https://consent.example.com`.

> **Do this before you add sites.** Every consent script Conzent generates has your domain baked into it. Changing the
> domain later works, but you must regenerate the scripts (step 5) *and* update the embed snippet on every website
> already using them (step 6).

---

## 1. Point DNS at your server

Create an `A` record for the hostname you want, pointing at your server's public IP:

```
consent.example.com.   A   203.0.113.10
```

Confirm it resolves before continuing:

```bash
dig +short consent.example.com
```

A subdomain of a domain you already own (`consent.`, `cmp.`, `privacy.`) is the usual choice — it keeps the consent
service on the same organisational domain as the sites it serves.

---

## 2. Set `APP_URL`

`APP_URL` is the single source of truth for every absolute URL Conzent produces: the consent script endpoints, the
embed snippet shown in the dashboard, password-reset links, and OAuth callbacks.

Edit `.env` in your install directory:

```ini
APP_URL=https://consent.example.com
```

Use the **exact** public URL, with the scheme and no trailing slash. If you are terminating TLS (step 3, and you
should), that means `https://`, even though the container itself still speaks plain HTTP internally.

> Setting `https://` in `APP_URL` also switches the "remember me" cookie to `Secure`, so it is never sent over
> plain HTTP.

New installs can do steps 1–2 in one shot:

```bash
curl -sSL https://getconzent.com/install | sh -s -- --domain consent.example.com
```

---

## 3. Terminate TLS

The bundled `nginx` container serves plain HTTP on `${APP_PORT:-80}`. It does not obtain or serve certificates. Pick
whichever of these matches your setup.

### Option A — Caddy in front (recommended if nothing else is on the box)

Caddy fetches and renews Let's Encrypt certificates automatically.

First free up port 80 for Caddy by moving the bundled nginx to another host port, in `.env`:

```ini
APP_PORT=8080
```

Then create **`docker-compose.override.yml`** next to `docker-compose.yml`:

```yaml
services:
  caddy:
    image: caddy:latest
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./docker/caddy/Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy-data:/data
      - caddy-config:/config
    depends_on:
      - nginx
    restart: unless-stopped

volumes:
  caddy-data:
  caddy-config:
```

And `docker/caddy/Caddyfile`:

```
consent.example.com {
    reverse_proxy nginx:80
}
```

Caddy reaches `nginx:80` over the Compose network, so the `8080` publish is only there to keep the container
reachable for local debugging — you can drop it entirely once TLS works.

Then:

```bash
docker compose up -d
```

Caddy issues the certificate on first request. Watch it happen with `docker compose logs -f caddy`.

> **Why an override file?** `docker-compose.override.yml` is merged automatically by Docker Compose, is listed in
> `.gitignore`, and is *not* tracked by git — so `scripts/install.sh --update` (which runs `git reset --hard`) leaves
> it alone. Edits you make directly to `docker-compose.yml` are discarded on the next update. See
> [upgrading.md](upgrading.md).

### Option B — an existing nginx or Apache on the host

Move Conzent off port 80 first, in `.env`:

```ini
APP_PORT=8080
```

`docker compose up -d`, then proxy to it. nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name consent.example.com;

    ssl_certificate     /etc/letsencrypt/live/consent.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/consent.example.com/privkey.pem;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}

server {
    listen 80;
    server_name consent.example.com;
    return 301 https://$host$request_uri;
}
```

`X-Forwarded-For` matters beyond tidiness: Conzent records the visitor IP on every consent log entry and uses it for
geo-targeting. Without the header, every consent is attributed to your proxy.

### Option C — Traefik

If you already run Traefik, set `APP_PORT=8080` in `.env` (so nothing fights over port 80) and add labels to the
`nginx` service in `docker-compose.override.yml`:

```yaml
services:
  nginx:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.conzent.rule=Host(`consent.example.com`)"
      - "traefik.http.routers.conzent.entrypoints=websecure"
      - "traefik.http.routers.conzent.tls.certresolver=letsencrypt"
      - "traefik.http.services.conzent.loadbalancer.server.port=80"
    networks:
      - default
      - traefik

networks:
  traefik:
    external: true
```

### Option D — Cloudflare in front

Proxy the record (orange cloud) and set SSL/TLS mode to **Full**. Keep origin TLS on — "Flexible" mode leaves the hop
between Cloudflare and your server unencrypted. Conzent also supports purging the Cloudflare cache automatically when
scripts change; set `CLOUDFLARE_ZONE_ID` and `CLOUDFLARE_API_TOKEN` in `.env`.

---

## 4. Restart and confirm the app sees the new URL

```bash
docker compose up -d
docker compose exec app php bin/oci health
```

Then load `https://consent.example.com` in a browser. You should get the login page over a valid certificate.

---

## 5. Regenerate the consent scripts — required

This is the step that is easy to miss and produces the strangest symptoms if you do.

Each site's script lives at `public/sites_data/{site_key}/script.js` and contains **absolute URLs built from `APP_URL`
at generation time** — the API endpoint it posts consent to, the CSS it pulls, the logo paths. Changing `APP_URL` does
not rewrite scripts that already exist. Until you regenerate, banners on your customers' sites keep calling the old
host: consent is recorded against the wrong origin, or fails outright once the old address stops answering.

```bash
docker compose exec app php bin/oci scripts:regenerate
```

Verify the new host is actually in the output:

```bash
docker compose exec app sh -c 'grep -o "https\?://[^\"]*" public/sites_data/*/script.js | head'
```

---

## 6. Update the embed snippet on your websites

The snippet shown in the dashboard now points at the new domain:

```html
<script src="https://consent.example.com/c/consent.js" data-key="YOUR_SITE_KEY"></script>
```

The install is a single tag, so the domain is the only thing that changes: the Google Consent Mode defaults and the
IAB TCF stub both live inside `consent.js` and are pushed synchronously by the loader itself. Keep the tag
parser-blocking (no `async`, no `defer`) and leave `data-key` — and `data-dl`, if the site has one — untouched. See
[embed-snippet.md](embed-snippet.md).

Any site still loading the old address needs updating. If you are moving a live install, keep the old hostname
resolving and proxying to the new one until you have swapped every embed — the loader is the entry point for the
entire banner, so a broken src means no consent banner at all.

---

## 7. Verify end to end

```bash
# Loader is served
curl -sSI https://consent.example.com/c/consent.js | head -1

# A site's generated script exists and carries a cache-busting version
curl -s https://consent.example.com/sites_data/YOUR_SITE_KEY/version.json
```

Then load a page that embeds the banner, accept consent, and confirm the entry appears under **Consent Logs** in the
dashboard. That single round trip exercises DNS, TLS, the loader, the generated script, and the API path together.

---

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Banner does not appear on your site | The embed still points at the old domain, or the script was never regenerated (step 5). |
| Consent logs stopped arriving after the move | Same cause — the generated script is posting to the old API path. Run `scripts:regenerate`. |
| Every consent log shows the same IP | The proxy is not forwarding `X-Forwarded-For` (step 3, option B). |
| Password-reset emails link to `localhost` | `APP_URL` was not updated, or the containers were not restarted after editing `.env`. |
| Browser warns about mixed content | `APP_URL` is `http://` while the site is served over HTTPS. Set the scheme to `https://` and regenerate scripts. |
| Certificate never issues (Caddy) | Port 80 is still held by the `nginx` container or another service. Check with `ss -tlnp | grep :80`. |
| Changes to `docker-compose.yml` vanished | An update reset tracked files. Move your changes into `docker-compose.override.yml`. |
