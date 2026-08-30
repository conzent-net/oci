#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C

PUBLIC_IP=${PUBLIC_IP:-157.180.5.57}
APP_HEALTH_URL=${APP_HEALTH_URL:-https://app.getconzent.com/health}
EXPECTED_SERVER_HOST=${EXPECTED_SERVER_HOST:-ryzen-prod}
BLOCKED_PORTS=(6001 6002 8000 8025 8090)

[[ $(hostname -s) != "$EXPECTED_SERVER_HOST" ]] || {
    echo 'Run this check from a separate workstation or external probe, not Ryzen.' >&2
    exit 1
}
for command_name in curl python3; do
    command -v "$command_name" >/dev/null 2>&1 || {
        echo "Missing command: $command_name" >&2
        exit 1
    }
done

curl --fail --silent --show-error --location \
    --connect-timeout 5 --max-time 15 "$APP_HEALTH_URL" >/dev/null

python3 - "$PUBLIC_IP" "${BLOCKED_PORTS[@]}" <<'PY'
import socket
import sys

host, *raw_ports = sys.argv[1:]
failures = []
for raw_port in raw_ports:
    port = int(raw_port)
    try:
        with socket.create_connection((host, port), timeout=3):
            failures.append(port)
    except (TimeoutError, ConnectionRefusedError, OSError):
        pass

if failures:
    joined = ", ".join(str(port) for port in failures)
    raise SystemExit(f"Public administrative ports still reachable: {joined}")
PY

printf 'External check passed: app health is reachable and administrative ports are blocked.\n'
