#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || { echo "Expected host $EXPECTED_HOST." >&2; exit 1; }
for command_name in fail2ban-client systemctl /usr/sbin/sshd; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "Missing command: $command_name" >&2; exit 1; }
done

/usr/sbin/sshd -t
systemctl is-active --quiet ssh.service || systemctl is-active --quiet sshd.service || {
    echo 'SSH service is not active.' >&2
    exit 1
}

mapfile -t ssh_ports < <(/usr/sbin/sshd -T | awk '$1=="port" {print $2}' | sort -nu)
((${#ssh_ports[@]})) || ssh_ports=(22)
ssh_port_csv=$(IFS=,; echo "${ssh_ports[*]}")

state_dir="/var/backups/conzent-remediation/fail2ban/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$state_dir"
target=/etc/fail2ban/jail.d/zzzz-conzent-sshd.local
had_old=0
if [[ -e $target ]]; then
    cp -a "$target" "$state_dir/zzzz-conzent-sshd.local.before"
    had_old=1
fi

temp_file=$(mktemp)
trap 'rm -f "$temp_file"' EXIT
cat >"$temp_file" <<EOF
# Managed Conzent host override. Parsed after distro .conf and jail.local.
[selinux-ssh]
enabled = false

[sshd]
enabled = true
backend = systemd
port = $ssh_port_csv
ignoreip = 127.0.0.1/8 ::1 100.64.0.0/10
mode = normal
findtime = 10m
maxretry = 3
bantime = 1h
bantime.increment = true
EOF

install -d -m 0755 /etc/fail2ban/jail.d
install -m 0644 "$temp_file" "$target"

if ! fail2ban-client -t; then
    if ((had_old)); then
        cp -a "$state_dir/zzzz-conzent-sshd.local.before" "$target"
    else
        rm -f "$target"
    fi
    echo 'Fail2Ban config test failed; the previous state was restored. No service was restarted.' >&2
    exit 1
fi

systemctl enable fail2ban.service
if ! systemctl restart fail2ban.service; then
    journalctl -u fail2ban.service -n 100 --no-pager >&2
    if ((had_old)); then cp -a "$state_dir/zzzz-conzent-sshd.local.before" "$target"; else rm -f "$target"; fi
    fail2ban-client -t && systemctl restart fail2ban.service || true
    echo 'Fail2Ban restart failed; the previous override was restored and restart retried.' >&2
    exit 1
fi

systemctl is-active --quiet fail2ban.service
systemctl is-active --quiet ssh.service || systemctl is-active --quiet sshd.service
fail2ban-client status
fail2ban-client status sshd
printf 'Fail2Ban repaired. Prior override backup, if any: %s\n' "$state_dir"
