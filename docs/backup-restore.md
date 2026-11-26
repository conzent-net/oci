# Backup & Restore

Everything Conzent stores lives in Docker volumes and one configuration file. This page covers what is worth backing
up, how to automate it, and how to get it all back.

---

## What actually holds your data

`docker compose` creates five named volumes. Only two of them contain anything you cannot rebuild:

| Volume / file | Contents | Back it up? |
| --- | --- | --- |
| **`oci-db-data`** | MariaDB: sites, banners, consent logs, cookie categories, policies, users, scan results | **Yes — this is everything** |
| **`app-sites-data`** | Generated consent scripts (`public/sites_data/{key}/script.js`) | Yes, though regenerable |
| **`.env`** | Database password, scanner key, third-party credentials | **Yes — irreplaceable** |
| `app-public` | Built CSS/JS assets | No — rebuilt from the image; the updater deletes it anyway |
| `app-var` | Cache and logs | No |
| `oci-redis-data` | Session store, job queue, beacon buffer | No — transient by design |

`scripts/backup.sh` captures exactly the first three, plus `.conzent-credentials` if it still exists.

Consent scripts are included because restoring them keeps live banners working during the minutes between a restore
and a regeneration — but they are not the source of truth. If they were lost entirely,
`php bin/oci scripts:regenerate` rebuilds every one of them from the database.

---

## Taking a backup

From your install directory:

```bash
bash scripts/backup.sh
```

That writes `backups/conzent-YYYYmmdd-HHMMSS.tar.gz` containing:

```
database.sql          full MariaDB dump (--single-transaction, so no write lock)
sites_data.tar.gz     generated consent scripts
env                   your .env
conzent-credentials   the installer's saved admin credentials, if present
manifest.txt          when it was taken, from which APP_URL and host
```

Options:

```bash
bash scripts/backup.sh --output /mnt/backups     # write somewhere else
bash scripts/backup.sh --keep 14                 # keep only the 14 newest archives there
```

The dump uses `--single-transaction`, so InnoDB tables are captured consistently without blocking writes. There is no
need to stop the application first.

> **The archive contains your database and your secrets.** It is written mode 600. Treat it like a password file:
> store it off the server, and encrypt it if it lands anywhere shared.

---

## Automating it

A nightly backup at 03:00, keeping two weeks:

```bash
crontab -e
```

```cron
0 3 * * * cd /path/to/conzent && /bin/bash scripts/backup.sh --keep 14 >> /var/log/conzent-backup.log 2>&1
```

Use the absolute path to your install directory — cron does not inherit your shell's working directory.

### Getting copies off the box

A backup on the same disk as the database is not a backup. Add an offsite step:

```cron
# rsync to another host
15 3 * * * rsync -az /path/to/conzent/backups/ backup-host:/srv/conzent-backups/

# or push to object storage with rclone
15 3 * * * rclone copy /path/to/conzent/backups/ remote:conzent-backups --max-age 25h
```

Encrypt before it leaves if the destination is not yours:

```bash
gpg --symmetric --cipher-algo AES256 backups/conzent-20260722-030000.tar.gz
```

---

## Restoring

```bash
bash scripts/restore.sh backups/conzent-20260722-030000.tar.gz --yes
```

`--yes` is mandatory — the restore replaces the current database outright.

What it does, in order:

1. Unpacks and validates the archive, printing its manifest.
2. Stops `app`, `worker`, `scheduler`, `beacon-worker`, `nginx` — leaving MariaDB running.
3. Imports `database.sql` into the database named in your **current** `.env`.
4. Restores the consent scripts volume.
5. Starts everything back up.
6. Runs `migrations:migrate` — so an older archive is brought up to the schema this version expects.
7. Runs `scripts:regenerate` — so scripts are rebuilt against your **current** `APP_URL`, not the one in the archive.
8. Flushes Redis.

Steps 6 and 7 are why a backup taken on `old-domain.com` restores cleanly onto an install now serving
`consent.example.com`.

### Restoring the configuration too

By default your existing `.env` is left alone, because it usually holds the credentials that match the *current*
database container. To take the archive's version as well:

```bash
bash scripts/restore.sh backups/conzent-....tar.gz --yes --restore-env
```

Your previous `.env` is kept as `.env.before-restore-<timestamp>`. Use this when rebuilding a lost server, not when
rolling back data on a working one — the archived `DB_PASSWORD` will not match a freshly initialised MariaDB volume.

---

## Rebuilding a server from scratch

The full disaster-recovery path:

```bash
# 1. Fresh install on the new machine
curl -sSL https://getconzent.com/install | sh -s -- --domain consent.example.com

# 2. Copy the archive across
scp backups/conzent-20260722-030000.tar.gz newhost:/root/conzent/

# 3. Restore data and configuration
cd /root/conzent
bash scripts/restore.sh conzent-20260722-030000.tar.gz --yes --restore-env

# 4. If the domain changed, set it and regenerate
sed -i 's|^APP_URL=.*|APP_URL=https://consent.example.com|' .env
docker compose up -d
docker compose exec app php bin/oci scripts:regenerate
```

If `--restore-env` brought back a `DB_PASSWORD` the new database does not know, follow
[credentials.md](credentials.md#rotating-the-database-password) to bring the two back into agreement.

---

## Run a restore drill

An untested backup is a guess. Do this once, before you need it:

1. Note a site name, its banner settings, and today's consent-log count.
2. `bash scripts/backup.sh`
3. On a **separate** machine or VM, install fresh and restore the archive.
4. Log in and confirm the site, the banner configuration, and the consent logs are all there.
5. Load a page carrying that site's embed and check the banner still renders.

Ten minutes now, versus discovering the gap during an incident.

---

## Before every upgrade

`scripts/install.sh --update` preserves your database — but take a backup first anyway, because a migration is the
one thing a restore cannot undo:

```bash
bash scripts/backup.sh --keep 14 && bash scripts/install.sh --update
```

See [upgrading.md](upgrading.md).

---

## Manual commands

If you prefer to drive it yourself:

```bash
# Dump
docker compose exec -T mariadb mariadb-dump -uoci -p"$DB_PASSWORD" --single-transaction oci > dump.sql

# Import
docker compose exec -T mariadb mariadb -uoci -p"$DB_PASSWORD" oci < dump.sql

# Copy a volume out (name it exactly as `docker volume ls` shows it)
docker run --rm -v conzent_app-sites-data:/data:ro -v "$PWD:/out" alpine \
  tar czf /out/sites_data.tar.gz -C /data .
```

After any manual import, run migrations and regenerate scripts:

```bash
docker compose exec app php bin/oci migrations:migrate
docker compose exec app php bin/oci scripts:regenerate
```
