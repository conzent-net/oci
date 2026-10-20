# Upgrading

```bash
cd /path/to/conzent
bash scripts/backup.sh && bash scripts/install.sh --update
```

Or, from anywhere:

```bash
curl -sSL https://getconzent.com/install | sh -s -- --update
```

Take the backup first. Migrations run automatically and are not reversible.

---

## What `--update` does

Step by step, so nothing is a surprise:

| Step | Effect |
| --- | --- |
| `git fetch` + `git reset --hard origin/main` | **Tracked files are reset to the release.** Local edits to them are discarded. |
| `docker compose down` | Containers stop. Volumes are **kept** — your database survives. |
| Removes the `app-public` volume | Stale CSS/JS/media is cleared so the new build's assets are served. |
| `docker compose build --no-cache --pull` | Full rebuild, ignoring layer cache, pulling fresh base images. |
| `docker compose up -d` | Everything starts again. |
| `migrations:migrate` | Applies pending schema migrations only. |
| `scanner:register` | Re-registers the bundled scanner (idempotent). |
| Redis `FLUSHALL` + Twig cache clear | Drops stale sessions and compiled templates. |
| Admin creation | **Skipped** — your users are untouched. |

Expect a few minutes for the no-cache rebuild.

### What survives

- The database (`oci-db-data`) — every site, banner, consent log, policy, and user.
- Generated consent scripts (`app-sites-data`).
- `.env`, `.conzent-credentials`, `docker-compose.override.yml`, `backups/` — all untracked, so `git reset` does not
  touch them.

### What does not

- **Edits to tracked files.** `docker-compose.yml`, `docker/nginx/default.conf`, templates, source files — all reset.
- The `app-public` volume, deliberately.
- Redis contents: everyone is signed out and queued jobs are dropped.

---

## Customising without losing it on the next update

The rule: never edit a tracked file. Two supported places to put your changes.

**`.env`** — all configuration: `APP_URL`, `APP_PORT`, SMTP, scanner tuning, third-party keys.

**`docker-compose.override.yml`** — anything about the stack itself. Compose merges it automatically, and it is
gitignored:

```yaml
services:
  # Publish the scanner so remote installs can reach it
  scanner:
    ports:
      - "8300:8300"

  # Give MariaDB more memory
  mariadb:
    command: --innodb-buffer-pool-size=1G

  # Mount custom nginx config
  nginx:
    volumes:
      - ./docker/nginx/custom.conf:/etc/nginx/conf.d/default.conf:ro
```

Files you *add* (a `Caddyfile`, a custom nginx conf) are untracked and survive updates. Files you *modify* do not.

If you have already customised a tracked file, move the change into an override before updating:

```bash
git diff --stat        # what you changed
git stash              # park it, update, then reapply deliberately
```

---

## Pinning a branch or version

```bash
bash scripts/install.sh --update --branch main
```

`--branch` selects which branch to reset to. To hold at a specific release, check out its tag manually and skip
`--update`:

```bash
git fetch --tags
git checkout v2.4.2
docker compose build --no-cache && docker compose up -d
docker compose exec app php bin/oci migrations:migrate
```

Note that `--update` resets to a branch head, so it will move you off a pinned tag.

---

## Verifying an upgrade

```bash
docker compose ps                                   # all services up
docker compose exec app php bin/oci health          # database, Redis, filesystem
docker compose exec app php bin/oci scanner:health  # scanner reachable
```

Then load the dashboard, open a site's banner settings, and confirm your customisations are intact. If the banner on a
live site looks wrong after an upgrade, regenerate the scripts:

```bash
docker compose exec app php bin/oci scripts:regenerate
```

---

## If an upgrade goes wrong

```bash
# 1. What broke
docker compose logs --tail=100 app
docker compose logs --tail=50 mariadb

# 2. Roll back code and data together
git checkout <previous-tag>
docker compose build --no-cache && docker compose up -d
bash scripts/restore.sh backups/<archive-taken-before-the-upgrade>.tar.gz --yes
```

Restoring the pre-upgrade backup is what undoes a migration — rolling back code alone leaves the new schema in place.
This is the reason for the backup-first rule at the top of this page.

Still stuck? Open an issue at [github.com/conzent-net/oci/issues](https://github.com/conzent-net/oci/issues) with the
output of `docker compose logs --tail=100 app` and your Conzent version.

---

## Uninstalling

```bash
bash scripts/install.sh --uninstall
```

This runs `docker compose down -v` — **deleting every volume, including the database** — and then removes the install
directory. It asks for confirmation first. Take a backup before you run it if there is any chance you will want the
data back; Docker and git are left in place.
