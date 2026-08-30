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
EXPECTED_REPOSITORY=${EXPECTED_REPOSITORY:-sitepointsystems/conzent-app}
EXPECTED_COMPOSE_FILE=${EXPECTED_COMPOSE_FILE:-/docker-compose.coolify.yaml}

require_root
require_commands curl python3 mktemp install flock
[[ $(hostname -s) == "$EXPECTED_HOST" ]] \
    || die "This script is pinned to $EXPECTED_HOST."
[[ $APP_UUID =~ ^[A-Za-z0-9_-]+$ ]] || die 'APP_UUID contains unsafe characters.'
case $COOLIFY_API_URL in
    http://*|https://*) ;;
    *) die 'COOLIFY_API_URL must use http:// or https://.' ;;
esac
case $COOLIFY_API_URL in
    *[[:space:]]*|*\"*|*\\*) die 'COOLIFY_API_URL contains unsafe characters.' ;;
esac

exec 9>/run/lock/conzent-coolify-remediation.lock
flock -n 9 || die 'Another Conzent Coolify remediation workflow is running.'

if [[ -z ${COOLIFY_ACCESS_TOKEN:-} ]]; then
    read -r -s -p 'Coolify API token: ' COOLIFY_ACCESS_TOKEN
    printf '\n' >&2
fi
[[ -n $COOLIFY_ACCESS_TOKEN ]] || die 'Coolify API token is empty.'
case $COOLIFY_ACCESS_TOKEN in
    *[[:space:]]*|*\"*|*\\*) die 'Coolify API token contains unsupported characters.' ;;
esac

work_dir=$(mktemp -d /tmp/conzent-coolify-stage.XXXXXX)
chmod 0700 "$work_dir"
cleanup() {
    local cleanup_rc=$?
    trap - EXIT
    trap '' INT TERM
    COOLIFY_ACCESS_TOKEN=''
    case ${work_dir:-} in
        /tmp/conzent-coolify-stage.*) rm -rf -- "$work_dir" ;;
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
        log "ERROR: Coolify API request failed for $method $path; response suppressed." >&2
        return 1
    fi
    if [[ ! $API_STATUS =~ ^[0-9]{3}$ ]]; then
        log 'ERROR: Coolify returned an invalid HTTP status.' >&2
        return 1
    fi
    rm -f -- "$request_config"
}

app_response="$work_dir/application.json"
api_request GET "/applications/$APP_UUID" "$app_response"
[[ $API_STATUS == 200 ]] \
    || die "Coolify application lookup failed with HTTP $API_STATUS; response suppressed."

if ! python3 - "$app_response" "$APP_UUID" "$EXPECTED_REPOSITORY" "$EXPECTED_COMPOSE_FILE" <<'PY'
import json
import sys
from urllib.parse import urlparse


def fail(message):
    print(message, file=sys.stderr)
    raise SystemExit(1)


try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    fail("Coolify application response is not valid JSON (body suppressed).")

if isinstance(payload, dict) and "uuid" not in payload and isinstance(payload.get("data"), dict):
    payload = payload["data"]
if not isinstance(payload, dict):
    fail("Coolify application response has an unexpected shape (body suppressed).")


def normalize_repository(value):
    value = str(value or "").strip().replace("\\", "/")
    if value.startswith("git@") and ":" in value:
        value = value.split(":", 1)[1]
    elif "://" in value:
        value = urlparse(value).path
    value = value.strip("/")
    if value.endswith(".git"):
        value = value[:-4]
    return value.lower()


def normalize_compose_path(value):
    value = str(value or "").strip().replace("\\", "/")
    return "/" + value.lstrip("./")


if str(payload.get("uuid", "")) != sys.argv[2]:
    fail("Coolify application UUID does not match APP_UUID.")
if str(payload.get("build_pack", "")).lower() != "dockercompose":
    fail("The Coolify resource is not a Docker Compose application.")
if normalize_repository(payload.get("git_repository")) != normalize_repository(sys.argv[3]):
    fail("The Coolify application points at an unexpected Git repository.")
if normalize_compose_path(payload.get("docker_compose_location")) != normalize_compose_path(sys.argv[4]):
    fail("The Coolify application points at an unexpected Compose file.")
PY
then
    die 'Coolify application identity validation failed.'
fi

env_response="$work_dir/environments.before.json"
rollback_candidate="$work_dir/rollback-flags.json"
api_request GET "/applications/$APP_UUID/envs" "$env_response"
[[ $API_STATUS == 200 ]] \
    || die "Coolify environment lookup failed with HTTP $API_STATUS; response suppressed."

: >"$rollback_candidate"
chmod 0600 "$rollback_candidate"
if ! python3 - "$env_response" "$rollback_candidate" <<'PY'
import json
import sys


def fail(message):
    print(message, file=sys.stderr)
    raise SystemExit(1)


def truthy(value):
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    fail("Coolify environment response is not valid JSON (body suppressed).")

if isinstance(payload, dict) and isinstance(payload.get("data"), list):
    payload = payload["data"]
if not isinstance(payload, list):
    fail("Coolify environment response has an unexpected shape (body suppressed).")

production = {}
for item in payload:
    if not isinstance(item, dict) or truthy(item.get("is_preview", False)):
        continue
    key = str(item.get("key", ""))
    if not key:
        continue
    production.setdefault(key, []).append(item)

checked_keys = {
    "APP_SECRET",
    "DATABASE_URL",
    "DB_PASSWORD",
    "DB_ROOT_PASSWORD",
    "SCANNER_API_KEY",
    "APP_ENV",
    "APP_DEBUG",
}
duplicates = sorted(key for key in checked_keys if len(production.get(key, [])) > 1)
if duplicates:
    fail("Duplicate non-preview Coolify variables must be resolved first: " + ", ".join(duplicates))


def configured(item):
    for field in ("value", "real_value"):
        value = item.get(field)
        if value is not None and str(value).strip():
            return True
    return False


required = ["APP_SECRET", "DATABASE_URL", "DB_PASSWORD", "DB_ROOT_PASSWORD", "SCANNER_API_KEY"]
missing = [key for key in required if not production.get(key) or not configured(production[key][0])]
if missing:
    fail("Missing or empty required Coolify variables (values suppressed): " + ", ".join(missing))


def flag_state(key, allowed):
    if not production.get(key):
        return {"present": False, "value": None}
    item = production[key][0]
    value = item.get("value")
    if value is None or str(value) == "":
        value = item.get("real_value")
    value = str(value)
    if value.strip().lower() not in allowed:
        fail(f"Existing {key} is not a recognized non-secret flag value; value suppressed.")
    return {"present": True, "value": value}


state = {
    "APP_ENV": flag_state("APP_ENV", {"dev", "development", "prod", "production", "test"}),
    "APP_DEBUG": flag_state("APP_DEBUG", {"0", "1", "false", "true", "no", "yes", "off", "on"}),
}
with open(sys.argv[2], "w", encoding="utf-8") as handle:
    json.dump(state, handle, sort_keys=True, separators=(",", ":"))
    handle.write("\n")
PY
then
    die 'Coolify environment safety validation failed; no variables were changed.'
fi

state_dir=$(make_state_dir coolify-env)
install -m 0600 "$rollback_candidate" "$state_dir/rollback-flags.json"

flag_was_present() {
    local key=${1:?key required}
    python3 - "$rollback_candidate" "$key" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
print("yes" if state[sys.argv[2]]["present"] else "no")
PY
}

stage_flag() {
    local key=${1:?key required}
    local value=${2:?value required}
    local present method body response

    present=$(flag_was_present "$key")
    [[ $present == yes || $present == no ]] || die "Could not determine whether $key exists."
    if [[ $present == yes ]]; then method=PATCH; else method=POST; fi

    body="$work_dir/$key.body.json"
    case "$key=$value" in
        APP_ENV=prod)
            printf '%s\n' '{"key":"APP_ENV","value":"prod","is_literal":true,"is_preview":false,"is_multiline":false}' >"$body"
            ;;
        APP_DEBUG=false)
            printf '%s\n' '{"key":"APP_DEBUG","value":"false","is_literal":true,"is_preview":false,"is_multiline":false}' >"$body"
            ;;
        *) die 'Internal error: unsupported production flag.' ;;
    esac
    chmod 0600 "$body"
    response="$work_dir/$key.response.json"
    api_request "$method" "/applications/$APP_UUID/envs" "$response" "$body"
    [[ $API_STATUS == 200 || $API_STATUS == 201 ]] \
        || die "Coolify rejected $key with HTTP $API_STATUS; response suppressed. Rollback flags are in $state_dir."
}

stage_flag APP_ENV prod
stage_flag APP_DEBUG false

env_after="$work_dir/environments.after.json"
api_request GET "/applications/$APP_UUID/envs" "$env_after"
[[ $API_STATUS == 200 ]] \
    || die "Post-stage environment lookup failed with HTTP $API_STATUS; response suppressed. Rollback flags are in $state_dir."

if ! python3 - "$env_after" <<'PY'
import json
import sys


def truthy(value):
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


try:
    with open(sys.argv[1], encoding="utf-8") as handle:
        payload = json.load(handle)
except Exception:
    raise SystemExit("Post-stage response is not valid JSON (body suppressed).")
if isinstance(payload, dict) and isinstance(payload.get("data"), list):
    payload = payload["data"]
if not isinstance(payload, list):
    raise SystemExit("Post-stage response has an unexpected shape (body suppressed).")

values = {}
for item in payload:
    if not isinstance(item, dict) or truthy(item.get("is_preview", False)):
        continue
    key = str(item.get("key", ""))
    if key in {"APP_ENV", "APP_DEBUG"}:
        value = item.get("value")
        if value is None or str(value) == "":
            value = item.get("real_value")
        values.setdefault(key, []).append(str(value))
if values.get("APP_ENV") != ["prod"] or values.get("APP_DEBUG") != ["false"]:
    raise SystemExit("Coolify did not persist the requested production flags; values suppressed.")
PY
then
    die "Coolify flag verification failed. Rollback flags are in $state_dir."
fi

log 'Coolify now stores APP_ENV=prod and APP_DEBUG=false for the production application.'
log "Non-secret rollback flags: $state_dir/rollback-flags.json"
log 'Nothing was deployed. Run 41-coolify-deploy.sh next.'
