#!/usr/bin/env bash

set -Eeuo pipefail
EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root.' >&2; exit 1; }
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || { echo "Expected host $EXPECTED_HOST." >&2; exit 1; }

systemctl disable --now conzent-admin-firewall.service 2>/dev/null || true
if [[ -x /usr/local/sbin/conzent-admin-firewall ]]; then
    /usr/local/sbin/conzent-admin-firewall rollback
fi
rm -f /etc/systemd/system/docker.service.d/90-conzent-admin-firewall.conf
rm -f /etc/systemd/system/conzent-admin-firewall.service
rm -f /usr/local/sbin/conzent-admin-firewall
rm -f /etc/conzent-admin-firewall.conf
systemctl daemon-reload

iptables -nL INPUT --line-numbers
iptables -nL DOCKER-USER --line-numbers 2>/dev/null || true
ip6tables -nL INPUT --line-numbers
ip6tables -nL DOCKER-USER --line-numbers 2>/dev/null || true
printf 'Custom firewall chains were removed. Docker was not restarted.\n'
