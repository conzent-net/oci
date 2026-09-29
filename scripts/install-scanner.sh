#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────
# Conzent OCI — Standalone Scanner Installer
# https://getconzent.com
#
# Adds scanning capacity to an existing Conzent install. Run this on
# any server; it installs Docker if missing, fetches only the scanner,
# starts it, verifies health, and prints the command to register it.
#
# Usage:
#   curl -sSL https://getconzent.com/install-scanner | sh
#   curl -sSL https://getconzent.com/install-scanner | sh -s -- --key mysecret --port 8300
#
# ──────────────────────────────────────────────────────────────

REPO="conzent-net/oci"
BRANCH="main"
INSTALL_DIR="conzent-scanner"
SCANNER_API_KEY=""
PORT="8300"
MAX_CONCURRENT="5"
SCAN_TIMEOUT="60000"
NAME=""
UNINSTALL=false
NEEDS_SUDO_DOCKER=false

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

banner() {
    printf "\n"
    printf "  ${BOLD}Conzent OCI — Scanner${RESET}\n"
    printf "  ${DIM}Adds cookie-scanning capacity to an existing install${RESET}\n"
    printf "\n"
}

# ── Parse arguments ──────────────────────────────────────────

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)       INSTALL_DIR="$2"; shift 2 ;;
        --key)       SCANNER_API_KEY="$2"; shift 2 ;;
        --port)      PORT="$2"; shift 2 ;;
        --max)       MAX_CONCURRENT="$2"; shift 2 ;;
        --timeout)   SCAN_TIMEOUT="$2"; shift 2 ;;
        --name)      NAME="$2"; shift 2 ;;
        --branch)    BRANCH="$2"; shift 2 ;;
        --uninstall) UNINSTALL=true; shift ;;
        --help|-h)
            banner
            printf "  ${BOLD}Usage:${RESET}\n"
            printf "    curl -sSL https://getconzent.com/install-scanner | sh\n"
            printf "    curl -sSL https://getconzent.com/install-scanner | sh -s -- [OPTIONS]\n\n"
            printf "  ${BOLD}Options:${RESET}\n"
            printf "    --key KEY        Shared secret (generated if omitted)\n"
            printf "    --port PORT      Host port to listen on (default: 8300)\n"
            printf "    --max N          Parallel browser sessions (default: 5)\n"
            printf "    --timeout MS     Per-page timeout in ms (default: 60000)\n"
            printf "    --name NAME      Label shown in the scan-server list\n"
            printf "    --dir DIR        Install directory (default: ./conzent-scanner)\n"
            printf "    --branch NAME    Git branch to fetch (default: main)\n"
            printf "    --uninstall      Stop and remove this scanner\n"
            printf "    --help           Show this help message\n\n"
            printf "  ${BOLD}Docs:${RESET} ${DIM}https://github.com/conzent-net/oci/blob/main/docs/scanning.md${RESET}\n\n"
            exit 0
            ;;
        *) fatal "Unknown option: $1 (use --help for usage)" ;;
    esac
done

# ── Helpers ──────────────────────────────────────────────────

check_command() { command -v "$1" > /dev/null 2>&1; }

run_root() {
    if [ "$(id -u)" -eq 0 ]; then
        "$@"
    elif check_command sudo; then
        sudo "$@"
    else
        fatal "This step requires root privileges. Run as root or install sudo."
    fi
}

run_docker() {
    if [ "$NEEDS_SUDO_DOCKER" = true ]; then run_root "$@"; else "$@"; fi
}

compose() { run_docker docker compose "$@"; }

generate_secret() {
    if check_command openssl; then
        openssl rand -hex 16
    elif [ -r /dev/urandom ]; then
        head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n'
    else
        date +%s%N | sha256sum | head -c 32
    fi
}

# Best-effort address the main app can reach this scanner on.
detect_host() {
    _ip=""
    if check_command hostname; then
        _ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    fi
    if [ -z "$_ip" ] && check_command ip; then
        _ip=$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[0-9.]+' | head -1)
    fi
    [ -n "$_ip" ] || _ip="<this-server-ip>"
    printf '%s' "$_ip"
}

# ── Uninstall ────────────────────────────────────────────────

if [ "$UNINSTALL" = true ]; then
    banner
    [ -d "$INSTALL_DIR" ] || fatal "Not found: $INSTALL_DIR (use --dir to point at it)"
    cd "$INSTALL_DIR"

    if ! docker info > /dev/null 2>&1 && check_command sudo && sudo docker info > /dev/null 2>&1; then
        NEEDS_SUDO_DOCKER=true
    fi

    step "Removing scanner"
    compose -f docker-compose.scanner.yml down --remove-orphans > /dev/null 2>&1 || true
    cd ..
    run_root rm -rf "$INSTALL_DIR"
    success "Scanner removed"
    printf "\n  ${DIM}Remember to deregister it in your Conzent install.${RESET}\n\n"
    exit 0
fi

banner

# ── 1. Docker ────────────────────────────────────────────────

step "Checking prerequisites"

if ! check_command docker; then
    warn "Docker not found — installing"
    check_command curl || fatal "curl is required to install Docker."
    curl -fsSL https://get.docker.com | run_root sh > /dev/null 2>&1 \
        || fatal "Docker installation failed. Install it manually: https://docs.docker.com/engine/install/"
    run_root systemctl enable --now docker > /dev/null 2>&1 || true
    success "Docker installed"
else
    success "Docker present"
fi

if ! docker info > /dev/null 2>&1; then
    if check_command sudo && sudo docker info > /dev/null 2>&1; then
        NEEDS_SUDO_DOCKER=true
        info "Using sudo for Docker commands"
    else
        fatal "Cannot talk to Docker. Is the daemon running, and do you have permission?"
    fi
fi

run_docker docker compose version > /dev/null 2>&1 \
    || fatal "Docker Compose v2 is required (docker compose). Update Docker and retry."

check_command curl || fatal "curl is required."
check_command tar  || fatal "tar is required."

success "Prerequisites ready"

# ── 2. Fetch just the scanner ────────────────────────────────

step "Fetching scanner"

if [ -d "$INSTALL_DIR" ]; then
    warn "Directory '$INSTALL_DIR' exists — refreshing scanner files"
else
    mkdir -p "$INSTALL_DIR"
fi

# Pull only docker/scanner/ out of the repo tarball — no git, no full clone.
# Download and extract are separate steps so a network failure and a
# "not in this branch" failure produce different, actionable messages.
TARBALL="https://codeload.github.com/${REPO}/tar.gz/refs/heads/${BRANCH}"
REPO_NAME=$(basename "$REPO")
ARCHIVE=$(mktemp)
# shellcheck disable=SC2064
trap "rm -f '$ARCHIVE'" EXIT INT TERM

curl -sSL -o "$ARCHIVE" "$TARBALL" \
    || fatal "Could not reach GitHub to download ${REPO}.\n    Check this server's outbound network access."

if [ ! -s "$ARCHIVE" ]; then
    fatal "Downloaded an empty archive from ${REPO} (${BRANCH}).\n    Does that branch exist?"
fi

tar xz -C "$INSTALL_DIR" --strip-components=3 -f "$ARCHIVE" \
    "${REPO_NAME}-${BRANCH}/docker/scanner" > /dev/null 2>&1 || true

if [ ! -f "$INSTALL_DIR/Dockerfile" ] || [ ! -f "$INSTALL_DIR/docker-compose.scanner.yml" ]; then
    fatal "The ${BRANCH} branch of ${REPO} has no docker/scanner directory yet.\n    Standalone scanners need a release that ships it — see\n    https://github.com/${REPO}/blob/main/docs/scanning.md"
fi

cd "$INSTALL_DIR"
success "Scanner files ready in $(pwd)"

# ── 3. Configure ─────────────────────────────────────────────

step "Configuring"

if [ -z "$SCANNER_API_KEY" ]; then
    if [ -f .env ] && grep -qE '^SCANNER_API_KEY=.+' .env; then
        SCANNER_API_KEY=$(grep -E '^SCANNER_API_KEY=' .env | head -1 | cut -d= -f2-)
        info "Reusing the existing API key"
    else
        SCANNER_API_KEY=$(generate_secret)
        success "Generated API key"
    fi
else
    success "Using the API key you supplied"
fi

cat > .env <<ENVFILE
# Conzent OCI — Scanner configuration
# The API key must match what you register on the main app.
SCANNER_API_KEY=${SCANNER_API_KEY}
SCANNER_PORT=${PORT}
MAX_CONCURRENT=${MAX_CONCURRENT}
SCAN_TIMEOUT=${SCAN_TIMEOUT}
ENVFILE
chmod 600 .env

[ "$PORT" = "8300" ] || success "Listening on port ${PORT}"

# ── 4. Start ─────────────────────────────────────────────────

step "Starting scanner"
info "First run builds the image (Chromium) — this takes a few minutes"

compose -f docker-compose.scanner.yml up -d --build > /dev/null 2>&1 \
    || fatal "Failed to start. Check: docker compose -f docker-compose.scanner.yml logs"

success "Container running"

# ── 5. Verify ────────────────────────────────────────────────

step "Verifying"

TRIES=0
HEALTHY=false
while [ $TRIES -lt 40 ]; do
    if curl -sf "http://localhost:${PORT}/health" > /dev/null 2>&1; then
        HEALTHY=true
        break
    fi
    TRIES=$((TRIES + 1))
    sleep 3
done

if [ "$HEALTHY" = true ]; then
    success "Health check passed"
else
    warn "Scanner did not answer /health within 2 minutes"
    info "Check the logs: cd $(pwd) && docker compose -f docker-compose.scanner.yml logs"
fi

HOST_ADDR=$(detect_host)
SCANNER_URL="http://${HOST_ADDR}:${PORT}"

# ── 6. Tell the operator how to register it ──────────────────

printf "\n"
printf "${GREEN}  ╔═══════════════════════════════════════════════╗${RESET}\n"
printf "${GREEN}  ║${RESET}   ${BOLD}${GREEN}✓ Scanner ready${RESET}                             ${GREEN}║${RESET}\n"
printf "${GREEN}  ╚═══════════════════════════════════════════════╝${RESET}\n"
printf "\n"
printf "  ${BOLD}URL:${RESET}      ${CYAN}%s${RESET}\n" "$SCANNER_URL"
printf "  ${BOLD}API key:${RESET}  ${CYAN}%s${RESET}\n" "$SCANNER_API_KEY"
printf "\n"
printf "  ${DIM}─────────────────────────────────────────────${RESET}\n"
printf "\n"
printf "  ${BOLD}Register it — run this on your Conzent server:${RESET}\n"
printf "\n"
if [ -n "$NAME" ]; then
    printf "    ${CYAN}docker compose exec app php bin/oci scanner:register \\\\${RESET}\n"
    printf "    ${CYAN}  --url=%s \\\\${RESET}\n" "$SCANNER_URL"
    printf "    ${CYAN}  --key=%s \\\\${RESET}\n" "$SCANNER_API_KEY"
    printf "    ${CYAN}  --name=\"%s\" --max=%s${RESET}\n" "$NAME" "$MAX_CONCURRENT"
else
    printf "    ${CYAN}docker compose exec app php bin/oci scanner:register \\\\${RESET}\n"
    printf "    ${CYAN}  --url=%s \\\\${RESET}\n" "$SCANNER_URL"
    printf "    ${CYAN}  --key=%s --max=%s${RESET}\n" "$SCANNER_API_KEY" "$MAX_CONCURRENT"
fi
printf "\n"
printf "  ${BOLD}Then confirm:${RESET}\n"
printf "    ${DIM}docker compose exec app php bin/oci scanner:health${RESET}\n"
printf "\n"
printf "  ${DIM}─────────────────────────────────────────────${RESET}\n"
printf "\n"
if [ "$HOST_ADDR" = "<this-server-ip>" ]; then
    warn "Could not detect this server's address — substitute it in the URL above."
else
    info "Behind NAT or a firewall? Use the address your Conzent server can reach,"
    info "and allow inbound TCP ${PORT} from it only — the API key is the sole guard."
fi
printf "\n"
printf "  ${BOLD}Manage:${RESET}\n"
printf "    ${DIM}cd %s${RESET}\n" "$(pwd)"
printf "    ${DIM}docker compose -f docker-compose.scanner.yml logs -f${RESET}    View logs\n"
printf "    ${DIM}docker compose -f docker-compose.scanner.yml ps${RESET}         Check status\n"
printf "    ${DIM}docker compose -f docker-compose.scanner.yml up -d --scale scanner=3${RESET}\n"
printf "\n"
printf "  ${BOLD}Docs:${RESET} ${DIM}https://github.com/conzent-net/oci/blob/main/docs/scanning.md${RESET}\n"
printf "\n"
