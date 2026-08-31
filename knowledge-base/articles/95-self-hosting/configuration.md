---
id: selfhost.configuration
title: Configuration reference (.env)
area: Self-hosting
knowledgebase: Self-Hosting
url: /
menu_path: (server) — not in the app UI
edition: [self-hosted]
audience: [admin]
plan: any
tags: [env, configuration, settings, smtp, mail, cmp-id, google-oauth, cloudflare, redis, database, openrouter, cache, consent-ip-key, retention, pseudonymization]
related: [selfhost.install, selfhost.cli, selfhost.scanner, compliance.tcf, platform.editions]
source_files:
  - .env.example
  - README.md
  - src/Shared/Service/EditionService.php
questions:
  - How do I configure SMTP for Conzent?
  - Password reset emails are not sending
  - How do I enable Sign in with Google?
  - Where do I set my CMP ID?
  - How do I enable the Auto Translate button?
  - How do I set up Cloudflare cache purging?
  - How do I disable caching for testing?
  - What environment variables does Conzent use?
  - How do I set the consent log retention period?
  - What is CONSENT_IP_KEY?
---

# Configuration reference (.env)

## Where to find it

`.env` in your installation directory. Start from `.env.example`, which carries inline notes for
every setting. Restart the containers after changing it.

## What it does

Everything not configurable through the UI. This is where the edition, mail, OAuth, TCF
registration and caching behaviour are decided.

## Application

| Variable | Purpose | Notes |
|---|---|---|
| `APP_ENV` | `dev` or `prod` | — |
| `APP_DEBUG` | Verbose errors | Never `true` in production |
| `APP_SECRET` | Encryption secret | A random 64-char string. Changing it invalidates sessions |
| `APP_URL` | Your public URL | **Baked into every generated consent script.** Run `php bin/oci scripts:regenerate` after changing it |

## Database and Redis

| Variable | Purpose |
|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | MariaDB / MySQL connection |
| `DATABASE_URL` | Full DSN alternative |
| `REDIS_HOST`, `REDIS_PORT`, `REDIS_URL` | Cache, sessions and job queue |

## Email — required for password resets

| Variable | Purpose |
|---|---|
| `MAIL_HOST` | SMTP host. **Leave it empty and outbound mail is silently skipped — password reset will not work** |
| `MAIL_PORT` | Usually 587 |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP credentials |
| `MAIL_ENCRYPTION` | `tls` typically |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender identity |

Examples from `.env.example`:

| Provider | Host | Port | Encryption |
|---|---|---|---|
| Amazon SES | `email-smtp.us-east-1.amazonaws.com` | 587 | tls |
| Postmark | `smtp.postmarkapp.com` | 587 | tls |
| Local dev | `mailpit` | 1025 | *(empty)* |

Test with `php bin/oci test:email`.

## Consent log privacy

| Variable | Default | Purpose |
|---|---|---|
| `CONSENT_IP_KEY` | *(empty)* | HMAC key for pseudonymizing visitor IPs in the consent log. Generate with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Without it, IPs are truncated (v4 /24, v6 /48) instead — weaker correlation between records. Set it once and never rotate casually: a new key breaks correlation with older records |
| `CONSENT_LOG_RETENTION_DAYS` | `1095` | Days to keep consent records; older rows are purged nightly. `0` keeps them forever. Note the default **starts purging records older than 3 years** on existing installs |

## IAB TCF

| Variable | Purpose |
|---|---|
| `CMP_ID` | **Your** registered IAB CMP ID. Setting it to a valid ID enables TCF *and* switches the install into Cloud Edition behaviour. Empty or `0` = Community Edition |
| `CMP_VERSION` | Bump when you materially change your consent UI (default 3) |

The ID is encoded into every TC string you issue — it must be your own registration.

### Global Vendor List

| Variable | Default | Purpose |
|---|---|---|
| `GVL_AUTO_UPDATE` | `true` | Daily refresh between 06:00 and 08:00. `false` pins the shipped list (air-gapped) |
| `GVL_SOURCE_URL` | `https://vendor-list.consensu.org/v3/` | Must end with a slash. Change only for a mirror |
| `GVL_LANGUAGES` | *(empty = all 36)* | Comma-separated TCF language codes. All is ~1 MB per refresh |
| `GVL_UPDATE_ATP` | `true` | Also refresh Google's Additional Consent provider list |
| `GVL_ARCHIVE_KEEP` | `10` | Historical vendor-list versions to retain |

## Google OAuth

Powers "Sign in with Google" and the GTM Wizard. Create credentials in Google Cloud Console and
enable the **Tag Manager API**.

| Variable | Purpose |
|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | OAuth client |

Authorised redirect URIs:

- `{APP_URL}/auth/google/callback` — user login
- `{APP_URL}/gtm/callback` — GTM wizard
- `{APP_URL}/app/integrations/google-ads/callback` — Revenue Impact Tier 3

## AI translation

| Variable | Purpose |
|---|---|
| `OPENROUTER_API_KEY` | Enables the **Auto Translate** button in banner content. Empty hides it |

## Cache and CDN

| Variable | Purpose |
|---|---|
| `CDN_URL` | Optional CDN prefix for consent scripts. Empty serves locally |
| `CLOUDFLARE_ZONE_ID`, `CLOUDFLARE_API_TOKEN` | Edge cache purge on script regeneration. Token needs the Cache Purge permission |
| `DISABLE_CACHE` | `true` disables every caching layer — browser, Redis, Cloudflare, minification. Useful for development, never in production |

## Scanning

| Variable | Purpose |
|---|---|
| `SCAN_QUEUE_NAME`, `SCAN_TIMEOUT` | Queue name and per-scan timeout |
| `SCANNER_API_KEY` | Shared secret between app and scanner. Must match what the scanner runs with |
| `SCANNER_URL` | Internal URL for the scanner (Docker DNS name), default `http://scanner:8300` |
| `SCANNER_CALLBACK_URL` | Where the scanner POSTs results. Defaults to `APP_URL`. For the bundled scanner use `http://nginx` to keep the round trip inside the Compose network — the default fails behind split-horizon DNS, hairpin NAT, or a proxy that blocks the loopback |
| `SCANNER_ALERT_EMAIL` | Where downtime/recovery alerts go. Falls back to `MAIL_FROM_ADDRESS` |
| `SCANNER_ALERT_FAILURES` | Consecutive failed health checks before alerting. Scheduler polls every 5 minutes |

## GeoIP

| Variable | Purpose |
|---|---|
| `IPREGISTRY_API_KEY` | Fallback geolocation when MaxMind cannot resolve an IP |

## Logging

| Variable | Purpose |
|---|---|
| `LOG_LEVEL` | `debug`, `info`, `warning`, `error` |
| `LOG_CHANNEL` | `stderr` for container logs |

## Legacy migration

| Variable | Purpose |
|---|---|
| `LEGACY_DATABASE_URL` | Remote legacy database for self-service account migration |
| `LEGACY_EXCLUDE_USER_IDS` | Comma-separated user IDs to exclude |

## Cloud-only settings

Present in `.env.example` but only meaningful when the corresponding commercial module is
installed: `MONETIZATION_MODEL`, `PAYMENT_GATEWAY`, the `STRIPE_*` keys, `TOKEN_ENCRYPTION_KEY`,
`GOOGLE_ADS_DEVELOPER_TOKEN`, `META_APP_ID` / `META_APP_SECRET`, the `AUTOSEO_*` keys, the
`SENDMAIL_*` keys and the `ENDPOINTR_*` keys.

## Common questions

**Password reset emails are not sending.**
`MAIL_HOST` is empty — outbound mail is skipped entirely, without an error. Set SMTP and test
with `php bin/oci test:email`. Meanwhile reset passwords with `php bin/oci user:password`.

**I changed `APP_URL` and banners broke.**
`APP_URL` is baked into generated scripts. Run `php bin/oci scripts:regenerate`.

**Changes are not appearing on my sites.**
Set `DISABLE_CACHE=true` while debugging, or use **Banners → Advanced Settings → Purge &
Regenerate** per site. Remember to turn it back off.

**How do I enable TCF?**
Register with IAB Europe, then set `CMP_ID` to your assigned ID. Note this also switches the
install into Cloud Edition behaviour, which enables plan limits and billing hooks — see
Knowledgebase: Platform - Document: editions.md.

**Where is the Auto Translate button?**
It needs `OPENROUTER_API_KEY`. Without it the button is hidden.

**Do I need Cloudflare settings?**
Only if Cloudflare fronts your Conzent server and you want script regeneration to purge the edge
cache automatically.

## Related

- Knowledgebase: Self-Hosting - Document: install.md — getting it running
- Knowledgebase: Self-Hosting - Document: cli.md — the commands referenced above
- Knowledgebase: Self-Hosting - Document: scanner.md — scanner configuration in depth
- Knowledgebase: Compliance - Document: iab-tcf.md — CMP registration
