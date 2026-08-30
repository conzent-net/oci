#!/usr/bin/env bash

set -Eeuo pipefail
set +x
export LC_ALL=C
umask 077
if [[ ${COOLIFY_ACCESS_TOKEN+x} ]]; then
    export -n COOLIFY_ACCESS_TOKEN
fi

script_dir=$(CDPATH= cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
# shellcheck source=lib.sh
source "$script_dir/lib.sh"

EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}
COOLIFY_API_URL=${COOLIFY_API_URL:-http://127.0.0.1:8000/api/v1}
APP_UUID=${APP_UUID:-brrmsbi50m4q02lqx35juhlr}
POLL_INTERVAL_SECONDS=${POLL_INTERVAL_SECONDS:-5}
DEPLOY_TIMEOUT_SECONDS=${DEPLOY_TIMEOUT_SECONDS:-1800}

require_root
require_commands curl python3 mktemp date sleep flock
[[ $(hostname -s) == "$EXPECTED_HOST" ]] \
    || die "This script is pinned to $EXPECTED_HOST."
[[ $APP_UUID =~ ^[A-Za-z0-9_-]+$ ]] || die 'APP_UUID contains unsafe characters.'
[[ $POLL_INTERVAL_SECONDS =~ ^[0-9]+$ ]] \
    && ((POLL_INTERVAL_SECONDS >= 1 && POLL_INTERVAL_SECONDS <= 60)) \
    || die 'POLL_INTERVAL_SECONDS must be between 1 and 60.'
[[ $DEPLOY_TIMEOUT_SECONDS =~ ^[0-9]+$ ]] \
    && ((DEPLOY_TIMEOUT_SECONDS >= 60 && DEPLOY_TIMEOUT_SECONDS <= 7200)) \
    || die 'DEPLOY_TIMEOUT_SECONDS must be between 60 and 7200.'
case $COOLIFY_API_URL in
    http://*|https://*) ;;
    *) die 'COOLIFY_API_URL must use http:// or https://.' ;;
esac
case $COOLIFY_API_URL in
    *[[:space:]]*|*\"*|*\\*) die 'COOLIFY_API_URL contains unsafe characters.' ;;
esac

lock_file=/run/lock/conzent-coolify-remediation.lock
if [[ -n ${CONZENT_COOLIFY_LOCK_FD:-} ]]; then
    [[ $CONZENT_COOLIFY_LOCK_FD =~ ^[0-9]+$ ]] \
        || die 'CONZENT_COOLIFY_LOCK_FD must be a numeric file descriptor.'
    [[ -e /proc/$$/fd/$CONZENT_COOLIFY_LOCK_FD ]] \
        || die 'The inherited remediation lock file descriptor is not open.'
    [[ /proc/$$/fd/$CONZENT_COOLIFY_LOCK_FD -ef $lock_file ]] \
        || die 'The inherited remediation lock points to the wrong file.'
    flock -n "$CONZENT_COOLIFY_LOCK_FD" \
        || die 'Another Conzent Coolify remediation workflow is running.'
else
    exec 9>"$lock_file"
    flock -n 9 || die 'Another Conzent Coolify remediation workflow is running.'
fi

if [[ -z ${COOLIFY_ACCESS_TOKEN:-} ]]; then
    read -r -s -p 'Coolify API token: ' COOLIFY_ACCESS_TOKEN
    printf '\n' >&2
fi
[[ -n $COOLIFY_ACCESS_TOKEN ]] || die 'Coolify API token is empty.'
case $COOLIFY_ACCESS_TOKEN in
    *[[:space:]]*|*\"*|*\\*) die 'Coolify API token contains unsupported characters.' ;;
esac

work_dir=$(mktemp -d /tmp/conzent-coolify-deploy.XXXXXX)
chmod 0700 "$work_dir"
cleanup() {
    local cleanup_rc=$?
    trap - EXIT
    trap '' INT TERM
    COOLIFY_ACCESS_TOKEN=''
    case ${work_dir:-} in
        /tmp/conzent-coolify-deploy.*) rm -rf -- "$work_dir" ;;
    esac
    exit "$cleanup_rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

request_number=0
API_STATUS=
api_request() {
    local method=${1:?method required}
    local path=${2:?path required}
    local output=${3:?output required}
    local body=${4:-}
    local request_config request_url

    ((request_number += 1))
    request_config="$work_dir/request-$request_number.conf"
    request_url="${COOLIFY_API_URL%/}$path"
    : >"$output"
    chmod 0600 "$output"
    {
        printf 'silent\nshow-error\nconnect-timeout = 10\nmax-time = 60\n'
        printf 'request = "%s"\n' "$method"
        printf 'url = "%s"\n' "$request_url"
        printf 'output = "%s"\n' "$output"
        printf 'write-out = "%%{http_code}"\n'
        printf 'header = "Accept: application/json"\n'
        printf 'header = "Authorization: Bearer %s"\n' "$COOLIFY_ACCESS_TOKEN"
        if [[ -n $body ]]; then
            [[ -f $body ]] || die 'Internal error: API body file is missing.'
            printf 'header = "Content-Type: application/json"\n'
            printf 'data-binary = "@%s"\n' "$body"
        fi
    } >"$request_config"
    chmod 0600 "$request_config"

    if ! API_STATUS=$(curl --config "$request_config"); then
        die "Coolify API request failed for $method $path; response suppressed."
    fi
    [[ $API_STATUS =~ ^[0-9]{3}$ ]] || die 'Coolify returned an invalid HTTP status.'
    rm -f -- "$request_config"
}

deploy_body="$work_dir/deploy.body.json"
printf '{"uuid":"%s","force":true}\n' "$APP_UUID" >"$deploy_body"
chmod 0600 "$deploy_body"
deploy_response="$work_dir/deploy.response.json"
api_request POST '/deploy' "$deploy_response" "$deploy_body"
[[ $API_STATUS == 200 || $API_STATUS == 201 || $API_STATUS == 202 ]] \
    || die "Coolify rejected the forced deployment with HTTP $API_STATUS; response suppressed."

deployment_uuid=$(python3 - "$deploy_response" "$APP_UUID" <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    raise SystemExit(1)
if isinstance(payload, dict) and isinstance(payload.get("data"), dict):
    payload = payload["data"]
deployments = payload.get("deployments", []) if isinstance(payload, dict) else []
if not isinstance(deployments, list):
    raise SystemExit(1)
matches = [
    item for item in deployments
    if isinstance(item, dict)
    and str(item.get("resource_uuid", "")) == sys.argv[2]
    and re.fullmatch(r"[A-Za-z0-9_-]+", str(item.get("deployment_uuid", "")))
]
if len(matches) != 1:
    raise SystemExit(1)
print(matches[0]["deployment_uuid"])
PY
) || die 'Coolify did not return one valid deployment UUID; response suppressed.'
[[ $deployment_uuid =~ ^[A-Za-z0-9_-]+$ ]] \
    || die 'Coolify returned an unsafe deployment UUID.'

log "Forced deployment queued: $deployment_uuid"
start_epoch=$(date +%s)
last_status=
while :; do
    now_epoch=$(date +%s)
    ((now_epoch - start_epoch < DEPLOY_TIMEOUT_SECONDS)) \
        || die "Deployment $deployment_uuid did not finish within ${DEPLOY_TIMEOUT_SECONDS}s."

    status_response="$work_dir/deployment-status.json"
    api_request GET "/deployments/$deployment_uuid" "$status_response"
    if [[ $API_STATUS == 404 ]]; then
        [[ $last_status == awaiting-record ]] || log 'Waiting for Coolify to create the deployment record.'
        last_status=awaiting-record
        sleep "$POLL_INTERVAL_SECONDS"
        continue
    fi
    [[ $API_STATUS == 200 ]] \
        || die "Deployment status lookup failed with HTTP $API_STATUS; response suppressed."

    deployment_status=$(python3 - "$status_response" <<'PY'
import json
import re
import sys

try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    raise SystemExit(1)
if isinstance(payload, dict) and "status" not in payload and isinstance(payload.get("data"), dict):
    payload = payload["data"]
status = str(payload.get("status", "")) if isinstance(payload, dict) else ""
if not re.fullmatch(r"[A-Za-z0-9._-]+", status):
    raise SystemExit(1)
print(status.lower())
PY
) || die 'Coolify returned an invalid deployment status; response suppressed.'

    if [[ $deployment_status != "$last_status" ]]; then
        log "Deployment status: $deployment_status"
        last_status=$deployment_status
    fi
    case $deployment_status in
        finished|success|successful)
            log "Deployment $deployment_uuid completed successfully."
            log 'Run 42-verify.sh now.'
            exit 0
            ;;
        failed|failure|error|cancelled|canceled|cancelled-by-user|canceled-by-user)
            die "Deployment $deployment_uuid ended with status $deployment_status; logs were not printed."
            ;;
    esac
    sleep "$POLL_INTERVAL_SECONDS"
done
