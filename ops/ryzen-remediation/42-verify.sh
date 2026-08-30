#!/usr/bin/env bash

set -Eeuo pipefail
set +x
export LC_ALL=C
umask 077

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$script_dir/lib.sh"

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
APP_UUID=${APP_UUID:-brrmsbi50m4q02lqx35juhlr}
APP_ID=${APP_ID:-4}
APP_HEALTH_URL=${APP_HEALTH_URL:-https://app.getconzent.com/health}
PMA_URL=${PMA_URL:-http://ozacg7jahe77grzlfv61p7w5.157.180.5.57.sslip.io}

require_root
require_commands docker curl python3 mktemp cmp sort awk grep systemctl flock
[[ $(hostname -s) == "$EXPECTED_HOST" ]] \
    || die "This script is pinned to $EXPECTED_HOST."
[[ $APP_UUID =~ ^[A-Za-z0-9_-]+$ ]] || die 'APP_UUID contains unsafe characters.'
[[ $APP_ID =~ ^[0-9]+$ ]] || die 'APP_ID must be numeric.'
case $APP_HEALTH_URL in
    http://*|https://*) ;;
    *) die 'APP_HEALTH_URL must use http:// or https://.' ;;
esac
case $APP_HEALTH_URL in
    *[[:space:]]*|*\"*|*\\*) die 'APP_HEALTH_URL contains unsafe characters.' ;;
esac
case $PMA_URL in
    http://*|https://*) ;;
    *) die 'PMA_URL must use http:// or https://.' ;;
esac
case $PMA_URL in
    *[[:space:]]*|*\"*|*\\*) die 'PMA_URL contains unsafe characters.' ;;
esac

exec 9>/run/lock/conzent-coolify-remediation.lock
flock -n 9 || die 'Another Conzent Coolify remediation workflow is running.'

systemctl is-active --quiet docker.service || die 'Docker is not active.'
docker info >/dev/null 2>&1 || die 'Docker is not responding.'

work_dir=$(mktemp -d /tmp/conzent-coolify-verify.XXXXXX)
chmod 0700 "$work_dir"
cleanup() {
    local cleanup_rc=$?
    trap - EXIT
    trap '' INT TERM
    case ${work_dir:-} in
        /tmp/conzent-coolify-verify.*) rm -rf -- "$work_dir" ;;
    esac
    exit "$cleanup_rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mapfile -t project_containers < <(
    docker ps -q --filter "label=com.docker.compose.project=$APP_UUID" | sort
)
((${#project_containers[@]} > 0)) || die 'No running containers have the expected Compose project UUID.'
mapfile -t application_containers < <(
    docker ps -q --filter "label=coolify.applicationId=$APP_ID" | sort
)
((${#application_containers[@]} > 0)) || die 'No running containers have the expected Coolify application ID.'

for container_id in "${project_containers[@]}"; do
    label_value=$(docker inspect --format '{{index .Config.Labels "coolify.applicationId"}}' "$container_id")
    [[ $label_value == "$APP_ID" ]] \
        || die 'A live project container has an unexpected Coolify application ID.'
done
for container_id in "${application_containers[@]}"; do
    label_value=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$container_id")
    [[ $label_value == "$APP_UUID" ]] \
        || die 'A live Coolify application container has an unexpected Compose project UUID.'
done

find_running_service() {
    local service=${1:?service required}
    local -a matches=()
    mapfile -t matches < <(
        docker ps -q \
            --filter "label=coolify.applicationId=$APP_ID" \
            --filter "label=com.docker.compose.project=$APP_UUID" \
            --filter "label=com.docker.compose.service=$service"
    )
    ((${#matches[@]} == 1)) \
        || die "Expected exactly one running $service container, found ${#matches[@]}."
    printf '%s\n' "${matches[0]}"
}

check_runtime_flag() {
    local container_id=${1:?container required}
    local key=${2:?key required}
    local expected=${3:?expected required}
    if ! docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$container_id" \
        | awk -v key="$key" -v expected="$key=$expected" '
            index($0, key "=") == 1 { count += 1; if ($0 == expected) good += 1 }
            END { exit !(count == 1 && good == 1) }
        '
    then
        die "A production PHP container has an unexpected $key value; value suppressed."
    fi
}

php_services=(app worker scheduler beacon-worker autoseo-render)
for service in "${php_services[@]}"; do
    container_id=$(find_running_service "$service")
    check_runtime_flag "$container_id" APP_ENV prod
    check_runtime_flag "$container_id" APP_DEBUG false
done
app_container=$(find_running_service app)

required_services=(nginx app worker scheduler beacon-worker scanner mariadb redis www autoseo-render elasticsearch chat-ai wix)
for service in "${required_services[@]}"; do
    container_id=$(find_running_service "$service")
    health_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$container_id")
    case $health_status in
        healthy|none) ;;
        *) die "$service has Docker health status $health_status." ;;
    esac
done

mapfile -t pma_containers < <(
    docker ps -aq \
        --filter "label=com.docker.compose.project=$APP_UUID" \
        --filter 'label=com.docker.compose.service=phpmyadmin'
)
((${#pma_containers[@]} == 0)) || die 'A phpMyAdmin container still exists in the production project.'

pma_body="$work_dir/phpmyadmin-route.body"
pma_config="$work_dir/phpmyadmin-route.curl.conf"
: >"$pma_body"
chmod 0600 "$pma_body"
{
    printf 'silent\nshow-error\nlocation\nmax-redirs = 5\nconnect-timeout = 10\nmax-time = 30\n'
    printf 'url = "%s"\n' "$PMA_URL"
    printf 'output = "%s"\n' "$pma_body"
    printf 'write-out = "%%{http_code}"\n'
    printf 'header = "Accept: text/html,application/xhtml+xml"\n'
} >"$pma_config"
chmod 0600 "$pma_config"
if ! pma_status=$(curl --config "$pma_config"); then
    die 'The retired phpMyAdmin route could not be checked; response suppressed.'
fi
[[ $pma_status =~ ^[0-9]{3}$ ]] \
    || die 'The retired phpMyAdmin route returned an invalid HTTP status.'
[[ $pma_status != 2* ]] || die "The retired phpMyAdmin route still returns HTTP $pma_status."
if grep -Eiq 'phpmyadmin|pma_(username|password)|input_(username|password)' "$pma_body"; then
    die 'The retired route still serves a phpMyAdmin login response; body suppressed.'
fi

expected_volumes="$work_dir/expected-volumes.txt"
actual_volumes="$work_dir/actual-volumes.txt"
for volume_key in app-public app-var db-data es-data redis-data wix-data www-blog; do
    printf '%s_%s\n' "$APP_UUID" "$volume_key"
done | sort >"$expected_volumes"
chmod 0600 "$expected_volumes"
docker volume ls --format '{{.Name}}' \
    | awk -v prefix="${APP_UUID}_" 'index($0, prefix) == 1 { print }' \
    | sort >"$actual_volumes"
chmod 0600 "$actual_volumes"
cmp -s "$expected_volumes" "$actual_volumes" \
    || die 'The project volume names do not exactly match the seven expected UUID-prefixed volumes.'

while IFS= read -r volume_name; do
    [[ -n $volume_name ]] || continue
    project_label=$(docker volume inspect --format '{{index .Labels "com.docker.compose.project"}}' "$volume_name")
    [[ $project_label == "$APP_UUID" ]] \
        || die "Volume $volume_name has an unexpected Compose project label."
done <"$expected_volumes"

check_mount() {
    local service=${1:?service required}
    local destination=${2:?destination required}
    local expected_volume=${3:?expected volume required}
    local container_id mounted_volume
    container_id=$(find_running_service "$service")
    mounted_volume=$(docker inspect \
        --format "{{range .Mounts}}{{if eq .Destination \"$destination\"}}{{println .Name}}{{end}}{{end}}" \
        "$container_id")
    [[ $mounted_volume == "$expected_volume" ]] \
        || die "$service does not mount the expected UUID-prefixed volume at $destination."
}

check_mount app /var/www/html/public "${APP_UUID}_app-public"
check_mount app /var/www/html/var "${APP_UUID}_app-var"
check_mount mariadb /var/lib/mysql "${APP_UUID}_db-data"
check_mount redis /data "${APP_UUID}_redis-data"
check_mount www /usr/share/nginx/html/blog "${APP_UUID}_www-blog"
check_mount elasticsearch /usr/share/elasticsearch/data "${APP_UUID}_es-data"
check_mount wix /app/data "${APP_UUID}_wix-data"

cli_health="$work_dir/cli-health.txt"
: >"$cli_health"
chmod 0600 "$cli_health"
if ! docker exec "$app_container" php bin/oci health >"$cli_health" 2>&1; then
    die 'The application CLI health command failed; output suppressed.'
fi
grep -Fxq 'Database: OK' "$cli_health" \
    && grep -Fxq 'Redis: OK' "$cli_health" \
    && grep -Fxq 'Environment: prod' "$cli_health" \
    && grep -Fxq 'Debug: false' "$cli_health" \
    || die 'The application CLI health result is not fully healthy and production-safe; output suppressed.'

http_health="$work_dir/http-health.json"
http_config="$work_dir/http-health.curl.conf"
: >"$http_health"
chmod 0600 "$http_health"
{
    printf 'silent\nshow-error\nconnect-timeout = 10\nmax-time = 30\n'
    printf 'url = "%s"\n' "$APP_HEALTH_URL"
    printf 'output = "%s"\n' "$http_health"
    printf 'write-out = "%%{http_code}"\n'
    printf 'header = "Accept: application/json"\n'
} >"$http_config"
chmod 0600 "$http_config"
if ! http_status=$(curl --config "$http_config"); then
    die 'The public application health request failed; response suppressed.'
fi
[[ $http_status == 200 ]] \
    || die "The public application health endpoint returned HTTP $http_status; response suppressed."
if ! python3 - "$http_health" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    raise SystemExit(1)
if not isinstance(payload, dict) or payload.get("success") is not True:
    raise SystemExit(1)
data = payload.get("data")
if not isinstance(data, dict):
    raise SystemExit(1)
services = data.get("services")
if not isinstance(services, dict):
    raise SystemExit(1)
if data.get("status") != "ok" or data.get("environment") != "prod":
    raise SystemExit(1)
if services.get("database") is not True or services.get("redis") is not True:
    raise SystemExit(1)
PY
then
    die 'The public application health payload is not fully healthy and production-safe; response suppressed.'
fi

log 'Verification passed: labels, production flags, phpMyAdmin removal, volumes, and application health are correct.'
