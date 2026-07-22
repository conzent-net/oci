#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────
# Conzent OCI — Restore
# https://getconzent.com
#
# Restores an archive produced by scripts/backup.sh:
#   - imports the MariaDB dump (REPLACES the current database)
#   - restores the generated consent scripts volume
#   - optionally restores .env
#
# After importing it runs migrations (in case the archive predates the
# installed version) and regenerates every consent script against the
# CURRENT APP_URL — which may differ from the one in the backup.
#
# Usage:
#   bash scripts/restore.sh backups/conzent-20260722-101500.tar.gz --yes
#   bash scripts/restore.sh backup.tar.gz --yes --restore-env
#
# ──────────────────────────────────────────────────────────────

ARCHIVE=""
CONFIRMED=false
RESTORE_ENV=false

# ── Colors & UI ──────────────────────────────────────────────

if [ -t 1 ]; then
    BOLD='\033[1m'; DIM='\033[2m'; RESET='\033[0m'
    GREEN='\033[0;32m'; YELLOW='\033[0;33m'; RED='\033[0;31m'; CYAN='\033[0;36m'
else
    BOLD=''; DIM=''; RESET=''; GREEN=''; YELLOW=''; RED=''; CYAN=''
fi

step()    { printf "\n${BOLD}%s${RESET}\n" "$*"; }
success() { printf "${GREEN}  ✓${RESET} %b\n" "$*"; }
warn()    { printf "${YELLOW}  !${RESET} %b\n" "$*"; }
info()    { printf "${DIM}  %b${RESET}\n" "$*"; }
fatal()   { printf "\n${RED}  ✗ %b${RESET}\n\n" "$*"; exit 1; }

# ── Parse arguments ──────────────────────────────────────────

while [ $# -gt 0 ]; do
    case "$1" in
        --yes)         CONFIRMED=true; shift ;;
        --restore-env) RESTORE_ENV=true; shift ;;
        --help|-h)
            printf "\n  ${BOLD}Usage:${RESET}\n"
            printf "    bash scripts/restore.sh ARCHIVE --yes [--restore-env]\n\n"
            printf "  ${BOLD}Options:${RESET}\n"
            printf "    --yes           Required. Confirms that the current database will be replaced\n"
            printf "    --restore-env   Also restore .env from the archive (secrets and APP_URL)\n"
            printf "    --help          Show this help message\n\n"
            exit 0
            ;;
        -*) fatal "Unknown option: $1 (use --help for usage)" ;;
        *)  ARCHIVE="$1"; shift ;;
    esac
done

[ -n "$ARCHIVE" ] || fatal "No archive given.\n    Usage: bash scripts/restore.sh backups/conzent-....tar.gz --yes"
[ -f "$ARCHIVE" ] || fatal "Archive not found: $ARCHIVE"

if [ ! -f docker-compose.yml ] && [ ! -f docker-compose.yaml ]; then
    fatal "Run this from your Conzent OCI directory (no docker-compose.yml here)."
fi

# ── Docker helpers (mirrors install.sh) ──────────────────────

NEEDS_SUDO_DOCKER=false
if ! docker info > /dev/null 2>&1; then
    if command -v sudo > /dev/null 2>&1 && sudo docker info > /dev/null 2>&1; then
        NEEDS_SUDO_DOCKER=true
    else
        fatal "Cannot talk to Docker. Is the daemon running, and do you have permission?"
    fi
fi

run_docker() {
    if [ "$NEEDS_SUDO_DOCKER" = true ]; then sudo "$@"; else "$@"; fi
}

if run_docker docker compose version > /dev/null 2>&1; then
    compose() { run_docker docker compose "$@"; }
elif command -v docker-compose > /dev/null 2>&1; then
    compose() { run_docker docker-compose "$@"; }
else
    fatal "Docker Compose not found."
fi

# ── Unpack and inspect ───────────────────────────────────────

STAGE=$(mktemp -d)
# shellcheck disable=SC2064
trap "rm -rf '$STAGE'" EXIT INT TERM

step "Reading archive"
tar xzf "$ARCHIVE" -C "$STAGE" 2>/dev/null || fatal "Could not unpack $ARCHIVE"
[ -f "$STAGE/database.sql" ] || fatal "Archive contains no database.sql — is this a Conzent backup?"

if [ -f "$STAGE/manifest.txt" ]; then
    while IFS= read -r line; do
        case "$line" in \#*|'') continue ;; esac
        info "$line"
    done < "$STAGE/manifest.txt"
fi

DUMP_SIZE=$(wc -c < "$STAGE/database.sql" | tr -d ' ')
success "Database dump found ($(( DUMP_SIZE / 1024 )) KB)"

# ── Confirm ──────────────────────────────────────────────────

if [ "$CONFIRMED" != true ]; then
    printf "\n"
    printf "  ${RED}${BOLD}This replaces the current database.${RESET}\n"
    printf "  ${DIM}Every site, banner, consent log and policy in this install will be${RESET}\n"
    printf "  ${DIM}overwritten by the contents of the archive.${RESET}\n\n"
    printf "  Re-run with ${BOLD}--yes${RESET} to proceed.\n\n"
    exit 1
fi

# ── Read database credentials from the LIVE .env ─────────────

env_value() {
    grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

[ -f .env ] || fatal ".env not found — install first, then restore into it."

DB_NAME=$(env_value DB_NAME); [ -n "$DB_NAME" ] || DB_NAME=oci
DB_USER=$(env_value DB_USER); [ -n "$DB_USER" ] || DB_USER=oci
DB_PASSWORD=$(env_value DB_PASSWORD); [ -n "$DB_PASSWORD" ] || DB_PASSWORD=oci

# ── 1. Stop the writers ──────────────────────────────────────
#
# mariadb and redis stay up; only the processes that write to them stop.

step "Stopping application containers"
compose stop app worker scheduler beacon-worker nginx > /dev/null 2>&1 || true
success "Application stopped (database left running)"

if ! compose exec -T mariadb sh -c 'exit 0' > /dev/null 2>&1; then
    step "Starting database"
    compose up -d mariadb > /dev/null 2>&1 || fatal "Could not start mariadb"
    printf "  Waiting for MariaDB"
    TRIES=0
    while [ $TRIES -lt 60 ]; do
        if compose exec -T mariadb mariadb-admin ping -u root --silent > /dev/null 2>&1; then
            break
        fi
        printf "."
        TRIES=$((TRIES + 1))
        sleep 1
    done
    printf "\n"
fi

# ── 2. Import the dump ───────────────────────────────────────

step "Restoring database"
compose exec -T mariadb mariadb -u"$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$STAGE/database.sql" 2>/dev/null \
    || fatal "Import failed. The database was NOT fully restored — check credentials in .env."
success "Database restored into '${DB_NAME}'"

# ── 3. Restore generated consent scripts ─────────────────────

if [ -f "$STAGE/sites_data.tar.gz" ]; then
    APP_CID=$(compose ps -q app 2>/dev/null | head -1 || true)
    SITES_VOLUME=""
    if [ -n "$APP_CID" ]; then
        SITES_VOLUME=$(run_docker docker inspect -f \
            '{{range .Mounts}}{{if eq .Destination "/var/www/html/public/sites_data"}}{{.Name}}{{end}}{{end}}' \
            "$APP_CID" 2>/dev/null || true)
    fi
    if [ -z "$SITES_VOLUME" ]; then
        SITES_VOLUME=$(run_docker docker volume ls --format '{{.Name}}' 2>/dev/null \
            | grep -E 'sites-data$' | head -1 || true)
    fi

    if [ -n "$SITES_VOLUME" ]; then
        run_docker docker run --rm \
            -v "${SITES_VOLUME}:/data" \
            -v "${STAGE}:/backup:ro" \
            alpine:latest \
            sh -c 'rm -rf /data/* 2>/dev/null; tar xzf /backup/sites_data.tar.gz -C /data' > /dev/null 2>&1 \
            && success "Consent scripts restored" \
            || warn "Could not restore consent scripts — they are regenerated below anyway"
    else
        warn "No sites-data volume found — scripts will be regenerated instead"
    fi
fi

# ── 4. Optionally restore .env ───────────────────────────────

if [ "$RESTORE_ENV" = true ] && [ -f "$STAGE/env" ]; then
    cp .env ".env.before-restore-$(date +%Y%m%d-%H%M%S)"
    cp "$STAGE/env" .env
    chmod 600 .env
    success "Configuration restored (.env; previous copy kept as .env.before-restore-*)"
elif [ -f "$STAGE/env" ]; then
    info "Archive contains .env — not restored (pass --restore-env to overwrite yours)"
fi

# ── 5. Bring everything back ─────────────────────────────────

step "Starting services"
compose up -d > /dev/null 2>&1 || fatal "Failed to start containers — check: docker compose logs"

printf "  Waiting for the application"
TRIES=0
while [ $TRIES -lt 60 ]; do
    if compose exec -T app php -v > /dev/null 2>&1; then
        break
    fi
    printf "."
    TRIES=$((TRIES + 1))
    sleep 1
done
printf "\n"
success "Containers running"

# ── 6. Reconcile schema and scripts ──────────────────────────

step "Reconciling"

compose exec -T app php bin/oci migrations:migrate > /dev/null 2>&1 \
    && success "Migrations applied" \
    || warn "Migrations failed — run: docker compose exec app php bin/oci migrations:migrate"

# The archive may have been taken on a different domain; scripts embed
# APP_URL, so regenerate them against whatever this install uses now.
compose exec -T app php bin/oci scripts:regenerate > /dev/null 2>&1 \
    && success "Consent scripts regenerated for this install's APP_URL" \
    || warn "Regeneration failed — run: docker compose exec app php bin/oci scripts:regenerate"

compose exec -T redis redis-cli FLUSHALL > /dev/null 2>&1 \
    && success "Cache flushed" || true

APP_URL=$(env_value APP_URL)
[ -n "$APP_URL" ] || APP_URL="http://localhost"

printf "\n"
printf "${GREEN}  ✓ Restore complete${RESET}\n\n"
printf "  ${BOLD}Open:${RESET} ${CYAN}%s${RESET}\n\n" "$APP_URL"
printf "  ${DIM}Log in with the credentials that were valid when the backup was taken.${RESET}\n"
printf "  ${DIM}Locked out? Reset with:${RESET}\n"
printf "    ${DIM}docker compose exec app php bin/oci user:password --email=you@example.com${RESET}\n"
printf "\n"
