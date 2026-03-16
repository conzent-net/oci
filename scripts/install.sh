#!/bin/sh
set -eu

# ──────────────────────────────────────────────────────────────
# Conzent OCI — One-Line Installer
# https://getconzent.com
#
# Usage:
#   curl -sSL https://getconzent.com/install | sh
#   curl -sSL https://getconzent.com/install | sh -s -- --dir /opt/conzent
#
# ──────────────────────────────────────────────────────────────

REPO_URL="https://github.com/conzent-net/oci.git"
INSTALL_DIR="conzent"
BRANCH="main"
SKIP_START=false
UNINSTALL=false
SHOW_CONFIG=false
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
NEEDS_SUDO_DOCKER=false

# ── Colors & UI ──────────────────────────────────────────────

if [ -t 1 ]; then
    BOLD='\033[1m'
    DIM='\033[2m'
    GREEN='\033[0;32m'
    YELLOW='\033[0;33m'
    RED='\033[0;31m'
    CYAN='\033[0;36m'
    MAGENTA='\033[0;35m'
    RESET='\033[0m'
    CLEAR_LINE='\033[2K\r'
else
    BOLD='' DIM='' GREEN='' YELLOW='' RED='' CYAN='' MAGENTA='' RESET='' CLEAR_LINE=''
fi

info()    { printf "${CYAN}  ▸${RESET} %b\n" "$*"; }
success() { printf "${GREEN}  ✓${RESET} %b\n" "$*"; }
warn()    { printf "${YELLOW}  ⚠${RESET} %b\n" "$*"; }
error()   { printf "${RED}  ✗${RESET} %b\n" "$*" >&2; }
fatal()   { error "$*"; exit 1; }
step()    { printf "\n${BOLD}${MAGENTA}  ■${RESET} ${BOLD}%b${RESET}\n" "$*"; }

# ── Spinner: run a command with animated progress ────────────

SPINNER_PID=""

spinner_start() {
    _msg="$1"
    if [ -t 1 ]; then
        (
            _frames='⠋ ⠙ ⠹ ⠸ ⠼ ⠴ ⠦ ⠧ ⠇ ⠏'
            _i=0
            while true; do
                for _f in $_frames; do
                    printf "${CLEAR_LINE}${CYAN}  %s${RESET} ${DIM}%s${RESET}" "$_f" "$_msg"
                    sleep 0.08 2>/dev/null || sleep 1
                done
            done
        ) &
        SPINNER_PID=$!
    else
        printf "  %s..." "$_msg"
    fi
}

spinner_stop() {
    if [ -n "$SPINNER_PID" ]; then
        kill "$SPINNER_PID" 2>/dev/null || true
        wait "$SPINNER_PID" 2>/dev/null || true
        SPINNER_PID=""
        printf "${CLEAR_LINE}"
    fi
}

# Run a command silently with a spinner animation
run_with_spinner() {
    _label="$1"
    shift
    _logfile=$(mktemp /tmp/conzent-install-XXXXXX.log 2>/dev/null || echo "/tmp/conzent-install-$$.log")

    spinner_start "$_label"
    if "$@" > "$_logfile" 2>&1; then
        spinner_stop
        success "$_label"
        rm -f "$_logfile"
        return 0
    else
        spinner_stop
        error "$_label — failed!"
        printf "\n${DIM}"
        tail -20 "$_logfile" 2>/dev/null
        printf "${RESET}\n"
        rm -f "$_logfile"
        return 1
    fi
}

# Like run_with_spinner but for root commands
run_root_with_spinner() {
    _label="$1"
    shift
    _logfile=$(mktemp /tmp/conzent-install-XXXXXX.log 2>/dev/null || echo "/tmp/conzent-install-$$.log")

    spinner_start "$_label"
    if run_root "$@" > "$_logfile" 2>&1; then
        spinner_stop
        success "$_label"
        rm -f "$_logfile"
        return 0
    else
        spinner_stop
        error "$_label — failed!"
        printf "\n${DIM}"
        tail -20 "$_logfile" 2>/dev/null
        printf "${RESET}\n"
        rm -f "$_logfile"
        return 1
    fi
}

banner() {
    printf "\n"
    printf "${BOLD}${CYAN}"
    printf "   ____      _     ____                          _\n"
    printf "  / ___| ___| |_  / ___|___  _ __  _______  _ __| |_\n"
    printf " | |  _ / _ \\ __|| |   / _ \\| '_ \\|_  / _ \\| '_ \\ __|\n"
    printf " | |_| |  __/ |_ | |__| (_) | | | |/ /  __/| | | | |_\n"
    printf "  \\____|\\___|\\___|\\____\\___/|_| |_/___\\___||_| |_|\\__|\n"
    printf "${RESET}\n"
    printf "  ${BOLD}Open Consent Infrastructure${RESET}  ${DIM}v1.0${RESET}\n"
    printf "  ${DIM}Self-Hosted Consent Management Platform${RESET}\n"
    printf "\n"
}

# ── Parse arguments ──────────────────────────────────────────

while [ $# -gt 0 ]; do
    case "$1" in
        --dir)            INSTALL_DIR="$2"; shift 2 ;;
        --branch)         BRANCH="$2"; shift 2 ;;
        --admin-email)    ADMIN_EMAIL="$2"; shift 2 ;;
        --admin-password) ADMIN_PASSWORD="$2"; shift 2 ;;
        --no-start)       SKIP_START=true; shift ;;
        --uninstall)      UNINSTALL=true; shift ;;
        --config)         SHOW_CONFIG=true; shift ;;
        --help|-h)
            banner
            printf "  ${BOLD}Usage:${RESET}\n"
            printf "    curl -sSL https://getconzent.com/install | sh\n"
            printf "    curl -sSL https://getconzent.com/install | sh -s -- [OPTIONS]\n\n"
            printf "  ${BOLD}Options:${RESET}\n"
            printf "    --dir DIR              Installation directory (default: ./conzent)\n"
            printf "    --branch NAME          Git branch to clone (default: main)\n"
            printf "    --admin-email EMAIL    Admin account email (prompted if omitted)\n"
            printf "    --admin-password PASS  Admin account password (auto-generated if omitted)\n"
            printf "    --no-start             Clone and configure only, don't start containers\n"
            printf "    --config               Show saved admin credentials and app URL\n"
            printf "    --uninstall            Stop containers and remove the installation\n"
            printf "    --help                 Show this help message\n"
            printf "\n"
            exit 0
            ;;
        *) fatal "Unknown option: $1 (use --help for usage)" ;;
    esac
done

# ── Helper: check if a command exists ─────────────────────────

check_command() {
    command -v "$1" > /dev/null 2>&1
}

# ── Helper: detect OS ────────────────────────────────────────

OS_DETECTED=false
OS_ID="unknown"
OS_ID_LIKE=""
OS_VERSION=""

detect_os() {
    if [ "$OS_DETECTED" = true ]; then
        return 0
    fi
    if [ -f /etc/os-release ]; then
        . /etc/os-release
        OS_ID="$ID"
        OS_ID_LIKE="${ID_LIKE:-}"
        OS_VERSION="${VERSION_ID:-}"
    elif check_command lsb_release; then
        OS_ID="$(lsb_release -si | tr '[:upper:]' '[:lower:]')"
        OS_VERSION="$(lsb_release -sr)"
    fi
    OS_DETECTED=true
}

# ── Helper: run a command with sudo if not root ───────────────

run_root() {
    if [ "$(id -u)" -eq 0 ]; then
        "$@"
    elif check_command sudo; then
        sudo "$@"
    else
        fatal "This step requires root privileges. Please run as root or install sudo."
    fi
}

# ── Helper: run docker with sudo if needed ────────────────────

run_docker() {
    if [ "$NEEDS_SUDO_DOCKER" = true ]; then
        run_root "$@"
    else
        "$@"
    fi
}

# Run a compose command (handles sudo transparently)
compose() {
    run_docker $COMPOSE_CMD "$@"
}

# ── Install Docker ────────────────────────────────────────────

install_docker() {
    detect_os

    case "$OS_ID" in
        ubuntu|debian|raspbian)
            run_root_with_spinner "Installing Docker via official script" \
                sh -c 'curl -fsSL https://get.docker.com | sh' \
                || fatal "Docker installation failed. See https://docs.docker.com/get-docker/"
            ;;
        centos|rhel|rocky|almalinux|fedora)
            run_root_with_spinner "Installing Docker via official script" \
                sh -c 'curl -fsSL https://get.docker.com | sh' \
                || fatal "Docker installation failed. See https://docs.docker.com/get-docker/"
            ;;
        amzn)
            run_root_with_spinner "Installing Docker" yum install -y docker \
                || fatal "Docker installation failed"
            run_root_with_spinner "Starting Docker service" systemctl start docker
            run_root systemctl enable docker > /dev/null 2>&1 || true
            # Install Compose plugin
            COMPOSE_VERSION=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest | grep '"tag_name"' | head -1 | cut -d'"' -f4)
            run_root mkdir -p /usr/local/lib/docker/cli-plugins
            run_root_with_spinner "Installing Docker Compose plugin" \
                curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" \
                -o /usr/local/lib/docker/cli-plugins/docker-compose
            run_root chmod +x /usr/local/lib/docker/cli-plugins/docker-compose
            ;;
        alpine)
            run_root_with_spinner "Installing Docker" apk add --no-cache docker docker-compose \
                || fatal "Docker installation failed"
            run_root rc-update add docker default > /dev/null 2>&1 || true
            run_root_with_spinner "Starting Docker service" service docker start
            ;;
        arch|manjaro)
            run_root_with_spinner "Installing Docker" pacman -Sy --noconfirm docker docker-compose \
                || fatal "Docker installation failed"
            run_root_with_spinner "Starting Docker service" systemctl start docker
            run_root systemctl enable docker > /dev/null 2>&1 || true
            ;;
        opensuse*|sles)
            run_root_with_spinner "Installing Docker" zypper install -y docker docker-compose \
                || fatal "Docker installation failed"
            run_root_with_spinner "Starting Docker service" systemctl start docker
            run_root systemctl enable docker > /dev/null 2>&1 || true
            ;;
        *)
            case "$OS_ID_LIKE" in
                *debian*|*ubuntu*)
                    run_root_with_spinner "Installing Docker via official script" \
                        sh -c 'curl -fsSL https://get.docker.com | sh' \
                        || fatal "Docker installation failed. See https://docs.docker.com/get-docker/"
                    ;;
                *rhel*|*fedora*|*centos*)
                    run_root_with_spinner "Installing Docker via official script" \
                        sh -c 'curl -fsSL https://get.docker.com | sh' \
                        || fatal "Docker installation failed. See https://docs.docker.com/get-docker/"
                    ;;
                *)
                    fatal "Unsupported OS: $OS_ID. Please install Docker manually: https://docs.docker.com/get-docker/"
                    ;;
            esac
            ;;
    esac

    # Verify Docker installed
    if ! check_command docker; then
        fatal "Docker installation failed. Please install manually: https://docs.docker.com/get-docker/"
    fi

    # Add current user to docker group (avoids needing sudo for docker commands)
    if [ "$(id -u)" -ne 0 ] && ! id -nG 2>/dev/null | grep -qw docker; then
        run_root usermod -aG docker "$(whoami)" > /dev/null 2>&1 || true
        # Since we can't re-login mid-script, we'll use sudo for docker commands
        NEEDS_SUDO_DOCKER=true
    fi

    # Start and enable Docker daemon
    if ! run_root docker info > /dev/null 2>&1; then
        if check_command systemctl; then
            run_root_with_spinner "Starting Docker daemon" systemctl start docker
            run_root systemctl enable docker > /dev/null 2>&1 || true
        elif check_command service; then
            run_root_with_spinner "Starting Docker daemon" service docker start
        fi
    fi

    # Final check
    if ! run_root docker info > /dev/null 2>&1; then
        fatal "Docker daemon failed to start. Run: sudo systemctl status docker"
    fi

    success "Docker ready"
}

# ── Install Git ───────────────────────────────────────────────

install_git() {
    detect_os

    case "$OS_ID" in
        ubuntu|debian|raspbian)
            run_root apt-get update -qq > /dev/null 2>&1
            run_root_with_spinner "Installing Git" apt-get install -y -qq git
            ;;
        centos|rhel|rocky|almalinux|fedora)
            if check_command dnf; then
                run_root_with_spinner "Installing Git" dnf install -y -q git
            else
                run_root_with_spinner "Installing Git" yum install -y -q git
            fi
            ;;
        amzn)
            run_root_with_spinner "Installing Git" yum install -y -q git
            ;;
        alpine)
            run_root_with_spinner "Installing Git" apk add --no-cache git
            ;;
        arch|manjaro)
            run_root_with_spinner "Installing Git" pacman -Sy --noconfirm git
            ;;
        opensuse*|sles)
            run_root_with_spinner "Installing Git" zypper install -y git
            ;;
        *)
            case "$OS_ID_LIKE" in
                *debian*|*ubuntu*)
                    run_root apt-get update -qq > /dev/null 2>&1
                    run_root_with_spinner "Installing Git" apt-get install -y -qq git
                    ;;
                *rhel*|*fedora*|*centos*)
                    if check_command dnf; then
                        run_root_with_spinner "Installing Git" dnf install -y -q git
                    else
                        run_root_with_spinner "Installing Git" yum install -y -q git
                    fi
                    ;;
                *)
                    fatal "Unsupported OS: $OS_ID. Please install git manually."
                    ;;
            esac
            ;;
    esac

    if ! check_command git; then
        fatal "Git installation failed. Please install manually."
    fi
}

# ── Install curl ──────────────────────────────────────────────

install_curl() {
    detect_os

    case "$OS_ID" in
        ubuntu|debian|raspbian)
            run_root apt-get update -qq > /dev/null 2>&1
            run_root_with_spinner "Installing curl" apt-get install -y -qq curl
            ;;
        centos|rhel|rocky|almalinux|fedora|amzn)
            if check_command dnf; then
                run_root_with_spinner "Installing curl" dnf install -y -q curl
            else
                run_root_with_spinner "Installing curl" yum install -y -q curl
            fi
            ;;
        alpine)
            run_root_with_spinner "Installing curl" apk add --no-cache curl
            ;;
        arch|manjaro)
            run_root_with_spinner "Installing curl" pacman -Sy --noconfirm curl
            ;;
        opensuse*|sles)
            run_root_with_spinner "Installing curl" zypper install -y curl
            ;;
        *)
            fatal "Cannot install curl automatically. Please install curl and re-run."
            ;;
    esac
}

# ── Prerequisite checks (with auto-install) ──────────────────

check_prerequisites() {
    step "Checking prerequisites"

    detect_os
    info "Detected ${BOLD}${OS_ID}${RESET} $(uname -m)"

    # curl
    if ! check_command curl; then
        install_curl
    fi

    # Git
    if check_command git; then
        success "Git $(git --version | cut -d' ' -f3)"
    else
        install_git
    fi

    # Docker
    if check_command docker; then
        _docker_ver=$(docker --version 2>/dev/null | cut -d' ' -f3 | tr -d ',')
        success "Docker ${_docker_ver}"
        # Detect if current user can talk to Docker without sudo
        if ! docker info > /dev/null 2>&1; then
            if run_root docker info > /dev/null 2>&1; then
                NEEDS_SUDO_DOCKER=true
            fi
        fi
    else
        install_docker
    fi

    # Docker Compose (v2 plugin or standalone)
    if run_docker docker compose version > /dev/null 2>&1; then
        COMPOSE_CMD="docker compose"
        _compose_ver=$(run_docker docker compose version 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
        success "Docker Compose ${_compose_ver}"
    elif check_command docker-compose; then
        COMPOSE_CMD="docker-compose"
        success "Docker Compose (standalone)"
    else
        spinner_start "Installing Docker Compose plugin"
        COMPOSE_VERSION=$(curl -fsSL https://api.github.com/repos/docker/compose/releases/latest | grep '"tag_name"' | head -1 | cut -d'"' -f4)
        COMPOSE_DIR="${HOME}/.docker/cli-plugins"
        mkdir -p "$COMPOSE_DIR"
        curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-$(uname -s)-$(uname -m)" \
            -o "$COMPOSE_DIR/docker-compose" 2>/dev/null
        chmod +x "$COMPOSE_DIR/docker-compose"
        spinner_stop

        if run_docker docker compose version > /dev/null 2>&1; then
            COMPOSE_CMD="docker compose"
            success "Docker Compose plugin installed"
        else
            fatal "Docker Compose installation failed. Install manually: https://docs.docker.com/compose/install/"
        fi
    fi

    # Check Docker daemon is running (use sudo as fallback)
    if ! run_docker docker info > /dev/null 2>&1; then
        # Maybe it's just not started yet
        if check_command systemctl; then
            run_root_with_spinner "Starting Docker daemon" systemctl start docker
            run_root systemctl enable docker > /dev/null 2>&1 || true
        elif check_command service; then
            run_root_with_spinner "Starting Docker daemon" service docker start
        else
            fatal "Cannot start Docker daemon. Please start it manually and re-run."
        fi
        sleep 2
        if ! run_docker docker info > /dev/null 2>&1; then
            fatal "Docker daemon failed to start. Run: sudo systemctl status docker"
        fi
    fi
    success "Docker daemon running"

    printf "\n"
    success "All prerequisites met"
}

# ── Generate secure secret ───────────────────────────────────

generate_secret() {
    if check_command openssl; then
        openssl rand -hex 32
    elif [ -r /dev/urandom ]; then
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
    else
        date +%s%N | sha256sum | head -c 64
    fi
}

# ── Clone repository ─────────────────────────────────────────

clone_repo() {
    step "Downloading Conzent OCI"

    if [ -d "$INSTALL_DIR" ]; then
        if [ -d "$INSTALL_DIR/.git" ]; then
            warn "Directory '$INSTALL_DIR' already exists"
            cd "$INSTALL_DIR"
            # Fix ownership if Docker created root-owned files
            if [ "$(id -u)" -ne 0 ] && ! [ -w "." ]; then
                run_root chown -R "$(id -u):$(id -g)" . 2>/dev/null || true
            fi
            run_with_spinner "Pulling latest changes" git pull origin "$BRANCH" --ff-only \
                || fatal "Failed to update. Resolve conflicts manually."
            return 0
        else
            fatal "Directory '$INSTALL_DIR' already exists and is not a git repo. Remove it or use --dir."
        fi
    fi

    run_with_spinner "Cloning repository" git clone --depth 1 --branch "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
    cd "$INSTALL_DIR"
    success "Downloaded to $(pwd)"
}

# ── Configure environment ────────────────────────────────────

configure_env() {
    step "Configuring environment"

    if [ -f .env ]; then
        warn ".env already exists — skipping"
        return 0
    fi

    if [ ! -f .env.example ]; then
        fatal ".env.example not found. The repository may be incomplete."
    fi

    APP_SECRET=$(generate_secret)
    DB_PASSWORD=$(generate_secret | head -c 24)

    sed \
        -e "s|APP_SECRET=change-me-to-a-random-64-char-string|APP_SECRET=${APP_SECRET}|" \
        -e "s|DB_PASSWORD=oci|DB_PASSWORD=${DB_PASSWORD}|" \
        -e "s|DATABASE_URL=mysql://oci:oci@mariadb:3306/oci?charset=utf8mb4|DATABASE_URL=mysql://oci:${DB_PASSWORD}@mariadb:3306/oci?charset=utf8mb4|" \
        -e "s|MONETIZATION_MODEL=saas|MONETIZATION_MODEL=oci|" \
        .env.example > .env

    success "Generated secure configuration"
}

# ── Start services ───────────────────────────────────────────

start_services() {
    if [ "$SKIP_START" = true ]; then
        warn "Skipping container startup (--no-start)"
        return 0
    fi

    step "Starting services"

    # Clean up any stale containers/volumes from previous installs
    # (e.g. MariaDB volume with old password after rm -rf without docker compose down)
    # Always run down -v — even if no containers are running, orphaned volumes
    # from a previous install (with different DB credentials) will cause auth failures
    compose down -v --remove-orphans > /dev/null 2>&1 || true

    run_with_spinner "Building containers (this may take a few minutes)" \
        compose up -d --build

    # Wait for MariaDB with spinner
    spinner_start "Waiting for MariaDB"
    TRIES=0
    MAX_TRIES=60
    while [ $TRIES -lt $MAX_TRIES ]; do
        if compose exec -T mariadb mariadb-admin ping -u root --silent > /dev/null 2>&1; then
            spinner_stop
            success "MariaDB ready"
            break
        fi
        TRIES=$((TRIES + 1))
        if [ $TRIES -eq $MAX_TRIES ]; then
            spinner_stop
            fatal "MariaDB did not start within ${MAX_TRIES}s. Check: docker compose logs mariadb"
        fi
        sleep 1
    done

    # Wait for Redis with spinner
    spinner_start "Waiting for Redis"
    TRIES=0
    while [ $TRIES -lt 15 ]; do
        if compose exec -T redis redis-cli ping 2>/dev/null | grep -q PONG; then
            spinner_stop
            success "Redis ready"
            break
        fi
        TRIES=$((TRIES + 1))
        sleep 1
    done
    spinner_stop 2>/dev/null

    # Run migrations
    run_with_spinner "Running database migrations" \
        compose exec -T app php bin/oci migrations:migrate

    # Create admin account
    create_admin_account

    # Health check
    spinner_start "Running health check"
    sleep 2
    if compose exec -T app php bin/oci health > /dev/null 2>&1; then
        spinner_stop
        success "Health check passed"
    else
        spinner_stop
        warn "Health check returned warnings (usually fine on first install)"
    fi
}

# ── Create admin account ─────────────────────────────────────

create_admin_account() {
    step "Creating admin account"

    # If both email and password provided via CLI args, use them directly
    if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
        run_with_spinner "Setting up admin account" \
            compose exec -T app php bin/oci setup --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD"
        save_credentials "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
        return 0
    fi

    # Always generate a random password
    ADMIN_PASSWORD=$(generate_secret | head -c 16)

    # Ask for email if not provided
    # Note: curl|sh makes stdin non-interactive, so we read from /dev/tty
    if [ -z "$ADMIN_EMAIL" ]; then
        if [ -e /dev/tty ]; then
            printf "\n"
            printf "  ${BOLD}Enter your email address:${RESET} "
            read -r ADMIN_EMAIL < /dev/tty
            printf "\n"

            if [ -z "$ADMIN_EMAIL" ]; then
                warn "Skipping admin creation. Create one later with:"
                printf "    cd $INSTALL_DIR && sudo docker compose exec app php bin/oci setup\n"
                return 0
            fi
        else
            warn "No terminal available. Skipping admin creation."
            printf "  ${DIM}Run this after install to create your admin account:${RESET}\n"
            printf "    cd $INSTALL_DIR && sudo docker compose exec app php bin/oci setup\n\n"
            return 0
        fi
    fi

    run_with_spinner "Creating admin account" \
        compose exec -T app php bin/oci setup --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD"
    save_credentials "$ADMIN_EMAIL" "$ADMIN_PASSWORD"
}

# ── Save/show credentials ────────────────────────────────────

save_credentials() {
    _email="$1"
    _password="$2"
    _app_url=$(grep -E '^APP_URL=' .env 2>/dev/null | cut -d= -f2- || echo "http://localhost")
    _cred_file=".conzent-credentials"

    cat > "$_cred_file" <<CRED
# Conzent OCI — Admin Credentials
# Generated on $(date)
# Keep this file safe — do not commit to version control.

ADMIN_EMAIL=$_email
ADMIN_PASSWORD=$_password
APP_URL=$_app_url
CRED

    chmod 600 "$_cred_file"
}

# ── Resolve install directory (for --config / --uninstall) ────

resolve_install_dir() {
    # 1. Explicit --dir takes priority
    if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
        return 0
    fi

    # 2. Check if we're already inside the install dir
    if [ -f "./docker-compose.yml" ] && [ -f "./.env" ] && [ -d "./.git" ]; then
        INSTALL_DIR="."
        return 0
    fi

    # 3. Try common locations
    for _candidate in \
        "$HOME/conzent" \
        "$HOME/conzent-oci" \
        "/opt/conzent" \
        "/srv/conzent" \
    ; do
        if [ -d "$_candidate" ] && [ -f "$_candidate/docker-compose.yml" ]; then
            INSTALL_DIR="$_candidate"
            return 0
        fi
    done

    fatal "Could not find Conzent OCI installation. Use --dir to specify the location."
}

show_config() {
    banner
    resolve_install_dir

    cd "$INSTALL_DIR"

    CRED_FILE=".conzent-credentials"
    if [ ! -f "$CRED_FILE" ]; then
        fatal "No credentials file found. Was Conzent OCI installed in this directory?"
    fi

    _email=$(grep -E '^ADMIN_EMAIL=' "$CRED_FILE" | cut -d= -f2-)
    _password=$(grep -E '^ADMIN_PASSWORD=' "$CRED_FILE" | cut -d= -f2-)
    _app_url=$(grep -E '^APP_URL=' "$CRED_FILE" | cut -d= -f2-)

    printf "  ${BOLD}┌─────────────────────────────────────────┐${RESET}\n"
    printf "  ${BOLD}│${RESET}  ${BOLD}Conzent OCI Configuration${RESET}               ${BOLD}│${RESET}\n"
    printf "  ${BOLD}├─────────────────────────────────────────┤${RESET}\n"
    printf "  ${BOLD}│${RESET}                                         ${BOLD}│${RESET}\n"
    printf "  ${BOLD}│${RESET}  ${DIM}URL:${RESET}       %-26s ${BOLD}│${RESET}\n" "$_app_url"
    printf "  ${BOLD}│${RESET}  ${DIM}Email:${RESET}     %-26s ${BOLD}│${RESET}\n" "$_email"
    printf "  ${BOLD}│${RESET}  ${DIM}Password:${RESET}  ${CYAN}%-26s${RESET} ${BOLD}│${RESET}\n" "$_password"
    printf "  ${BOLD}│${RESET}                                         ${BOLD}│${RESET}\n"
    printf "  ${BOLD}│${RESET}  ${DIM}Directory:${RESET} %-26s ${BOLD}│${RESET}\n" "$(pwd)"
    printf "  ${BOLD}└─────────────────────────────────────────┘${RESET}\n"
    printf "\n"
}

# ── Print completion message ─────────────────────────────────

print_success() {
    APP_PORT=$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2- || echo "80")
    if [ "$APP_PORT" = "80" ]; then
        APP_URL="http://localhost"
    else
        APP_URL="http://localhost:${APP_PORT}"
    fi

    # Detect LAN IP for remote access
    LAN_IP=""
    if check_command hostname; then
        LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
    fi
    if [ -z "$LAN_IP" ] && check_command ip; then
        LAN_IP=$(ip route get 1.1.1.1 2>/dev/null | grep -oP 'src \K[0-9.]+' | head -1)
    fi
    LAN_URL=""
    if [ -n "$LAN_IP" ]; then
        if [ "$APP_PORT" = "80" ]; then
            LAN_URL="http://${LAN_IP}"
        else
            LAN_URL="http://${LAN_IP}:${APP_PORT}"
        fi
    fi

    printf "\n"
    printf "${GREEN}  ╔═══════════════════════════════════════════════╗${RESET}\n"
    printf "${GREEN}  ║                                               ║${RESET}\n"
    printf "${GREEN}  ║${RESET}   ${BOLD}${GREEN}✓ Conzent OCI installed successfully!${RESET}      ${GREEN}║${RESET}\n"
    printf "${GREEN}  ║                                               ║${RESET}\n"
    printf "${GREEN}  ╚═══════════════════════════════════════════════╝${RESET}\n"

    if [ "$SKIP_START" = true ]; then
        printf "\n"
        printf "  Start the application:\n"
        printf "    ${CYAN}cd %s && docker compose up -d${RESET}\n" "$(pwd)"
    else
        printf "\n"
        printf "  ${BOLD}Open your browser:${RESET}\n"
        printf "\n"
        printf "    ${DIM}On this machine:${RESET}   ${CYAN}%s${RESET}\n" "$APP_URL"
        if [ -n "$LAN_URL" ]; then
        printf "    ${DIM}From your network:${RESET} ${CYAN}%s${RESET}\n" "$LAN_URL"
        fi
        printf "\n"
        printf "  ${BOLD}Your credentials:${RESET}\n"
        printf "    ${DIM}Email:${RESET}     %s\n" "$ADMIN_EMAIL"
        printf "    ${DIM}Password:${RESET}  ${CYAN}%s${RESET}\n" "$ADMIN_PASSWORD"
    fi

    printf "\n"
    printf "  ${DIM}─────────────────────────────────────────────${RESET}\n"
    printf "\n"
    printf "  ${BOLD}Installed to:${RESET} %s\n" "$(pwd)"
    printf "\n"
    printf "  ${BOLD}Commands:${RESET}\n"
    printf "    ${CYAN}cd %s${RESET}\n" "$(pwd)"
    printf "    ${DIM}docker compose logs -f${RESET}             View logs\n"
    printf "    ${DIM}docker compose ps${RESET}                 Check status\n"
    printf "    ${DIM}docker compose down${RESET}               Stop services\n"
    printf "    ${DIM}docker compose up -d${RESET}              Start services\n"
    printf "    ${DIM}bash scripts/deploy.sh${RESET}            Deploy updates\n"
    printf "    ${DIM}bash scripts/install.sh --config${RESET}  Show credentials\n"
    printf "\n"
    printf "  ${BOLD}Docs:${RESET}    ${DIM}https://getconzent.com/docs/${RESET}\n"
    printf "  ${BOLD}Issues:${RESET}  ${DIM}https://github.com/conzent-net/oci/issues${RESET}\n"
    printf "\n"
}

# ── Uninstall ─────────────────────────────────────────────────

uninstall() {
    banner
    resolve_install_dir

    cd "$INSTALL_DIR"

    printf "  ${RED}${BOLD}This will permanently remove Conzent OCI${RESET}\n"
    printf "  ${DIM}Directory: $(pwd)${RESET}\n"
    printf "  ${DIM}All data (database, uploads, config) will be deleted.${RESET}\n"
    printf "\n"

    if [ -e /dev/tty ]; then
        printf "  Type ${BOLD}yes${RESET} to confirm: "
        read -r CONFIRM < /dev/tty
        if [ "$CONFIRM" != "yes" ]; then
            info "Uninstall cancelled."
            exit 0
        fi
        printf "\n"
    fi

    if check_command docker; then
        if run_docker docker compose version > /dev/null 2>&1; then
            _compose="compose"
        elif check_command docker-compose; then
            _compose="docker-compose"
        else
            _compose=""
        fi

        if [ -n "$_compose" ] && [ -f "docker-compose.yml" ]; then
            if [ "$_compose" = "compose" ]; then
                run_with_spinner "Stopping containers and removing volumes" \
                    run_docker docker compose down -v --remove-orphans || true
            else
                run_with_spinner "Stopping containers and removing volumes" \
                    $_compose down -v --remove-orphans || true
            fi
        fi
    fi

    cd ..
    # Docker creates root-owned files (volumes, build cache), so use sudo
    run_root_with_spinner "Removing installation directory" rm -rf "$INSTALL_DIR"

    printf "\n"
    printf "  ${GREEN}${BOLD}Conzent OCI has been uninstalled.${RESET}\n"
    printf "\n"
    printf "  ${DIM}Docker and Git were left in place (they may be used by other apps).${RESET}\n"
    printf "\n"
}

# ── Main ─────────────────────────────────────────────────────

main() {
    if [ "$SHOW_CONFIG" = true ]; then
        show_config
        exit 0
    fi

    if [ "$UNINSTALL" = true ]; then
        uninstall
        exit 0
    fi

    banner
    check_prerequisites
    clone_repo
    configure_env
    start_services
    print_success
}

main
