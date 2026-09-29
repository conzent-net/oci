#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────
# Conzent OCI — Backup
# https://getconzent.com
#
# Creates a single restorable archive containing:
#   - a full MariaDB dump (every site, banner, consent log, policy)
#   - the generated consent scripts volume (app-sites-data)
#   - .env and .conzent-credentials
#
# Redis, var/ and the built public assets are deliberately NOT backed
# up — they are cache, logs and build output, all regenerated on start.
#
# Usage:
#   bash scripts/backup.sh
#   bash scripts/backup.sh --output /mnt/backups --keep 14
#
# ──────────────────────────────────────────────────────────────

OUTPUT_DIR="backups"
KEEP=0

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
        --output) OUTPUT_DIR="$2"; shift 2 ;;
        --keep)   KEEP="$2"; shift 2 ;;
        --help|-h)
            printf "\n  ${BOLD}Usage:${RESET}\n"
            printf "    bash scripts/backup.sh [OPTIONS]\n\n"
            printf "  ${BOLD}Options:${RESET}\n"
            printf "    --output DIR   Where to write the archive (default: backups)\n"
            printf "    --keep N       Keep only the N most recent archives in that directory\n"
            printf "    --help         Show this help message\n\n"
            printf "  ${BOLD}Restore with:${RESET}\n"
            printf "    bash scripts/restore.sh backups/conzent-YYYYmmdd-HHMMSS.tar.gz --yes\n\n"
            exit 0
            ;;
        *) fatal "Unknown option: $1 (use --help for usage)" ;;
    esac
done

# ── Locate the installation ──────────────────────────────────

if [ ! -f docker-compose.yml ] && [ ! -f docker-compose.yaml ]; then
    fatal "Run this from your Conzent OCI directory (no docker-compose.yml here)."
fi
[ -f .env ] || fatal ".env not found — is this a Conzent OCI installation?"

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

# ── Read database credentials from .env ──────────────────────

env_value() {
    grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'
}

DB_NAME=$(env_value DB_NAME); [ -n "$DB_NAME" ] || DB_NAME=oci
DB_USER=$(env_value DB_USER); [ -n "$DB_USER" ] || DB_USER=oci
DB_PASSWORD=$(env_value DB_PASSWORD); [ -n "$DB_PASSWORD" ] || DB_PASSWORD=oci

# ── Prepare staging area ─────────────────────────────────────

STAMP=$(date +%Y%m%d-%H%M%S)
ARCHIVE_NAME="conzent-${STAMP}.tar.gz"

mkdir -p "$OUTPUT_DIR"
STAGE=$(mktemp -d)
# shellcheck disable=SC2064
trap "rm -rf '$STAGE'" EXIT INT TERM

step "Backing up Conzent OCI"
info "Target: ${OUTPUT_DIR}/${ARCHIVE_NAME}"

# ── 1. Database ──────────────────────────────────────────────
#
# The PHP image carries no mysql client, so the dump has to run inside
# the mariadb container.

if ! compose exec -T mariadb sh -c 'exit 0' > /dev/null 2>&1; then
    fatal "The mariadb container is not running. Start it with: docker compose up -d"
fi

compose exec -T mariadb mariadb-dump \
    -u"$DB_USER" -p"$DB_PASSWORD" \
    --single-transaction \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "$DB_NAME" > "$STAGE/database.sql" 2>/dev/null \
    || fatal "Database dump failed. Check credentials in .env (DB_USER / DB_PASSWORD)."

DB_SIZE=$(wc -c < "$STAGE/database.sql" | tr -d ' ')
[ "$DB_SIZE" -gt 1000 ] || fatal "Database dump looks empty (${DB_SIZE} bytes) — aborting."
success "Database dumped ($(( DB_SIZE / 1024 )) KB)"

# ── 2. Generated consent scripts ─────────────────────────────
#
# Regenerable via `scripts:regenerate`, but restoring them keeps sites
# live during the window before a regeneration runs.

# Ask the running container for the real volume name rather than guessing
# the "<project>_<volume>" prefix, which varies with the directory name.
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
        -v "${SITES_VOLUME}:/data:ro" \
        -v "${STAGE}:/backup" \
        alpine:latest \
        tar czf /backup/sites_data.tar.gz -C /data . > /dev/null 2>&1 \
        && success "Consent scripts archived (${SITES_VOLUME})" \
        || warn "Could not archive consent scripts (they can be regenerated)"
else
    warn "No sites-data volume found — skipping consent scripts (regenerable)"
fi

# ── 3. Configuration and credentials ─────────────────────────

cp .env "$STAGE/env" 2>/dev/null && success "Configuration (.env) included"
if [ -f .conzent-credentials ]; then
    cp .conzent-credentials "$STAGE/conzent-credentials"
    success "Saved credentials included"
fi

# ── 4. Manifest ──────────────────────────────────────────────

APP_URL=$(env_value APP_URL)
CONTENTS=$(cd "$STAGE" && ls -1 | tr '\n' ' ' | sed -e 's/ $//')
cat > "$STAGE/manifest.txt" <<MANIFEST
# Conzent OCI backup
created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
app_url=${APP_URL}
database=${DB_NAME}
host=$(hostname 2>/dev/null || echo unknown)
contents=${CONTENTS}
MANIFEST

# ── 5. Seal the archive ──────────────────────────────────────

tar czf "${OUTPUT_DIR}/${ARCHIVE_NAME}" -C "$STAGE" . 2>/dev/null \
    || fatal "Failed to write ${OUTPUT_DIR}/${ARCHIVE_NAME}"
chmod 600 "${OUTPUT_DIR}/${ARCHIVE_NAME}"

ARCHIVE_SIZE=$(wc -c < "${OUTPUT_DIR}/${ARCHIVE_NAME}" | tr -d ' ')
success "Archive written ($(( ARCHIVE_SIZE / 1024 )) KB)"

# ── 6. Rotation ──────────────────────────────────────────────

if [ "$KEEP" -gt 0 ]; then
    # shellcheck disable=SC2012
    COUNT=$(ls -1 "${OUTPUT_DIR}"/conzent-*.tar.gz 2>/dev/null | wc -l | tr -d ' ')
    if [ "$COUNT" -gt "$KEEP" ]; then
        REMOVE=$(( COUNT - KEEP ))
        # shellcheck disable=SC2012
        ls -1t "${OUTPUT_DIR}"/conzent-*.tar.gz | tail -n "$REMOVE" | while read -r old; do
            rm -f "$old"
        done
        success "Rotated out ${REMOVE} old archive(s), keeping ${KEEP}"
    fi
fi

printf "\n"
printf "  ${BOLD}Backup complete:${RESET} ${CYAN}%s/%s${RESET}\n" "$OUTPUT_DIR" "$ARCHIVE_NAME"
printf "\n"
printf "  ${DIM}This archive contains your database AND your secrets.${RESET}\n"
printf "  ${DIM}Copy it off this machine and keep it somewhere private.${RESET}\n"
printf "\n"
printf "  ${BOLD}Restore with:${RESET}\n"
printf "    ${DIM}bash scripts/restore.sh %s/%s --yes${RESET}\n" "$OUTPUT_DIR" "$ARCHIVE_NAME"
printf "\n"
