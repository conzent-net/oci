#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────
# Conzent OCI — Force Update
# Pulls latest code, rebuilds containers with no cache,
# refreshes public assets, flushes Redis and OPcache.
#
# Usage:
#   cd /path/to/conzent && bash scripts/update.sh
#
# ──────────────────────────────────────────────────────────────

# ── Colors ────────────────────────────────────────────────────
if [ -t 1 ]; then
    BOLD='\033[1m' GREEN='\033[0;32m' CYAN='\033[0;36m'
    RED='\033[0;31m' YELLOW='\033[0;33m' RESET='\033[0m'
else
    BOLD='' GREEN='' CYAN='' RED='' YELLOW='' RESET=''
fi

info()    { printf "${CYAN}  ▸${RESET} %s\n" "$*"; }
success() { printf "${GREEN}  ✓${RESET} %s\n" "$*"; }
warn()    { printf "${YELLOW}  ⚠${RESET} %s\n" "$*"; }
error()   { printf "${RED}  ✗${RESET} %s\n" "$*" >&2; }
step()    { printf "\n${BOLD}  ■ %s${RESET}\n" "$*"; }

# ── Detect docker compose command ─────────────────────────────
if docker compose version > /dev/null 2>&1; then
    COMPOSE="docker compose"
elif command -v docker-compose > /dev/null 2>&1; then
    COMPOSE="docker-compose"
else
    error "Docker Compose not found. Please install Docker."
    exit 1
fi

COMPOSE_FILE=""
if [ -f docker-compose.oci.yml ]; then
    COMPOSE_FILE="-f docker-compose.oci.yml"
elif [ -f docker-compose.prod.yml ]; then
    COMPOSE_FILE="-f docker-compose.prod.yml"
fi

compose() { $COMPOSE $COMPOSE_FILE "$@"; }

# ── Preflight checks ─────────────────────────────────────────
if [ ! -f .env ]; then
    error "No .env file found. Are you in the Conzent install directory?"
    exit 1
fi

if [ ! -d .git ]; then
    error "Not a git repository. Cannot pull updates."
    exit 1
fi

printf "\n${BOLD}${CYAN}  Conzent OCI — Force Update${RESET}\n"
printf "  This will pull the latest code and rebuild all containers.\n"
printf "  Your database and configuration (.env) will be preserved.\n\n"

# ── Step 1: Pull latest code ─────────────────────────────────
step "Pulling latest code"
git fetch origin main
git reset --hard origin/main
success "Code updated to latest"

# ── Step 2: Stop running containers ──────────────────────────
step "Stopping containers"
compose down --remove-orphans 2>/dev/null || true
success "Containers stopped"

# ── Step 3: Remove stale public volume ───────────────────────
step "Clearing cached assets"
docker volume rm "$(compose config --volumes | grep -E 'app-public' | head -1)" 2>/dev/null || true
# Fallback: try with project prefix
PROJECT_NAME=$(basename "$(pwd)" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]//g')
docker volume rm "${PROJECT_NAME}_app-public" 2>/dev/null || true
docker volume rm "conzent_app-public" 2>/dev/null || true
docker volume rm "conzent-app-public" 2>/dev/null || true
success "Asset cache cleared"

# ── Step 4: Rebuild with no cache ────────────────────────────
step "Rebuilding containers (no cache — this may take a few minutes)"
compose build --no-cache --pull
success "Containers rebuilt"

# ── Step 5: Start services ───────────────────────────────────
step "Starting services"
compose up -d
success "Services started"

# ── Step 6: Wait for MariaDB ─────────────────────────────────
step "Waiting for database"
TRIES=0
while [ $TRIES -lt 60 ]; do
    if compose exec -T mariadb mariadb-admin ping -u root --silent > /dev/null 2>&1; then
        success "Database ready"
        break
    fi
    TRIES=$((TRIES + 1))
    sleep 2
done
if [ $TRIES -ge 60 ]; then
    warn "Database did not respond in time — check logs with: $COMPOSE $COMPOSE_FILE logs mariadb"
fi

# ── Step 7: Report update telemetry ────────────────────────
curl -sSL -o /dev/null "https://app.getconzent.com/ping?e=update&v=1" 2>/dev/null || true

# ── Step 8: Run migrations ───────────────────────────────────
step "Running database migrations"
compose exec -T app php bin/oci migrations:migrate --no-interaction 2>/dev/null && \
    success "Migrations complete" || \
    warn "Migration command not available or already up to date"

# ── Step 8: Flush Redis cache ────────────────────────────────
step "Flushing caches"
compose exec -T redis redis-cli FLUSHALL > /dev/null 2>&1 && \
    success "Redis cache flushed" || \
    warn "Could not flush Redis"

# ── Step 9: Clear Twig cache ─────────────────────────────────
compose exec -T app rm -rf /var/www/html/var/cache/twig/* 2>/dev/null && \
    success "Twig template cache cleared" || true

# ── Done ──────────────────────────────────────────────────────
printf "\n${GREEN}  ✓ Update complete!${RESET}\n\n"
compose exec -T app php bin/oci --version 2>/dev/null || true
printf "\n"
