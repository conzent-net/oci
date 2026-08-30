#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || { echo "Expected host $EXPECTED_HOST." >&2; exit 1; }
for command_name in docker ip iptables ip6tables python3 systemctl; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "Missing command: $command_name" >&2; exit 1; }
done
systemctl is-active --quiet docker || { echo 'Docker is not active.' >&2; exit 1; }

public_raw=${PUBLIC_IFACES:-}
if [[ -z $public_raw ]]; then
    public_raw=$({ ip -4 route show default; ip -6 route show default; } | awk '{print $5}' | sort -u | xargs)
fi
[[ -n $public_raw ]] || { echo 'Set PUBLIC_IFACES, for example PUBLIC_IFACES=enp6s0.' >&2; exit 1; }
read -r -a public_ifaces <<< "$public_raw"

admin_v4=()
admin_v6=()
[[ -z ${ADMIN_V4_CIDRS:-} ]] || read -r -a admin_v4 <<< "$ADMIN_V4_CIDRS"
[[ -z ${ADMIN_V6_CIDRS:-} ]] || read -r -a admin_v6 <<< "$ADMIN_V6_CIDRS"

for interface_name in "${public_ifaces[@]}"; do
    ip link show dev "$interface_name" >/dev/null || { echo "No such interface: $interface_name" >&2; exit 1; }
done

tailscale_present=0
if ip link show tailscale0 >/dev/null 2>&1; then tailscale_present=1; fi
if ((tailscale_present == 0 && ${#admin_v4[@]} + ${#admin_v6[@]} == 0)); then
    echo 'No allowed management path: tailscale0 is absent and no admin CIDR was supplied.' >&2
    exit 1
fi

python3 - 4 "${admin_v4[@]}" <<'PY'
import ipaddress, sys
for value in sys.argv[2:]:
    network = ipaddress.ip_network(value, strict=False)
    if network.version != int(sys.argv[1]):
        raise SystemExit(f'Wrong address family: {value}')
PY
python3 - 6 "${admin_v6[@]}" <<'PY'
import ipaddress, sys
for value in sys.argv[2:]:
    network = ipaddress.ip_network(value, strict=False)
    if network.version != int(sys.argv[1]):
        raise SystemExit(f'Wrong address family: {value}')
PY

state_dir="/var/backups/conzent-remediation/firewall/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$state_dir"
iptables-save >"$state_dir/iptables.before"
ip6tables-save >"$state_dir/ip6tables.before" || true
iptables -S DOCKER-USER >"$state_dir/docker-user-v4.before" 2>&1 || true
ip6tables -S DOCKER-USER >"$state_dir/docker-user-v6.before" 2>&1 || true

config_tmp=$(mktemp)
trap 'rm -f "$config_tmp"' EXIT
{
    printf 'PUBLIC_IFACES=('; for value in "${public_ifaces[@]}"; do printf ' %q' "$value"; done; printf ' )\n'
    printf 'ADMIN_V4_CIDRS=('; for value in "${admin_v4[@]}"; do printf ' %q' "$value"; done; printf ' )\n'
    printf 'ADMIN_V6_CIDRS=('; for value in "${admin_v6[@]}"; do printf ' %q' "$value"; done; printf ' )\n'
} >"$config_tmp"
install -m 0600 "$config_tmp" /etc/conzent-admin-firewall.conf

install -m 0750 /dev/stdin /usr/local/sbin/conzent-admin-firewall <<'SCRIPT'
#!/usr/bin/env bash
set -Eeuo pipefail
export LC_ALL=C
source /etc/conzent-admin-firewall.conf
PORTS=(6001 6002 8000 8025 8090)
PORT_CSV=$(IFS=,; echo "${PORTS[*]}")

chain_exists() { "$1" -w 5 -nL "$2" >/dev/null 2>&1; }
new_chain() { chain_exists "$1" "$2" || "$1" -w 5 -N "$2"; "$1" -w 5 -F "$2"; }
remove_jump() {
    local binary=$1 parent=$2 child=$3
    chain_exists "$binary" "$parent" || return 0
    while "$binary" -w 5 -C "$parent" -j "$child" 2>/dev/null; do
        "$binary" -w 5 -D "$parent" -j "$child"
    done
}

apply_family() {
    local binary=$1 input_chain=$2 forward_chain=$3 cidr_array_name=$4
    local -n cidrs=$cidr_array_name
    local parent interface_name port cidr

    new_chain "$binary" "$input_chain"
    "$binary" -w 5 -A "$input_chain" -i lo -j RETURN
    "$binary" -w 5 -A "$input_chain" -i tailscale0 -j RETURN
    for interface_name in "${PUBLIC_IFACES[@]}"; do
        for cidr in "${cidrs[@]}"; do
            "$binary" -w 5 -A "$input_chain" -i "$interface_name" -s "$cidr" -p tcp -m multiport --dports "$PORT_CSV" -j RETURN
        done
        "$binary" -w 5 -A "$input_chain" -i "$interface_name" -p tcp -m multiport --dports "$PORT_CSV" -m conntrack --ctstate NEW -j DROP
    done
    "$binary" -w 5 -A "$input_chain" -j RETURN
    remove_jump "$binary" INPUT "$input_chain"
    "$binary" -w 5 -I INPUT 1 -j "$input_chain"

    new_chain "$binary" "$forward_chain"
    for interface_name in "${PUBLIC_IFACES[@]}"; do
        for cidr in "${cidrs[@]}"; do
            for port in "${PORTS[@]}"; do
                "$binary" -w 5 -A "$forward_chain" -i "$interface_name" -s "$cidr" -p tcp \
                    -m conntrack --ctstate NEW --ctdir ORIGINAL --ctorigdstport "$port" -j RETURN
            done
        done
        for port in "${PORTS[@]}"; do
            "$binary" -w 5 -A "$forward_chain" -i "$interface_name" -p tcp \
                -m conntrack --ctstate NEW --ctdir ORIGINAL --ctorigdstport "$port" -j DROP
        done
    done
    "$binary" -w 5 -A "$forward_chain" -j RETURN
    remove_jump "$binary" DOCKER-USER "$forward_chain"
    remove_jump "$binary" FORWARD "$forward_chain"
    if chain_exists "$binary" DOCKER-USER; then parent=DOCKER-USER; else parent=FORWARD; fi
    "$binary" -w 5 -I "$parent" 1 -j "$forward_chain"
}

rollback_family() {
    local binary=$1 input_chain=$2 forward_chain=$3
    command -v "$binary" >/dev/null 2>&1 || return 0
    remove_jump "$binary" INPUT "$input_chain"
    remove_jump "$binary" DOCKER-USER "$forward_chain"
    remove_jump "$binary" FORWARD "$forward_chain"
    "$binary" -w 5 -F "$input_chain" 2>/dev/null || true
    "$binary" -w 5 -X "$input_chain" 2>/dev/null || true
    "$binary" -w 5 -F "$forward_chain" 2>/dev/null || true
    "$binary" -w 5 -X "$forward_chain" 2>/dev/null || true
}

case ${1:-apply} in
    apply)
        apply_family iptables CZADMIN-IN CZADMIN-FWD ADMIN_V4_CIDRS
        apply_family ip6tables CZADMIN6-IN CZADMIN6-FWD ADMIN_V6_CIDRS
        ;;
    rollback)
        rollback_family iptables CZADMIN-IN CZADMIN-FWD
        rollback_family ip6tables CZADMIN6-IN CZADMIN6-FWD
        ;;
    *) echo 'usage: conzent-admin-firewall {apply|rollback}' >&2; exit 2 ;;
esac
SCRIPT

install -d -m 0755 /etc/systemd/system/docker.service.d
install -m 0644 /dev/stdin /etc/systemd/system/conzent-admin-firewall.service <<'UNIT'
[Unit]
Description=Restrict Conzent/Coolify administrative Docker ports
Wants=network-online.target docker.service
After=network-online.target docker.service tailscaled.service
PartOf=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/conzent-admin-firewall apply
ExecReload=/usr/local/sbin/conzent-admin-firewall apply
RemainAfterExit=yes
TimeoutStartSec=30s

[Install]
WantedBy=multi-user.target
UNIT
install -m 0644 /dev/stdin /etc/systemd/system/docker.service.d/90-conzent-admin-firewall.conf <<'DROPIN'
[Unit]
Wants=conzent-admin-firewall.service
DROPIN

firewall_committed=0
cleanup_failed_install() {
    local rc=$?
    trap - EXIT
    rm -f "$config_tmp"
    if ((firewall_committed == 0)); then
        systemctl disable --now conzent-admin-firewall.service >/dev/null 2>&1 || true
        /usr/local/sbin/conzent-admin-firewall rollback >/dev/null 2>&1 || true
        rm -f /etc/systemd/system/docker.service.d/90-conzent-admin-firewall.conf
        rm -f /etc/systemd/system/conzent-admin-firewall.service
        rm -f /usr/local/sbin/conzent-admin-firewall
        rm -f /etc/conzent-admin-firewall.conf
        systemctl daemon-reload || true
    fi
    exit "$rc"
}
trap cleanup_failed_install EXIT
systemctl daemon-reload
if ! systemctl enable --now conzent-admin-firewall.service; then
    echo 'Firewall installation failed; custom rules were rolled back.' >&2
    exit 1
fi

iptables -C INPUT -j CZADMIN-IN
iptables -C DOCKER-USER -j CZADMIN-FWD 2>/dev/null || iptables -C FORWARD -j CZADMIN-FWD
ip6tables -C INPUT -j CZADMIN6-IN
ip6tables -C DOCKER-USER -j CZADMIN6-FWD 2>/dev/null || ip6tables -C FORWARD -j CZADMIN6-FWD
systemctl is-active --quiet docker
firewall_committed=1

printf 'Firewall applied. Diagnostic snapshots: %s\n' "$state_dir"
printf 'Keep this session open. Verify a second SSH login, app health, and external port blocking now.\n'
