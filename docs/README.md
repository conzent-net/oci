# Conzent OCI Documentation

Operating guides for a self-hosted Conzent OCI install. If you have not installed yet, start with the
[README](../README.md); these pages pick up the moment the installer finishes.

## Running it for real

| Guide | What it covers |
| --- | --- |
| [Custom domain & HTTPS](custom-domain.md) | Point your own domain at the install, terminate TLS, and regenerate consent scripts so your sites load from the new host. |
| [Credentials & rotation](credentials.md) | Every secret the installer generates, where it lives, and how to rotate each one — including recovering a lost admin password. |
| [Backup & restore](backup-restore.md) | What actually holds your data, how to back it up on a schedule, and a restore drill you should run before you need it. |
| [Upgrading](upgrading.md) | What `--update` does to your files, database, and volumes — and what it does not preserve. |
| [Cookie scanning](scanning.md) | How the bundled scanner works, how to check its health, and how to add more scanners. |

## The 10-minute production checklist

Fresh install → running a client site. Each step links to the detail.

1. **Point a domain at it and enable HTTPS** — [custom-domain.md](custom-domain.md).
   Your consent scripts embed this URL, so do it before you add sites.
2. **Configure SMTP** — set `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` in `.env`.
   Without it, password resets and scan alerts silently do nothing. Verify with:
   ```bash
   docker compose exec app php bin/oci test:email --to=you@example.com
   ```
3. **Change the generated admin password and store it in a password manager** — [credentials.md](credentials.md).
   Then delete `.conzent-credentials`, which holds it in plain text.
4. **Set up backups** — [backup-restore.md](backup-restore.md). A nightly cron entry takes one line.
5. **Run a restore drill once** — an untested backup is a guess.
6. **Check health**:
   ```bash
   docker compose exec app php bin/oci health
   docker compose exec app php bin/oci scanner:health
   ```

## Command reference

Run these from your install directory. `docker compose exec app` puts you inside the application container.

| Command | Purpose |
| --- | --- |
| `docker compose exec app php bin/oci health` | Database, Redis, and filesystem checks |
| `docker compose exec app php bin/oci user:password --email=ADDR` | Reset a password, sign out that user's sessions |
| `docker compose exec app php bin/oci scripts:regenerate` | Rebuild every site's consent script (required after a domain change) |
| `docker compose exec app php bin/oci scanner:register` | Register or update a scan server |
| `docker compose exec app php bin/oci scanner:health` | Ping registered scanners |
| `docker compose exec app php bin/oci migrations:migrate` | Apply pending database migrations |
| `docker compose exec app php bin/oci cache:clear` | Clear application caches |
| `bash scripts/backup.sh` | Create a restorable archive |
| `bash scripts/restore.sh ARCHIVE --yes` | Restore an archive |
| `bash scripts/install.sh --update` | Update to the latest release |
| `bash scripts/install.sh --config` | Show the saved admin credentials |

Adding scanning capacity? Run this **on the new server**, then paste the registration command it prints on your
Conzent server — see [scanning.md](scanning.md):

```bash
curl -sSL https://getconzent.com/install-scanner | sh
```

Run `docker compose exec app php bin/oci help` for the full list.

## Getting help

- **Issues and bugs** — [github.com/conzent-net/oci/issues](https://github.com/conzent-net/oci/issues)
- **Community** — [r/consent_cmp](https://www.reddit.com/r/consent_cmp/)
- **Website docs** — [getconzent.com/docs](https://getconzent.com/docs/)
