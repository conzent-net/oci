---
id: selfhost.install
title: Installing Conzent OCI (self-hosted)
area: Self-hosting
knowledgebase: Self-Hosting
url: /
menu_path: (server) — not in the app UI
edition: [self-hosted]
audience: [admin]
plan: any
tags: [install, self-host, oci, docker, docker-compose, installer, requirements, upgrade, uninstall, migrations, backup]
related: [platform.editions, selfhost.configuration, selfhost.cli, selfhost.scanner]
source_files:
  - README.md
  - docker-compose.yml
  - scripts/install.sh
  - bin/oci
questions:
  - How do I install Conzent on my own server?
  - What are the system requirements for self-hosting?
  - How do I update my Conzent installation?
  - How do I uninstall Conzent?
  - Where do I find my admin password after installing?
  - How do I run database migrations?
  - How do I install Conzent on a custom domain?
  - How do I back up Conzent?
---

# Installing Conzent OCI (self-hosted)

## Where to find it

On your own server. Conzent OCI is the open-source core, Apache 2.0 licensed, available at
`github.com/conzent-net/oci`.

## What it does

Runs the full Conzent CMP on your infrastructure — unlimited websites, unlimited pageviews, no
licence fee, no data leaving your servers. See
Knowledgebase: Platform - Document: editions.md for what differs from Cloud.

## Requirements

| Component | Minimum |
|---|---|
| PHP | 8.3+ |
| Database | MariaDB 11+ (or MySQL 8+) |
| Cache | Redis 7+ |
| Composer | 2+ |
| Web server | Nginx or Apache |

The Docker stack bundles all of these.

## One-line install (recommended)

```bash
curl -sSL https://getconzent.com/install | sh
```

Clones the repository, generates secure credentials, starts all containers and runs migrations.

### Installer options

| Option | What it does |
|---|---|
| `--dir DIR` | Installation directory (default `./conzent`) |
| `--branch NAME` | Git branch to clone (default `main`) |
| `--admin-email EMAIL` | Admin account email (prompted if omitted) |
| `--admin-password PASS` | Admin password (auto-generated if omitted) |
| `--domain DOMAIN` | Public domain, e.g. `consent.example.com` — sets `APP_URL` |
| `--update` | Update an existing installation, preserving database and config |
| `--no-start` | Clone and configure only, do not start containers |
| `--config` | Print the saved admin credentials and app URL |
| `--uninstall` | Stop containers and remove the installation |

```bash
# custom directory
curl -sSL https://getconzent.com/install | sh -s -- --dir /opt/conzent

# your own domain
curl -sSL https://getconzent.com/install | sh -s -- --domain consent.example.com

# update in place
curl -sSL https://getconzent.com/install | sh -s -- --update

# unattended
curl -sSL https://getconzent.com/install | sh -s -- --admin-email admin@example.com --admin-password secret123

# show credentials
curl -sSL https://getconzent.com/install | sh -s -- --config
```

## Docker

```bash
git clone https://github.com/conzent-net/oci.git
cd oci
cp .env.example .env
# edit .env — database, Redis, APP_URL, mail
docker compose up -d
```

## Manual

```bash
git clone https://github.com/conzent-net/oci.git
cd oci
cp .env.example .env
composer install --no-dev --optimize-autoloader
php bin/oci migrations:migrate
```

Point your web server's document root at `public/`.

## Verify

Open your configured `APP_URL`. You should see the login page. Sign in with the admin account
the installer created, then add your first site.

## After install — the production checklist

| Guide | Covers |
|---|---|
| `docs/custom-domain.md` | DNS, TLS termination (Caddy / nginx / Traefik / Cloudflare), regenerating consent scripts for the new host |
| `docs/credentials.md` | Every generated secret, where it lives, how to rotate it, recovering a lost admin password |
| `docs/backup-restore.md` | What actually holds your data, `scripts/backup.sh`, cron scheduling, a restore drill |
| `docs/upgrading.md` | What `--update` preserves and what it resets |
| `docs/scanning.md` | Scanner health, callback configuration, tuning, remote scanners |

`docs/README.md` has a 10-minute production checklist.

## Host-side helpers

Run from the install directory, not inside the container:

```bash
bash scripts/backup.sh --keep 14                 # create a restorable archive
bash scripts/restore.sh ARCHIVE.tar.gz --yes     # restore one
bash scripts/install.sh --update                 # update to the latest release
```

**Back up before every upgrade.** `--update` preserves the database and config, but a
restorable archive is the only thing that makes a bad upgrade recoverable.

## Common questions

**Where is my admin password?**
`curl -sSL https://getconzent.com/install | sh -s -- --config` prints the saved credentials and
app URL.

**I am locked out and never configured SMTP.**
Password reset needs mail. Reset from the host instead:

```bash
docker compose exec app php bin/oci user:password --email=you@example.com
```

**How do I change the domain after installing?**
Set `APP_URL` in `.env`, then regenerate — `APP_URL` is baked into every generated consent
script:

```bash
php bin/oci scripts:regenerate
```

Then follow `docs/custom-domain.md` for TLS.

**Do I need to run migrations after an update?**
`--update` runs them. After a manual `git pull`, run `php bin/oci migrations:migrate` yourself.

**Is TCF available?**
Only with your own IAB Europe CMP registration. Set `CMP_ID` to your assigned ID. See
Knowledgebase: Compliance - Document: iab-tcf.md.

**What is not included?**
Billing, agency/reseller management, A/B testing and Revenue Impact are commercial modules and
are not in the public repository. Everything else is.

**How many websites can I run?**
Unlimited. Plan limits are not enforced without a `CMP_ID`.

## Related

- Knowledgebase: Self-Hosting - Document: configuration.md — the environment reference
- Knowledgebase: Self-Hosting - Document: cli.md — every CLI command
- Knowledgebase: Self-Hosting - Document: scanner.md — cookie scanning setup
- Knowledgebase: Platform - Document: editions.md — what differs from Cloud
