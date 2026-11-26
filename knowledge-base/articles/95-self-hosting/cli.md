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
tags: [cli, bin-oci, commands, migrations, queue, scheduler, cache, password-reset, regenerate, health, backup, users, administrators, reclassification, scan-servers]
related: [selfhost.install, selfhost.configuration, selfhost.scanner]
source_files:
  - bin/oci
  - src/Admin/Console/
  - README.md
  - scripts/
questions:
  - How do I add another administrator on a self-hosted install?
  - How do I unlock an account that is locked after failed sign-ins?
  - How do I approve a cookie reclassification request without the admin screens?
  - How do I disable or remove a scan server?
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

## Managing a self-hosted install without the Admin area

A self-hosted install's admin menu has two screens: **Backup and restore** (`/admin/backup`) and
**Audit Log** (`/admin/audit-log`). Everything else an administrator does runs from the commands
below, in Docker as `docker compose exec app php bin/oci <command>`.

| Task | How |
|---|---|
| The administrator account | `setup` creates it on a new install (the installer runs it). It does nothing once any account exists |
| Accounts and administrators | `user:list`, `user:show`, `user:create`, `user:role`, `user:deactivate`, `user:activate`, `user:delete`, `user:restore`, `user:unlock` (see below) |
| A forgotten password | `user:password --email=you@example.com`. Signs that user out everywhere |
| A lost authenticator | `user:2fa-reset --email=you@example.com`. Turns two-factor off for that user and clears their recovery codes and trusted devices |
| The scan server | `scanner:register` registers or updates one (`--url=`, `--key=`, `--name=`, `--region=`, `--max=`; without options, the bundled scanner). `scanner:health` lists and pings the registered servers. `scanner:list`, `scanner:disable`, `scanner:enable` and `scanner:remove` (see below) |
| The global cookie data | `cookies:sync` pulls the Open Cookie Database (`--dry-run` to preview); `cookies:translate --insert` loads the cookie descriptions in every language. `cookies:show` and `cookies:classify` read and set one entry (see below) |
| Reclassification requests | `requests:list`, `requests:approve`, `requests:reject` (see below) |
| A site's own cookies | **Compliance → Cookies** (`/cookies`) in the app: each site's owner classifies its cookies there |
| Backups | **Backup and restore** in the admin menu, `backup:run` from the command line, or the host scripts `scripts/backup.sh` and `scripts/restore.sh` |
| Who did what | **Audit Log** in the admin menu |

## Accounts, requests and scan servers

These commands do what the Users, Scan Servers and classification screens of the Cloud edition do.
This section is the reference for them; the README and `docs/README.md` repeat only the one-line
descriptions.

What they share:

- **The account** is named with `--email=ADDR` or `--id=N`.
- **The audit log.** Every change is written to the **Audit Log** with IP `cli` and the command as
  user agent. It shows as **System** unless `--by=ADDR` names the active administrator acting, who
  is then recorded instead. A `--by` that is not an active administrator is refused.
- **Confirmation.** Deleting an account, removing a scan server and demoting an administrator ask
  `Type yes to continue`. Without a terminal (a script, cron) they are refused unless `--yes` is
  given.
- **The last administrator.** Demoting, deactivating or deleting the only active administrator is
  refused, with the reason. Make another account admin first.
- **No secrets on the command line.** `user:create` generates the password and prints it once; it
  is not stored anywhere else.
- **Exit code.** 0 on success, 1 on any refusal or error (unknown account, request or server).

### Accounts

| Command | What it does |
|---|---|
| `user:list [--role=admin\|customer\|agency] [--search=TEXT] [--deleted]` | List accounts with their role, status (`active`, `inactive`, `locked`, `deleted`) and last sign-in. `--deleted` includes deleted accounts |
| `user:show --email=ADDR` | Show one account, including a deleted one |
| `user:create --email=ADDR [--role=admin] [--first-name=] [--last-name=]` | Create an account (role `customer` unless given); prints a generated password once. Change it with `user:password` |
| `user:role --email=ADDR --role=ROLE` | Make an account `admin`, `customer` or `agency`. Demoting an administrator asks for confirmation |
| `user:deactivate --email=ADDR` | Stop an account signing in, and sign it out of every session |
| `user:activate --email=ADDR` | Let a deactivated account sign in again |
| `user:delete --email=ADDR` | Delete an account and sign it out. Asks for confirmation. The account can be restored |
| `user:restore --email=ADDR` | Restore a deleted account |
| `user:unlock --email=ADDR` | Clear the failed sign-in count. An account is locked after 10 failed sign-ins |

```bash
php bin/oci user:create --email=admin2@example.com --role=admin
php bin/oci user:role --email=owner@example.com --role=customer --yes
php bin/oci user:delete --email=owner@example.com --yes --by=admin@example.com
```

### Reclassification requests

Site owners can ask for a cookie or a beacon to be moved to another category. The app accepts the
requests and the cookie list shows **Change requested** until an administrator decides.

| Command | What it does |
|---|---|
| `requests:list [--type=cookie\|beacon] [--status=pending\|approved\|rejected\|all]` | List requests (pending by default): the cookie or beacon, the current and requested category, the site and the reason |
| `requests:approve --type=cookie --id=N --by=ADDR` | Approve one: the requested category becomes the global classification, for every site |
| `requests:reject --type=cookie --id=N --by=ADDR [--note=TEXT]` | Reject one, with an optional note |

`--by` is required for both: the decision is recorded on the request against an administrator.
A request that does not exist or was already decided is refused.

```bash
php bin/oci requests:list --type=cookie
php bin/oci requests:approve --type=cookie --id=12 --by=admin@example.com
```

### Scan servers

| Command | What it does |
|---|---|
| `scanner:list` | List registered scan servers: name, URL, state and last heartbeat |
| `scanner:disable --id=N` | Stop sending scans to a server. Scans go only to active servers |
| `scanner:enable --id=N` | Send scans to a disabled server again |
| `scanner:remove --id=N` | Remove a server registration. Asks for confirmation. `scanner:register` adds it again |

### The global cookie database

| Command | What it does |
|---|---|
| `cookies:show --name=NAME [--domain=]` | Show how the global cookie database classifies a cookie, and whether by a pattern entry |
| `cookies:classify --name=NAME --category=SLUG [--domain=] [--description=] [--platform=]` | Set the global category of the entry stored under that name, creating it if there is none. A name with `*` is stored as a pattern. An unknown category is refused with the list of valid ones |

```bash
php bin/oci cookies:classify --name=_example_id --category=analytics --description="Visitor id"
```

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
