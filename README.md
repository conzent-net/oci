<p align="center">
  <img src="public/media/primary-light.png" alt="Conzent OCI" width="280">
</p>

<h1 align="center">Conzent OCI</h1>

<p align="center">
  <strong>Open Consent Infrastructure — Self-Hosted Consent Management Platform</strong>
</p>

<p align="center">
  <a href="https://getconzent.com">Website</a> &middot;
  <a href="https://getconzent.com/docs/">Documentation</a> &middot;
  <a href="https://github.com/conzent-net/oci/issues">Issues</a> &middot;
  <a href="https://getconzent.com/license/">License</a>
</p>

---

Conzent OCI is a production-grade, self-hosted cookie consent management platform. Deploy it on your own infrastructure to collect, manage, and report on user consent across all your websites — fully compliant with GDPR, ePrivacy, CCPA, and other privacy regulations.

## Features

- **Cookie Detection & Categorization** — Automatic cookie scanning with categorization (necessary, analytics, marketing, preferences)
- **Customizable Consent Banners** — Multiple layout types (popup, banner, box) with 7 position options, light/dark themes, and full CSS control
- **IAB TCF v2.2 / v2.3 Support** — Register your own CMP ID with IAB Europe and operate a fully certified TCF consent solution
- **Google Consent Mode v2** — Native integration with Google's consent signals for Analytics, Ads, and Tag Manager
- **Multi-Site Management** — Manage consent across unlimited websites from a single dashboard
- **Multi-Language** — Full i18n support for banner content, cookie descriptions, and policies
- **Privacy & Cookie Policy Generation** — Built-in policy wizard that auto-populates from detected cookies
- **Consent Logging & Reporting** — Complete audit trail with date-range filtering, export, and trend visualization
- **Cookie Scanning** — On-demand and scheduled scans to detect cookies, scripts, and tracking technologies
- **Associated Domains** — Share consent state across related domains
- **Compliance Checklists** — Guided setup for GDPR, GCM, CCPA, IAB/TCF, and more
- **Module System** — Extensible architecture for custom integrations

## Screenshots

<p align="center">
  <img src="public/media/screenshots/dashboard.png" alt="Dashboard" width="800">
</p>

<details>
<summary>More screenshots</summary>

| | |
|---|---|
| ![Banner Settings](public/media/screenshots/banner-settings.png) | ![Consent Logs](public/media/screenshots/consent-logs.png) |
| ![Cookie Scanner](public/media/screenshots/cookie-scanner.png) | ![Policy Generator](public/media/screenshots/policy-generator.png) |

</details>

## Requirements

- PHP 8.3+
- MariaDB 11+ (or MySQL 8+)
- Redis 7+
- Composer 2+
- Nginx or Apache

## Quick Start

### One-Line Install (recommended)

```bash
curl -sSL https://getconzent.com/install | sh
```

This clones the repository, generates secure credentials, starts all containers, and runs database migrations automatically.

#### Installer Options

| Option | Description |
|--------|-------------|
| `--dir DIR` | Installation directory (default: `./conzent`) |
| `--branch NAME` | Git branch to clone (default: `main`) |
| `--admin-email EMAIL` | Admin account email (prompted if omitted) |
| `--admin-password PASS` | Admin account password (auto-generated if omitted) |
| `--update` | Update an existing installation (preserves database and config) |
| `--no-start` | Clone and configure only, don't start containers |
| `--config` | Show saved admin credentials and app URL |
| `--uninstall` | Stop containers and remove the installation |

**Examples:**

```bash
# Install to a custom directory
curl -sSL https://getconzent.com/install | sh -s -- --dir /opt/conzent

# Update an existing installation
curl -sSL https://getconzent.com/install | sh -s -- --update

# Fully automated install (CI/scripting)
curl -sSL https://getconzent.com/install | sh -s -- --admin-email admin@example.com --admin-password secret123

# Show saved credentials
curl -sSL https://getconzent.com/install | sh -s -- --config

# Uninstall
curl -sSL https://getconzent.com/install | sh -s -- --uninstall
```

### Using Docker

```bash
git clone https://github.com/conzent-net/oci.git
cd oci
cp .env.example .env
# Edit .env with your database and Redis credentials
docker compose up -d
```

### Manual Installation

```bash
git clone https://github.com/conzent-net/oci.git
cd oci
cp .env.example .env
composer install --no-dev --optimize-autoloader
```

Configure your `.env` file (see [Configuration](#configuration)), then point your web server to the `public/` directory.

### Run Migrations

```bash
php bin/oci migrations:migrate
```

### Verify Installation

Visit your configured `APP_URL` in a browser. You should see the login page. Create your first account and add your website.

## Configuration

Copy `.env.example` to `.env` and configure the following sections:

| Section | Key Variables | Description |
|---------|--------------|-------------|
| **Application** | `APP_ENV`, `APP_URL`, `APP_SECRET` | Environment, URL, and encryption secret |
| **Database** | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MariaDB/MySQL connection |
| **Redis** | `REDIS_HOST`, `REDIS_PORT` | Cache, sessions, and queue backend |
| **Email** | `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS` | SMTP for notifications and password resets |
| **IAB TCF** | `CMP_ID` | Your registered IAB CMP ID (leave empty to disable TCF) |
| **Google OAuth** | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | "Sign in with Google" and GTM integration |
| **CDN** | `CDN_URL` | Optional CDN prefix for consent scripts |
| **Cloudflare** | `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_API_TOKEN` | Edge cache purge on script regeneration |
| **Scanning** | `SCAN_QUEUE_NAME`, `SCAN_TIMEOUT` | Cookie scanning configuration |
| **AI Translation** | `OPENROUTER_API_KEY` | Auto-translate banner content via AI |

See `.env.example` for all available options with inline documentation.

## Architecture

Conzent OCI is built on a modern PHP stack with no framework dependencies:

- **PHP 8.3+** with PSR-4 autoloading, PSR-7 HTTP messages, PSR-11 DI container
- **Doctrine DBAL** for database access (query builder, no ORM)
- **Twig** for server-side templates
- **Alpine.js + htmx** for reactive UI without heavy JavaScript
- **Bulma CSS** for responsive layouts
- **Redis** for caching, sessions, and job queues
- **FastRoute** for HTTP routing

### Domain Structure

The codebase is organized into 12 domain boundaries:

| Domain | Responsibility |
|--------|---------------|
| **Identity** | Users, authentication, sessions, API keys |
| **Site** | Website management, domains, script generation |
| **Cookie** | Cookie detection, categorization, reference database |
| **Consent** | Consent collection, logging, reporting |
| **Banner** | Banner configuration, content, layouts, translations |
| **Policy** | Privacy/cookie policy generation & templates |
| **Compliance** | IAB TCF v2.2/v2.3, Google Consent Mode v2 |
| **Scanning** | Scan orchestration, scheduling, results |
| **Dashboard** | Site overview, consent stats, recommendations |
| **Notification** | Email and in-app notifications |
| **Report** | Consent reports and scheduled exports |
| **Shared** | Cross-domain DTOs, events, services |

### Module System

Conzent OCI supports optional modules in `src/Modules/` for extended functionality. The core application runs fully without any modules. Modules are auto-discovered at boot time and can add routes, services, templates, and menu items.

## CLI Commands

```bash
php bin/oci health                  # Health check
php bin/oci migrations:migrate      # Run database migrations
php bin/oci cache:clear             # Clear all caches
php bin/oci queue:work              # Process background jobs
php bin/oci schedule:run            # Run scheduled tasks
php bin/oci scripts:regenerate      # Regenerate all consent scripts
```

## Web Server Configuration

### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/oci/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2?)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
    }
}
```

### Apache

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    DocumentRoot /var/www/oci/public

    <Directory /var/www/oci/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

## Adding the Consent Banner to Your Website

After configuring your site and banner in the dashboard, add this script tag to your website's `<head>`:

```html
<script src="https://your-oci-domain.com/c/consent.js" data-key="YOUR_SITE_KEY"></script>
```

The consent script is lightweight, non-blocking, and handles all cookie consent UI, storage, and reporting automatically.

## Cookie Scanning

Conzent OCI includes a built-in cookie scanning service that detects cookies, scripts, and tracking technologies on your websites. The scanner runs headless Chromium via Puppeteer and communicates with the main app over HTTP. **No additional installation or configuration is required** — the scanner is included in both the one-liner install and Docker Compose setup, and works out of the box.

### How It Works

1. You trigger a scan from the dashboard (or schedule one)
2. The app queues the scan and dispatches it to a scanner server
3. The scanner visits your site with headless Chromium, collecting cookies, localStorage, and network beacons
4. Results are sent back to the app via webhook and stored in the database
5. Detected cookies are auto-categorized (necessary, analytics, marketing, functional) using a global reference database and pattern matching

### What's Included

The default Docker Compose stack runs these scanning-related services automatically:

| Service | Purpose |
|---------|---------|
| `scanner` | Headless Chromium scanning server (port 8300) |
| `worker` | Processes scan jobs from the Redis queue |
| `scheduler` | Triggers scheduled and recurring scans |
| `beacon-worker` | Processes client-side beacon data in batches |

No external dependencies — Chromium, Node.js, and all libraries are bundled in the Docker image.

### Scanner API

The scanner server exposes these endpoints (authenticated via `X-Api-Key` header):

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/health` | Health check (no auth required) — returns status, active jobs, uptime |
| `POST` | `/scan` | Scan a single URL synchronously — returns cookies, localStorage, beacons |
| `POST` | `/scan/batch` | Scan multiple URLs asynchronously — returns a job ID (202 Accepted) |
| `GET` | `/status/:jobId` | Check batch job progress — returns completed/failed counts and results |

**Single scan request:**

```json
POST /scan
X-Api-Key: your-scanner-api-key

{
  "url": "https://example.com",
  "options": {
    "waitForNetworkIdle": true,
    "extraWait": 3000
  }
}
```

**Batch scan request (used by the app internally):**

```json
POST /scan/batch
X-Api-Key: your-scanner-api-key

{
  "scan_id": 123,
  "urls": ["https://example.com/", "https://example.com/about"],
  "callback_url": "https://your-oci-domain.com/api/v1/scan-webhook",
  "options": {
    "waitForNetworkIdle": true,
    "extraWait": 3000
  }
}
```

### Deploying Additional Scanners

For faster scans or geographic distribution, you can deploy standalone scanner instances on separate servers.

**1. Copy the scanner files to your server:**

Copy the `docker/scanner/` directory from the repository to your remote server.

**2. Set your API key and start the scanner:**

```bash
cd docker/scanner
export SCANNER_API_KEY=your-secret-key
docker compose -f docker-compose.scanner.yml up -d
```

**3. Register the scanner in Conzent:**

Go to **Admin → Scan Servers** in the Conzent dashboard and add the scanner:

- **URL:** `http://<server-ip>:8300`
- **API Key:** The `SCANNER_API_KEY` you set above

The scanner will appear in the server list and start receiving scan jobs automatically.

### Scanner Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `SCANNER_API_KEY` | *(required)* | Shared secret for authenticating with the main app |
| `MAX_CONCURRENT` | `5` | Maximum parallel browser sessions per container |
| `SCAN_TIMEOUT` | `60000` | Per-page timeout in milliseconds |

### Scaling

To run multiple scanner instances on the same server:

```bash
docker compose -f docker-compose.scanner.yml up -d --scale scanner=3
```

### Health Check

Each scanner exposes a health endpoint at `http://<server>:8300/health`.

### System Requirements (per scanner container)

- **Memory:** 512 MB minimum, 2 GB recommended
- **CPU:** 1+ cores
- **Network:** Outbound HTTPS to scan target websites, inbound HTTP from the main app on port 8300

## IAB TCF Registration

To use IAB TCF features:

1. Register independently with [IAB Europe](https://iabeurope.eu/tcf-for-cmps/) as a CMP
2. Obtain your own CMP ID
3. Set `CMP_ID` in your `.env` file
4. The platform automatically enables TCF v2.3 features

> **Note:** This License does not grant rights to use the Licensor's CMP ID. You must register your own.

## Contributing

We welcome contributions! Please:

1. Fork the repository
2. Create a feature branch
3. Submit a pull request

For bug reports and feature requests, use [GitHub Issues](https://github.com/conzent-net/oci/issues).

## Cloud Edition

Looking for a managed solution? [Conzent Cloud](https://getconzent.com) offers a fully managed version with additional features not available in the open-source edition:

- **A/B Testing** — Test different banner designs and copy to optimize consent rates
- **Revenue Impact Analysis** — Measure how consent rates affect your ad revenue and analytics data
- **Agency Management** — Multi-tenant agency tools with customer management and commission tracking
- **Managed Billing** — Built-in subscription and payment handling
- **Priority Support** — Dedicated support from the Conzent team

## Branding

The "Powered by Conzent" branding must remain visible in the consent banner. Removing or hiding the Conzent branding is not permitted under the license terms.

## License

Conzent OCI is licensed under the [Business Source License 1.1](LICENSE.md).

**You are free to:** self-host, modify, customize, and use for your own websites and properties.

**You may not:** offer it as a hosted/managed service to third parties, or use it as the basis for a competing commercial CMP product.

**After 4 years**, each release automatically converts to the [Apache License 2.0](https://www.apache.org/licenses/LICENSE-2.0).

See [LICENSE.md](LICENSE.md) for the complete terms.

---

<p align="center">
  Built by <a href="https://getconzent.com">Conzent ApS</a>
</p>
