# Credentials & Rotation

The installer generates every secret your install needs, so nothing ships with a default password. This page lists
what it generated, where each value lives, and how to change it without breaking the install.

---

## What the installer generated

| Secret | Generated as | Stored in | What it protects |
| --- | --- | --- | --- |
| **Admin password** | 16-character random string (or the value you passed to `--admin-password`) | `.conzent-credentials` (mode 600) and bcrypt-hashed in the database | Dashboard login |
| **`DB_PASSWORD`** | 24-character random string | `.env`, twice: `DB_PASSWORD=` and inside `DATABASE_URL=` | MariaDB access |
| **`DB_ROOT_PASSWORD`** | not set — defaults to `root` | `.env` (if you set it) | MariaDB superuser |
| **`SCANNER_API_KEY`** | 32-character random string | `.env` | Authenticates the app to the cookie scanner |
| **`APP_SECRET`** | 64-character hex string | `.env` | Reserved. No code path reads it today |

View the saved admin credentials at any time:

```bash
bash scripts/install.sh --config
```

### First thing to do

`.conzent-credentials` holds your admin password **in plain text**. It exists so the installer can show it to you
again if you missed it. Once the password is in a password manager:

```bash
rm .conzent-credentials
```

Nothing depends on the file; `--config` will simply report that it is gone.

---

## Rotating the admin password

Two ways, depending on whether you can still log in.

### You can log in

Change it in the dashboard under **Account → Profile**. This is the normal path and it takes effect immediately.

### You cannot log in

This is the case that used to be a dead end: `bin/oci setup` refuses to run once an account exists, and the "forgot
password" email needs a working SMTP server, which self-hosted installs often do not have. Reset it from the host
instead:

```bash
# Generate a new password and print it
docker compose exec app php bin/oci user:password --email=you@example.com

# Or set a specific one
docker compose exec app php bin/oci user:password --email=you@example.com --password='your-new-password'
```

The command:

- bcrypt-hashes and stores the new password,
- **signs out every existing session** for that user, so the old password grants nothing anywhere,
- clears any lockout from failed login attempts (the app locks an account after 10).

It works for any user, not just admins — useful when a client forgets theirs and you have no mail server.

> **Want the email flow to work instead?** Configure SMTP in `.env` (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`,
> `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`), restart, and test with
> `docker compose exec app php bin/oci test:email --to=you@example.com`. With `MAIL_HOST` empty, Conzent logs
> `Email not sent: no SMTP server configured` and moves on — reset links are never delivered.

---

## Rotating the database password

Four places have to agree: MariaDB itself, `DB_PASSWORD`, `DATABASE_URL`, and the running containers.

```bash
# 1. Pick a new password
NEW_PASS=$(openssl rand -hex 16)

# 2. Change it inside MariaDB (uses the current root password from .env, default: root)
docker compose exec -T mariadb mariadb -uroot -p"$DB_ROOT_PASSWORD" \
  -e "ALTER USER 'oci'@'%' IDENTIFIED BY '$NEW_PASS'; FLUSH PRIVILEGES;"

# 3. Update BOTH places in .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$NEW_PASS|" .env
sed -i "s|^DATABASE_URL=.*|DATABASE_URL=mysql://oci:$NEW_PASS@mariadb:3306/oci?charset=utf8mb4|" .env

# 4. Restart the services that hold connections
docker compose up -d --force-recreate app worker scheduler beacon-worker

# 5. Confirm
docker compose exec app php bin/oci health
```

If `health` reports a database failure, the two `.env` values disagree — `DATABASE_URL` is the one the application
actually uses, `DB_PASSWORD` is what MariaDB is initialised with. Both must carry the new value.

> **`DB_ROOT_PASSWORD` defaults to `root`.** The MariaDB container publishes no host port in the self-hosted stack, so
> it is only reachable from inside the Compose network — but set a real value in `.env` before adding any host port
> mapping of your own.

---

## Rotating the scanner API key

The key is a shared secret between the app and the scanner container.

```bash
# 1. New key in .env
sed -i "s|^SCANNER_API_KEY=.*|SCANNER_API_KEY=$(openssl rand -hex 16)|" .env

# 2. Recreate both sides so they pick it up
docker compose up -d --force-recreate scanner app worker scheduler

# 3. Re-register so the stored server record carries the new key
docker compose exec app php bin/oci scanner:register

# 4. Confirm
docker compose exec app php bin/oci scanner:health
```

Registration is keyed by URL and idempotent, so re-running it updates the existing record rather than adding a
duplicate. If you run [additional scanners](scanning.md), rotate the key on each of them and re-register each URL.

---

## `APP_SECRET`

Generated as a 64-character hex string, but no code path reads it today — sessions use PHP's native handler and the
remember-me cookie is validated against a token stored in the database. Rotating it is harmless and changes nothing.
It is reserved for future use; leave it populated.

---

## API keys and site keys

Two more credentials exist that the installer does not generate:

- **API keys** (`oci_api_keys`) — created per user for programmatic access. Revoke and re-issue from the dashboard.
- **Site keys** — the `data-key` value in each site's embed snippet. It is a public identifier, not a secret: it
  appears in the page source of every site using the banner. It cannot be rotated without updating the embed on that
  site.

---

## Third-party credentials in `.env`

If you have configured any of these, they follow the same pattern: change the value, then
`docker compose up -d --force-recreate app worker scheduler`.

| Variable | Rotate at |
| --- | --- |
| `CLOUDFLARE_API_TOKEN` | Cloudflare dashboard → API Tokens |
| `GOOGLE_CLIENT_SECRET` | Google Cloud Console → Credentials |
| `OPENROUTER_API_KEY` | OpenRouter dashboard |
| `MAIL_PASSWORD` | Your SMTP provider |

---

## Handling `.env`

`.env` is the most sensitive file in the install: it holds the database password, the scanner key, and every
third-party credential.

- It is listed in `.gitignore` and is never committed.
- The installer creates it with mode 600. If you copied it around, restore that: `chmod 600 .env`.
- It is included in backups produced by `scripts/backup.sh` — which is why those archives are also written mode 600
  and should be stored somewhere private. See [backup-restore.md](backup-restore.md).
- Restoring a backup does **not** overwrite your `.env` unless you pass `--restore-env`.
