# Ryzen production remediation (no reboot)

These scripts are pinned to `ryzen-prod`, Coolify application ID `4`, and the
Compose project/resource UUID `brrmsbi50m4q02lqx35juhlr`. They intentionally do
not reboot the host. Read every script before running it.

## Safety rules

- Keep the current SSH session open and establish a second session over the
  Ryzen Tailscale address before touching the firewall.
- Run the host scripts with `sudo bash SCRIPT`; Windows checkouts may not retain
  executable bits.
- Never run `git add -A`: this checkout already contains unrelated user edits.
- Do not put tokens or passwords in command-line arguments, shell history, chat,
  or Git. Coolify API scripts prompt silently for the API token.
- Run `51-full-encrypted-backup.sh` in a short maintenance window: it briefly
  stops this one Conzent stack for a consistent snapshot, then restores exactly
  the services that were running before it.
- The no-reboot update script deliberately leaves Docker/containerd packages on
  hold. Upgrade those, and activate the already-installed kernel, only in a later
  reboot maintenance window.

## 1. Prepare and publish the source fix (development checkout)

From the repository root (without `sudo` in your local checkout):

```bash
bash ops/ryzen-remediation/05-prepare-source.sh
remediation_paths=(
  .dockerignore .gitignore .env.production .claude/settings.local.json
  deploy.sh docker-compose.coolify.yaml
  legacy/app/admin/autologin.php legacy/app/api/v1/geo_ip.php
  legacy/app/classes/freshdesk.php legacy/app/classes/tagmanagerAPI.php
  legacy/app/cron/freshdesk.php legacy/app/custom_functions.php
  scripts/publish.php
  scripts/deploy-matomo-plugin.sh
  plugins/getconzent_wix/.dockerignore plugins/getconzent_wix/.envyx
  ops/ryzen-remediation
)
remediation_add_paths=(
  .dockerignore .gitignore deploy.sh docker-compose.coolify.yaml
  legacy/app/admin/autologin.php legacy/app/api/v1/geo_ip.php
  legacy/app/classes/freshdesk.php legacy/app/classes/tagmanagerAPI.php
  legacy/app/cron/freshdesk.php legacy/app/custom_functions.php
  scripts/publish.php
  scripts/deploy-matomo-plugin.sh
  plugins/getconzent_wix/.dockerignore ops/ryzen-remediation
)
git add -- "${remediation_add_paths[@]}"
git diff --cached --stat -- "${remediation_paths[@]}"
git diff --cached -- "${remediation_paths[@]}" \
  ':!.env.production' ':!.claude/settings.local.json' \
  ':!plugins/getconzent_wix/.envyx'
git diff --cached --name-status -- .env.production .claude/settings.local.json \
  plugins/getconzent_wix/.envyx
git commit --only -m "Harden Ryzen production deployment" -- "${remediation_paths[@]}"
git push origin main
```

`git commit --only` is deliberate: it leaves the checkout's pre-existing staged
changes out of this remediation commit.

The source patch removes tracked and hard-coded credentials/phpMyAdmin, prevents
local secrets from entering image layers, explicitly supplies runtime environment
variables, defaults production mode/debug off, pins MariaDB/Redis images, and
preserves Ryzen's existing UUID-prefixed volumes.

## 2. Copy this kit to Ryzen and preflight

Copy `ops/ryzen-remediation` to a root-owned location on Ryzen. Then:

```bash
cd /root/ryzen-remediation
sudo bash 00-preflight.sh
```

Do not continue if the host, stack UUID, application health, or container set is
not the expected one.

## 3. Close public administrative ports

Open a second SSH session through Tailscale first. In the original session:

```bash
cd /root/ryzen-remediation
sudo PUBLIC_IFACES=enp6s0 bash 10-install-admin-firewall.sh
```

Verify the second SSH session still works and `https://app.getconzent.com/health`
is healthy. The script leaves ports 22/80/443 untouched and restricts public TCP
6001, 6002, 8000, 8025, and 8090 with Docker-aware IPv4/IPv6 rules. If the
checks fail:

```bash
sudo bash 11-rollback-admin-firewall.sh
```

From a separate workstation on the public internet, run the external check; a
same-host check cannot prove Docker-published ports are unreachable:

```bash
bash ops/ryzen-remediation/12-verify-public-ports.sh
```

## 4. Repair Fail2Ban

```bash
sudo bash 20-repair-fail2ban.sh
```

This tests SSH and the complete Fail2Ban configuration before restarting only
Fail2Ban; it does not restart SSH.

## 5. Create and verify a full encrypted backup

First install `age` if it is missing, and place a public age recipient (not the
private identity) in a root-owned file such as `/root/conzent-backup-recipient.txt`.
Keep the matching private identity off the production server.

The current SSHFS mount was audited with `allow_other` and permissive modes. The
target check will fail and print the exact fstab/remount correction; it will not
unmount or edit fstab automatically:

```bash
sudo bash 50-secure-backup-target.sh
# Perform the printed mount correction in a separately approved window.
sudo bash 50-secure-backup-target.sh
sudo AGE_RECIPIENT_FILE=/root/conzent-backup-recipient.txt \
  bash 51-full-encrypted-backup.sh
sudo bash 52-verify-encrypted-backup.sh
```

Copy the `.tar.age` file plus its `.sha256` sidecar to an isolated drill host
that has Docker, the cached image digests and the private age identity. There:

```bash
sudo AGE_IDENTITY_FILE=/root/conzent-backup-identity.txt \
  bash 53-restore-drill.sh /path/to/conzent-full-TIMESTAMP.tar.age
```

The drill creates random, internal-only disposable containers/volumes, imports
the database, compares MariaDB/Wix/Elasticsearch source baselines, validates all
volume archives, and cleans up only labeled drill resources. It never attaches
production volumes. This is a data-integrity drill; an application-level recovery
test still requires a separately isolated copy of the full runtime configuration.

The legacy `/home/lennart/backup.sh` still produces broad plaintext backups of
all Docker volumes/Coolify state. The new script replaces Conzent application
coverage, but not Coolify's server-level state, proxy/certificate configuration,
or unrelated stacks. Keep that broader job until those items have an encrypted,
tested replacement; do not delete old backups in this runbook. After credentials
are rotated, handle their retention under an explicit deletion/change ticket.

## 6. Stage production flags, force a clean deployment, verify

The Coolify token must have application read/update and deployment permissions.
Each API script prompts silently:

```bash
sudo bash 40-coolify-stage-env.sh
sudo bash 41-coolify-deploy.sh
sudo bash 42-verify.sh
```

The verification requires all five PHP containers to run `APP_ENV=prod` and
`APP_DEBUG=false`, exact existing data-volume mounts, no phpMyAdmin container or
login route, and healthy database/Redis/application checks.

After the deployment, inspect the rebuilt application image ID and prove that
no forbidden secret paths survive in any layer:

```bash
sudo IMAGE="$(docker inspect \
  --format '{{.Image}}' \
  "$(docker ps -q \
    --filter label=coolify.applicationId=4 \
    --filter label=com.docker.compose.project=brrmsbi50m4q02lqx35juhlr \
    --filter label=com.docker.compose.service=app)")" \
  bash 06-check-built-image.sh
```

## 7. Rotate compromised credentials

Create a root-only worksheet:

```bash
sudo bash 60-prepare-credential-rotation.sh
```

Create replacements at the providers, update the corresponding Coolify
variables through its UI, deploy/verify again, test each integration, and only
then revoke old credentials. Rotate at least the exposed SES SMTP, OpenRouter,
Cloudflare, Wix and any credentials present in the local Claude settings. Treat
every value committed in the three removed files or hard-coded old deploy script
as compromised.

After the encrypted backup and restore drill have passed, rotate both database
credentials. This creates and tests a new application account while the old one
still works, rotates every supported root account, changes all four Coolify
database variables as one set, deploys, verifies all five PHP runtimes and both
health paths, then removes the old application account:

```bash
sudo bash 61-rotate-database-credentials.sh
sudo bash 42-verify.sh
```

Do not make a concurrent Coolify deployment or environment edit while it runs.
On any failure, stop and inspect the printed phase; the script retains a root-only
recovery record under `/var/backups/conzent-remediation/database-rotation` and
refuses a second rotation until that state is resolved. Never copy that record to
chat or an unencrypted ticket.

Credential rotation is the containment step. Rewriting private Git history can
reduce future accidental discovery but cannot revoke secrets from existing
clones; coordinate a separate `git filter-repo` change with every collaborator
and CI/deploy consumer after rotations are complete.

Keep old Conzent images during the immediate rollback window. After every
exposed credential—including database credentials—has been rotated, inventory
unreferenced Conzent images and BuildKit cache on Ryzen and remove only reviewed,
unreferenced artifacts under a separate cleanup approval. Do not run a broad
Docker prune on this multi-tenant host.

## 8. Apply host updates without reboot

Review the simulation generated by the first script before running the second:

```bash
sudo bash 30-stage-os-updates.sh
sudo bash 31-apply-os-updates-no-reboot.sh
sudo bash 00-preflight.sh
```

This applies non-Docker package updates and refuses removals, Docker/containerd
updates, or an unexpected Docker restart. It never reboots. A reboot will still
be required later to activate the installed kernel/security fixes.

## Explicitly deferred hardening

This kit contains the audit incident without changing application protocols. A
separate, tested change is still required for Redis authentication,
Elasticsearch security, scanner browser sandboxing, and replacing the chat
service's wildcard CORS policy. They are internal-only after the firewall and
Compose changes, but should not be treated as fully remediated.
