---
id: selfhost.cli
title: CLI commands (bin/oci)
area: Self-hosting
knowledgebase: Self-Hosting
url: /
menu_path: (server) — not in the app UI
edition: [self-hosted]
audience: [admin]
plan: any
tags: [cli, bin-oci, commands, migrations, queue, scheduler, cache, password-reset, regenerate, health, backup]
related: [selfhost.install, selfhost.configuration, selfhost.scanner]
source_files:
  - bin/oci
  - README.md
  - scripts/
questions:
  - How do I run database migrations?
  - How do I reset a user's password from the command line?
  - How do I regenerate all consent scripts?
  - How do I clear the Conzent cache?
  - What cron jobs does Conzent need?
  - How do I test my email configuration?
  - How do I check if Conzent is healthy?
  - How do I back up Conzent?
---

# CLI commands (bin/oci)

## Where to find it

`php bin/oci <command>` from the installation directory. In Docker:

```bash
docker compose exec app php bin/oci <command>
```

## What it does

Operational tasks that have no UI: migrations, queue and scheduler workers, cache management,
password recovery, script regeneration and scanner registration.

## Core commands

| Command | What it does |
|---|---|
| `health` | Health check — database, Redis and configuration |
| `migrations:migrate` | Applies pending database migrations. **Run after every update** |
| `cache:clear` | Clears all caches |
| `queue:work` | Processes background jobs (scans, emails). Runs continuously |
| `schedule:run` | Runs due scheduled tasks. Drive from cron every minute |
| `ingest:flush` | Flushes buffered beacon/consent ingest data |
| `scripts:regenerate` | Regenerates every site's consent script. **Run after changing `APP_URL`** |
| `cache:purge-loader` | Purges the cached consent loader |

## Users

| Command | What it does |
|---|---|
| `setup` | First-run setup — creates the initial admin account |
| `user:password --email=you@example.com` | Resets a password and signs out that user's sessions. **The way back in when SMTP is not configured** |
| `users:verify-emails` | Batch email-verification pass (needs an Endpointr key; a no-op otherwise) |
| `cleanup:non-admin-users` | Removes non-admin users. Destructive — read it before running |

## Scanning

| Command | What it does |
|---|---|
| `scanner:register` | Registers or updates a scan server. Stores its API key, region and concurrency |
| `scanner:health` | Pings registered scan servers |

A scan server must be registered before any scan can run. See
Knowledgebase: Self-Hosting - Document: scanner.md.

## Cookies and IAB

| Command | What it does |
|---|---|
| `cookies:sync` | Syncs the global cookie reference database |
| `cookies:translate` | Translates global cookie descriptions |
| `iab:update-vendor-list` | Refreshes the IAB Global Vendor List. Also runs on the daily schedule |

## Banner content

| Command | What it does |
|---|---|
| `banners:seed-translations` | Seeds banner field translations |

## Diagnostics

| Command | What it does |
|---|---|
| `test:email` | Sends a test email using your SMTP settings |
| `test:endpointr-vault` | Verifies the Endpointr credential vault connection |

## Migration and publishing

| Command | What it does |
|---|---|
| `legacy:migrate` | Migrates data from a legacy Conzent installation |
| `publish` | Publishes core code to the public repository (maintainers only) |

## Host-side scripts

Run from the install directory, **not** inside the container:

```bash
bash scripts/backup.sh --keep 14                 # create a restorable archive, keep 14
bash scripts/restore.sh ARCHIVE.tar.gz --yes     # restore one
bash scripts/install.sh --update                 # update to the latest release
```

## Cron

Two long-running processes plus the scheduler:

```cron
# scheduler — drives scans, reports, GVL refresh, scanner health checks
* * * * * cd /opt/conzent && docker compose exec -T app php bin/oci schedule:run >> /var/log/conzent-schedule.log 2>&1

# nightly backup, keep 14 days
0 3 * * * cd /opt/conzent && bash scripts/backup.sh --keep 14 >> /var/log/conzent-backup.log 2>&1
```

`queue:work` should run under a process supervisor (systemd, supervisord, or a Compose service
with `restart: always`) rather than cron — it is a long-lived worker, not a periodic task.

## Common questions

**I am locked out and email is not configured.**

```bash
docker compose exec app php bin/oci user:password --email=you@example.com
```

Resets the password and terminates that user's sessions.

**Do I have to run migrations manually?**
The installer's `--update` runs them. After a manual `git pull`, run
`php bin/oci migrations:migrate` yourself.

**Scans are queued but never run.**
`queue:work` is not running, or no scan server is registered. Check `scanner:health`, then confirm
the worker process is alive.

**Scheduled reports and scans never fire.**
`schedule:run` is not on cron. Add the minute-by-minute entry above.

**I moved the install to a new domain and banners broke.**
Update `APP_URL` in `.env`, then `php bin/oci scripts:regenerate` — the old URL is baked into
every generated script.

**How do I confirm the install is healthy?**
`php bin/oci health` checks database, Redis and configuration.

**Is there a command to list all commands?**
Run `php bin/oci` with no arguments.

## Related

- Knowledgebase: Self-Hosting - Document: install.md — installation
- Knowledgebase: Self-Hosting - Document: configuration.md — the settings these commands read
- Knowledgebase: Self-Hosting - Document: scanner.md — registering and monitoring scanners
