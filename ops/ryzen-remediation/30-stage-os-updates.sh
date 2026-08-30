#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || { echo "Expected host $EXPECTED_HOST." >&2; exit 1; }
state_dir="/var/backups/conzent-remediation/updates/$(date -u +%Y%m%dT%H%M%SZ)-stage"
install -d -m 0700 "$state_dir"
exec > >(tee -a "$state_dir/stage.log") 2>&1

audit_output=$(dpkg --audit)
[[ -z $audit_output ]] || { printf '%s\n' "$audit_output"; echo 'Repair dpkg state before continuing.'; exit 1; }
apt-get check
apt-get update
apt list --upgradable 2>/dev/null | tee "$state_dir/upgradable.txt"
apt-get -s --with-new-pkgs --no-remove upgrade | tee "$state_dir/simulation.txt"
if awk '$1=="Remv"{found=1} END{exit !found}' "$state_dir/simulation.txt"; then
    echo 'Simulation contains removals; refusing.' >&2
    exit 1
fi
apt-get -y --download-only --with-new-pkgs --no-remove upgrade
printf 'Packages were downloaded only. Review %s/simulation.txt before applying.\n' "$state_dir"
