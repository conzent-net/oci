#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

die() {
    log "ERROR: $*" >&2
    exit 1
}

require_root() {
    [[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this script as root.'
}

require_commands() {
    local command_name
    for command_name in "$@"; do
        command -v "$command_name" >/dev/null 2>&1 || die "Required command not found: $command_name"
    done
}

make_state_dir() {
    local category=${1:?category required}
    local stamp state_dir
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    state_dir="/var/backups/conzent-remediation/${category}/${stamp}"
    install -d -m 0700 "$state_dir"
    printf '%s\n' "$state_dir"
}

read_secret() {
    local prompt=${1:?prompt required}
    local value
    read -r -s -p "$prompt" value
    printf '\n' >&2
    printf '%s' "$value"
}

json_value() {
    local value=${1-}
    python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$value"
}
