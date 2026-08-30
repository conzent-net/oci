#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
STACK_UUID=${STACK_UUID:-brrmsbi50m4q02lqx35juhlr}

actual_host=$(hostname -s)
[[ $actual_host == "$EXPECTED_HOST" ]] || {
    printf 'Expected host %s, found %s. Set EXPECTED_HOST deliberately if this is intentional.\n' "$EXPECTED_HOST" "$actual_host" >&2
    exit 1
}

printf 'Host: %s\n' "$actual_host"
printf 'Kernel: %s\n' "$(uname -r)"
printf 'Uptime: %s\n' "$(uptime -p)"
printf 'Default routes:\n'
ip -4 route show default || true
ip -6 route show default || true

printf '\nConzent containers:\n'
mapfile -t actual_services < <(docker ps \
    --filter "label=com.docker.compose.project=$STACK_UUID" \
    --format '{{.Label "com.docker.compose.service"}}' | sort)
expected_before=(app autoseo-render beacon-worker chat-ai elasticsearch mariadb nginx phpmyadmin redis scanner scheduler wix worker www)
expected_after=(app autoseo-render beacon-worker chat-ai elasticsearch mariadb nginx redis scanner scheduler wix worker www)
if diff -q <(printf '%s\n' "${expected_before[@]}" | sort) <(printf '%s\n' "${actual_services[@]}") >/dev/null; then
    service_phase=before-phpmyadmin-removal
elif diff -q <(printf '%s\n' "${expected_after[@]}" | sort) <(printf '%s\n' "${actual_services[@]}") >/dev/null; then
    service_phase=after-phpmyadmin-removal
else
    printf 'Unexpected running Conzent service set. Expected the audited 14 services or the remediated 13 services.\n' >&2
    exit 1
fi
printf 'Service phase: %s\n' "$service_phase"
docker ps -a --filter "label=com.docker.compose.project=$STACK_UUID" --format '{{.Names}}|{{.Status}}|{{.Ports}}' || {
    printf 'No containers found for stack %s. Stop and confirm the target.\n' "$STACK_UUID" >&2
    exit 1
}

app_container=$(docker ps -q --filter "name=app-${STACK_UUID}" | head -n 1)
[[ -n $app_container ]] || { printf 'Conzent app container not found.\n' >&2; exit 1; }

printf '\nApplication health:\n'
docker exec "$app_container" php bin/oci health
docker exec "$app_container" php bin/oci scanner:health

while IFS= read -r container_id; do
    state=$(docker inspect --format '{{.State.Status}} {{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")
    case $state in running\ healthy|running\ none) ;; *) printf 'Unhealthy Conzent container: %s (%s)\n' "$container_id" "$state" >&2; exit 1 ;; esac
done < <(docker ps -aq --filter "label=com.docker.compose.project=$STACK_UUID")

printf '\nCurrent non-secret runtime flags:\n'
docker inspect "$app_container" --format '{{range .Config.Env}}{{println .}}{{end}}' \
    | grep -E '^(APP_ENV|APP_DEBUG|APP_URL)=' || true

printf '\nSensitive listeners:\n'
ss -H -lnt | awk '$4 ~ /:(6001|6002|8000|8025|8090)$/ {print}' || true

printf '\nFailed services:\n'
systemctl --failed --no-pager --plain || true

printf '\nPending reboot: '
if [[ -f /var/run/reboot-required ]]; then printf 'yes (this kit will not reboot)\n'; else printf 'no\n'; fi

printf '\nPreflight passed. Keep this SSH session open and open a second session before applying firewall rules.\n'
