#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || { echo "Expected host $EXPECTED_HOST." >&2; exit 1; }
systemctl is-active --quiet docker.service || { echo 'Docker is not active before maintenance.' >&2; exit 1; }
if dpkg --audit | grep -q .; then dpkg --audit; echo 'Repair dpkg state first.' >&2; exit 1; fi
apt-get check

state_dir="/var/backups/conzent-remediation/updates/$(date -u +%Y%m%dT%H%M%SZ)-apply"
hold_state=/var/lib/conzent-maintenance
install -d -m 0700 "$state_dir" "$hold_state"
exec > >(tee -a "$state_dir/apply.log") 2>&1

docker_pid_before=$(systemctl show docker.service -p MainPID --value)
docker_active_since_before=$(systemctl show docker.service -p ActiveEnterTimestampMonotonic --value)
docker ps -q | sort >"$state_dir/containers.before"

protected_pattern='^(docker|containerd|runc|moby)([.:+-]|$)'
mapfile -t protected_packages < <(dpkg-query -W -f='${binary:Package}\n' | grep -E "$protected_pattern" || true)
touch "$hold_state/docker-holds-added"
chmod 0600 "$hold_state/docker-holds-added"
for package_name in "${protected_packages[@]}"; do
    if ! apt-mark showhold | grep -Fxq -- "$package_name"; then
        apt-mark hold "$package_name"
        grep -Fxq -- "$package_name" "$hold_state/docker-holds-added" \
            || printf '%s\n' "$package_name" >>"$hold_state/docker-holds-added"
    fi
done

apt-get -s --with-new-pkgs --no-remove upgrade | tee "$state_dir/simulation.txt"
if awk '$1=="Remv"{found=1} END{exit !found}' "$state_dir/simulation.txt"; then
    echo 'Simulation contains removals; refusing.' >&2
    exit 1
fi
if awk '$1=="Inst"{print $2}' "$state_dir/simulation.txt" | grep -Eq "$protected_pattern"; then
    echo 'Simulation still includes a protected Docker/runtime package; refusing.' >&2
    exit 1
fi

DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1 \
    apt-get -y --with-new-pkgs --no-remove \
    -o Dpkg::Options::='--force-confdef' \
    -o Dpkg::Options::='--force-confold' upgrade

systemctl is-active --quiet docker.service || { echo 'CRITICAL: Docker is inactive after updates.' >&2; exit 1; }
docker_pid_after=$(systemctl show docker.service -p MainPID --value)
docker_active_since_after=$(systemctl show docker.service -p ActiveEnterTimestampMonotonic --value)
if [[ $docker_pid_before != "$docker_pid_after" || $docker_active_since_before != "$docker_active_since_after" ]]; then
    echo 'CRITICAL: Docker restarted unexpectedly; inspect the maintenance log.' >&2
    exit 1
fi

docker info >/dev/null
docker ps -q | sort >"$state_dir/containers.after"
diff -u "$state_dir/containers.before" "$state_dir/containers.after" \
    || echo 'WARNING: running container set changed; investigate.'
command -v needrestart >/dev/null 2>&1 && needrestart -b -r l || true
systemctl --failed --no-legend || true

if [[ -f /var/run/reboot-required ]]; then
    echo 'Reboot remains required later; this script did not reboot.'
    cat /var/run/reboot-required.pkgs 2>/dev/null || true
fi
apt list --upgradable 2>/dev/null | tee "$state_dir/remaining-upgrades.txt"
printf 'Non-Docker updates applied without reboot. Docker/runtime holds remain in %s/docker-holds-added.\n' "$hold_state"
