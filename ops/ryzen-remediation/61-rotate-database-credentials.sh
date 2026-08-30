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
APP_ID=${APP_ID:-4}
EXPECTED_REPOSITORY=${EXPECTED_REPOSITORY:-sitepointsystems/conzent-app}
EXPECTED_COMPOSE_FILE=${EXPECTED_COMPOSE_FILE:-/docker-compose.coolify.yaml}
APP_HEALTH_URL=${APP_HEALTH_URL:-https://app.getconzent.com/health}
recovery_root=/var/backups/conzent-remediation/database-rotation
lock_file=/run/lock/conzent-coolify-remediation.lock

require_root
require_commands docker curl python3 mktemp install flock date sort grep awk systemctl bash sync
[[ $(hostname -s) == "$EXPECTED_HOST" ]] \
    || die "This script is pinned to $EXPECTED_HOST."
[[ $APP_UUID =~ ^[A-Za-z0-9_-]+$ ]] || die 'APP_UUID contains unsafe characters.'
[[ $APP_ID =~ ^[0-9]+$ ]] || die 'APP_ID must be numeric.'
case $COOLIFY_API_URL in
    http://*|https://*) ;;
    *) die 'COOLIFY_API_URL must use http:// or https://.' ;;
esac
case $COOLIFY_API_URL in
    *[[:space:]]*|*\"*|*\\*) die 'COOLIFY_API_URL contains unsafe characters.' ;;
esac
case $APP_HEALTH_URL in
    http://*|https://*) ;;
    *) die 'APP_HEALTH_URL must use http:// or https://.' ;;
esac
case $APP_HEALTH_URL in
    *[[:space:]]*|*\"*|*\\*) die 'APP_HEALTH_URL contains unsafe characters.' ;;
esac

exec 9>"$lock_file"
flock -n 9 || die 'Another Conzent Coolify remediation workflow is running.'
exec 8>/run/lock/conzent-full-backup.lock
flock -n 8 || die 'A Conzent full backup is running; wait for it to finish before rotating database credentials.'

shopt -s nullglob
prior_recoveries=("$recovery_root"/*/recovery.json)
shopt -u nullglob
((${#prior_recoveries[@]} == 0)) \
    || die "An unresolved database rotation already exists under $recovery_root; recover or securely retire it before starting another."

systemctl is-active --quiet docker.service || die 'Docker is not active.'
docker info >/dev/null 2>&1 || die 'Docker is not responding.'

if [[ -z ${COOLIFY_ACCESS_TOKEN:-} ]]; then
    read -r -s -p 'Coolify API token: ' COOLIFY_ACCESS_TOKEN
    printf '\n' >&2
fi
[[ -n $COOLIFY_ACCESS_TOKEN ]] || die 'Coolify API token is empty.'
case $COOLIFY_ACCESS_TOKEN in
    *[[:space:]]*|*\"*|*\\*) die 'Coolify API token contains unsupported characters.' ;;
esac

work_dir=$(mktemp -d /tmp/conzent-db-rotation.XXXXXX)
chmod 0700 "$work_dir"
state_dir=
recovery_file=
phase=preflight
db_container=
declare -a db_containers_seen=()

remember_db_container() {
    local candidate=${1:?container required}
    local known
    for known in "${db_containers_seen[@]}"; do
        [[ $known == "$candidate" ]] && return 0
    done
    db_containers_seen+=("$candidate")
}

cleanup() {
    local cleanup_rc=$?
    local container_id
    trap - EXIT
    trap '' INT TERM
    set +e
    COOLIFY_ACCESS_TOKEN=''
    for container_id in "${db_containers_seen[@]}"; do
        docker exec "$container_id" sh -c \
            'rm -f -- /run/conzent-rotation-old-root.cnf /run/conzent-rotation-new-root.cnf /run/conzent-rotation-old-app.cnf /run/conzent-rotation-new-app.cnf' \
            >/dev/null 2>&1
    done
    case ${work_dir:-} in
        /tmp/conzent-db-rotation.*) rm -rf -- "$work_dir" ;;
    esac
    if ((cleanup_rc != 0)) && [[ -n ${recovery_file:-} && -f $recovery_file ]]; then
        log "Database rotation stopped in phase '$phase'. Protected recovery state was retained at $recovery_file." >&2
    fi
    exit "$cleanup_rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

set_phase() {
    phase=${1:?phase required}
    if [[ -n ${state_dir:-} ]]; then
        printf '%s\n' "$phase" >"$state_dir/phase"
        chmod 0600 "$state_dir/phase"
    fi
    log "Database rotation phase: $phase"
}

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
            [[ -f $body ]] || return 1
            printf 'header = "Content-Type: application/json"\n'
            printf 'data-binary = "@%s"\n' "$body"
        fi
    } >"$request_config"
    chmod 0600 "$request_config"

    if ! API_STATUS=$(curl --config "$request_config"); then
        API_STATUS=transport-error
        rm -f -- "$request_config"
        return 1
    fi
    rm -f -- "$request_config"
    [[ $API_STATUS =~ ^[0-9]{3}$ ]]
}

app_response="$work_dir/application.json"
api_request GET "/applications/$APP_UUID" "$app_response" \
    || die 'Coolify application lookup failed; response suppressed.'
[[ $API_STATUS == 200 ]] \
    || die "Coolify application lookup failed with HTTP $API_STATUS; response suppressed."

if ! python3 - "$app_response" "$APP_UUID" "$APP_ID" "$EXPECTED_REPOSITORY" "$EXPECTED_COMPOSE_FILE" <<'PY'
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
    fail("Coolify application response is invalid (body suppressed).")
if isinstance(payload, dict) and "uuid" not in payload and isinstance(payload.get("data"), dict):
    payload = payload["data"]
if not isinstance(payload, dict):
    fail("Coolify application response has an unexpected shape (body suppressed).")

def repository(value):
    value = str(value or "").strip().replace("\\", "/")
    if value.startswith("git@") and ":" in value:
        value = value.split(":", 1)[1]
    elif "://" in value:
        value = urlparse(value).path
    value = value.strip("/")
    return (value[:-4] if value.endswith(".git") else value).lower()

def compose_path(value):
    return "/" + str(value or "").strip().replace("\\", "/").lstrip("./")

checks = [
    str(payload.get("uuid", "")) == sys.argv[2],
    str(payload.get("id", "")) == sys.argv[3],
    str(payload.get("build_pack", "")).lower() == "dockercompose",
    repository(payload.get("git_repository")) == repository(sys.argv[4]),
    compose_path(payload.get("docker_compose_location")) == compose_path(sys.argv[5]),
]
if not all(checks):
    fail("Coolify application identity does not match the pinned production resource.")
PY
then
    die 'Coolify application identity validation failed.'
fi

db_container=$(find_running_service mariadb)
remember_db_container "$db_container"
app_container=$(find_running_service app)

env_before="$work_dir/environments.before.json"
api_request GET "/applications/$APP_UUID/envs" "$env_before" \
    || die 'Coolify environment lookup failed; response suppressed.'
[[ $API_STATUS == 200 ]] \
    || die "Coolify environment lookup failed with HTTP $API_STATUS; response suppressed."

state_dir=$(make_state_dir database-rotation)
recovery_file="$state_dir/recovery.json"
if ! python3 - "$env_before" "$recovery_file" "$APP_UUID" <<'PY'
import json
import re
import secrets
import sys
from urllib.parse import quote, unquote, urlsplit, urlunsplit

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
    fail("Coolify environment response is invalid (body suppressed).")
if isinstance(payload, dict) and isinstance(payload.get("data"), list):
    payload = payload["data"]
if not isinstance(payload, list):
    fail("Coolify environment response has an unexpected shape (body suppressed).")

production = {}
for item in payload:
    if not isinstance(item, dict) or truthy(item.get("is_preview", False)):
        continue
    key = str(item.get("key", ""))
    if key:
        production.setdefault(key, []).append(item)

required = ["DB_USER", "DB_PASSWORD", "DATABASE_URL", "DB_ROOT_PASSWORD"]
duplicates = [key for key in required if len(production.get(key, [])) > 1]
missing = [key for key in required if len(production.get(key, [])) != 1]
if duplicates:
    fail("Duplicate production database variables must be resolved first: " + ", ".join(duplicates))
if missing:
    fail("Missing production database variables must be configured first: " + ", ".join(missing))

def value(key):
    item = production[key][0]
    for field in ("real_value", "value"):
        candidate = item.get(field)
        if candidate is None:
            continue
        candidate = str(candidate)
        if candidate and not re.fullmatch(r"\*+", candidate):
            return candidate
    fail(f"{key} is empty or masked; values suppressed.")

old_user = value("DB_USER")
old_password = value("DB_PASSWORD")
old_url = value("DATABASE_URL")
old_root = value("DB_ROOT_PASSWORD")
if not re.fullmatch(r"[A-Za-z0-9_]{1,32}", old_user) or old_user.lower() in {
    "root", "mysql", "mariadb", "mysql.sys", "mysql.session"
}:
    fail("DB_USER is not a safe rotatable application account name; value suppressed.")
for label, secret in (("DB_PASSWORD", old_password), ("DB_ROOT_PASSWORD", old_root)):
    if not secret or any(ord(char) < 32 or ord(char) == 127 for char in secret):
        fail(f"{label} is empty or contains unsupported control characters; value suppressed.")

try:
    parsed = urlsplit(old_url)
    port = parsed.port
except Exception:
    fail("DATABASE_URL is invalid; value suppressed.")
if parsed.scheme.lower() not in {"mysql", "mariadb"}:
    fail("DATABASE_URL must use the mysql or mariadb scheme.")
dsn_user = unquote(parsed.username or "")
dsn_password = unquote(parsed.password or "")
if dsn_user != old_user or dsn_password != old_password:
    fail("DATABASE_URL credentials do not match DB_USER and DB_PASSWORD; values suppressed.")
if (parsed.hostname or "").lower() != "mariadb" or port not in (None, 3306):
    fail("DATABASE_URL must target mariadb on port 3306.")
db_name = unquote(parsed.path.lstrip("/"))
if not re.fullmatch(r"[A-Za-z0-9_]{1,64}", db_name):
    fail("DATABASE_URL contains an unsafe database name; value suppressed.")
if parsed.fragment:
    fail("DATABASE_URL fragments are unsupported.")

new_user = "czr_" + secrets.token_hex(8)
new_password = secrets.token_hex(32)
new_root = secrets.token_hex(32)
explicit_port = f":{port}" if port is not None else ""
new_netloc = f"{quote(new_user, safe='')}:{quote(new_password, safe='')}@mariadb{explicit_port}"
new_url = urlunsplit((parsed.scheme, new_netloc, parsed.path, parsed.query, ""))

state = {
    "version": 1,
    "application_uuid": sys.argv[3],
    "database": db_name,
    "old": {
        "user": old_user,
        "password": old_password,
        "database_url": old_url,
        "root_password": old_root,
    },
    "new": {
        "user": new_user,
        "password": new_password,
        "database_url": new_url,
        "root_password": new_root,
    },
}
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    json.dump(state, handle, sort_keys=True, separators=(",", ":"))
    handle.write("\n")
PY
then
    die 'Database environment validation failed; no database account was changed.'
fi
chmod 0600 "$recovery_file"
sync -f "$recovery_file"
set_phase recovery-recorded

verify_old_runtime_env() {
    local container=${1:?container required}
    local kind=${2:?app or database required}
    local inspect_file="$work_dir/preflight-$kind.inspect.json"
    : >"$inspect_file"
    chmod 0600 "$inspect_file"
    docker inspect "$container" >"$inspect_file" || return 1
    python3 - "$inspect_file" "$recovery_file" "$kind" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    inspected = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    state = json.load(handle)
if not isinstance(inspected, list) or len(inspected) != 1:
    raise SystemExit(1)
environment = inspected[0].get("Config", {}).get("Env", [])
values = {}
for entry in environment:
    key, separator, value = str(entry).partition("=")
    if separator:
        values.setdefault(key, []).append(value)
old = state["old"]
if sys.argv[3] == "app":
    expected = {
        "DB_HOST": "mariadb",
        "DB_PORT": "3306",
        "DB_NAME": state["database"],
        "DB_USER": old["user"],
        "DB_PASSWORD": old["password"],
        "DATABASE_URL": old["database_url"],
    }
elif sys.argv[3] == "database":
    expected = {
        "MARIADB_DATABASE": state["database"],
        "MARIADB_USER": old["user"],
        "MARIADB_PASSWORD": old["password"],
        "MARIADB_ROOT_PASSWORD": old["root_password"],
    }
else:
    raise SystemExit(1)
if any(values.get(key) != [value] for key, value in expected.items()):
    raise SystemExit(1)
PY
}

verify_old_runtime_env "$app_container" app \
    || die 'The running app environment does not exactly match the current Coolify database variables; values suppressed.'
verify_old_runtime_env "$db_container" database \
    || die 'The running MariaDB environment does not exactly match the current Coolify database variables; values suppressed.'

make_client_config() {
    local generation=${1:?old or new required}
    local account=${2:?root or app required}
    local output=${3:?output required}
    python3 - "$recovery_file" "$generation" "$account" "$output" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
generation, account = sys.argv[2], sys.argv[3]
if generation not in {"old", "new"} or account not in {"root", "app"}:
    raise SystemExit(1)
values = state[generation]
user = "root" if account == "root" else values["user"]
password = values["root_password"] if account == "root" else values["password"]

def option(value):
    value = str(value)
    if any(ord(char) < 32 or ord(char) == 127 for char in value):
        raise SystemExit(1)
    return '"' + value.replace("\\", "\\\\").replace('"', '\\"') + '"'

lines = [
    "[client]",
    "protocol=tcp",
    "host=127.0.0.1",
    "port=3306",
    "user=" + option(user),
    "password=" + option(password),
]
if account == "app":
    lines.append("database=" + option(state["database"]))
with open(sys.argv[4], "x", encoding="utf-8") as handle:
    handle.write("\n".join(lines) + "\n")
PY
    chmod 0600 "$output"
}

old_root_local="$work_dir/old-root.cnf"
new_root_local="$work_dir/new-root.cnf"
old_app_local="$work_dir/old-app.cnf"
new_app_local="$work_dir/new-app.cnf"
make_client_config old root "$old_root_local"
make_client_config new root "$new_root_local"
make_client_config old app "$old_app_local"
make_client_config new app "$new_app_local"

old_root_remote=/run/conzent-rotation-old-root.cnf
new_root_remote=/run/conzent-rotation-new-root.cnf
old_app_remote=/run/conzent-rotation-old-app.cnf
new_app_remote=/run/conzent-rotation-new-app.cnf

install_db_config() {
    local local_file=${1:?local config required}
    local remote_file=${2:?remote config required}
    docker exec -i "$db_container" sh -c \
        'umask 077; cat >"$1" && chmod 0600 "$1"' sh "$remote_file" <"$local_file" \
        >/dev/null 2>"$work_dir/config-install.err"
}

remove_db_configs() {
    docker exec "$db_container" sh -c \
        'rm -f -- /run/conzent-rotation-old-root.cnf /run/conzent-rotation-new-root.cnf /run/conzent-rotation-old-app.cnf /run/conzent-rotation-new-app.cnf' \
        >/dev/null 2>"$work_dir/config-remove.err"
}

install_all_db_configs() {
    install_db_config "$old_root_local" "$old_root_remote" || return 1
    install_db_config "$new_root_local" "$new_root_remote" || return 1
    install_db_config "$old_app_local" "$old_app_remote" || return 1
    install_db_config "$new_app_local" "$new_app_remote" || return 1
}

db_client=$(docker exec "$db_container" sh -c \
    'if command -v mariadb >/dev/null 2>&1; then command -v mariadb; elif command -v mysql >/dev/null 2>&1; then command -v mysql; else exit 1; fi') \
    || die 'No MariaDB client exists in the database container.'
[[ $db_client == /* && $db_client != *[[:space:]]* ]] \
    || die 'The database container returned an unsafe client path.'
install_all_db_configs || die 'Could not install protected client option files in the database container.'

sql_number=0
run_db_sql() {
    local remote_config=${1:?remote config required}
    local sql_file=${2:?SQL file required}
    local output_file error_file
    ((sql_number += 1))
    output_file="$work_dir/sql-$sql_number.out"
    error_file="$work_dir/sql-$sql_number.err"
    : >"$output_file"
    : >"$error_file"
    chmod 0600 "$output_file" "$error_file"
    if ! docker exec -i "$db_container" "$db_client" \
        "--defaults-extra-file=$remote_config" --batch --skip-column-names \
        <"$sql_file" >"$output_file" 2>"$error_file"
    then
        DB_SQL_OUTPUT=$output_file
        return 1
    fi
    DB_SQL_OUTPUT=$output_file
}

probe_sql="$work_dir/probe.sql"
printf '%s\n' 'SELECT 1;' >"$probe_sql"
chmod 0600 "$probe_sql"
run_db_sql "$old_root_remote" "$probe_sql" \
    || die 'The existing DB_ROOT_PASSWORD cannot authenticate root over TCP; output suppressed.'
grep -Fxq '1' "$DB_SQL_OUTPUT" || die 'The root database probe returned an unexpected result.'
run_db_sql "$old_app_remote" "$probe_sql" \
    || die 'The existing application database account cannot authenticate; output suppressed.'
grep -Fxq '1' "$DB_SQL_OUTPUT" || die 'The application database probe returned an unexpected result.'

accounts_query="$work_dir/accounts-query.sql"
python3 - "$recovery_file" "$accounts_query" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
users = ["root", state["old"]["user"], state["new"]["user"]]
if not all(value.replace("_", "a").isalnum() for value in users):
    raise SystemExit(1)
literals = ",".join("'" + value + "'" for value in users)
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    handle.write(f"SELECT User,Host,IFNULL(plugin,'') FROM mysql.user WHERE User IN ({literals}) ORDER BY User,Host;\n")
PY
chmod 0600 "$accounts_query"
run_db_sql "$old_root_remote" "$accounts_query" \
    || die 'Could not inspect MariaDB accounts; output suppressed.'
accounts_file="$state_dir/accounts.json"
if ! python3 - "$recovery_file" "$DB_SQL_OUTPUT" "$accounts_file" <<'PY'
import json
import re
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
rows = []
with open(sys.argv[2], encoding="utf-8") as handle:
    for raw in handle:
        fields = raw.rstrip("\n").split("\t")
        if len(fields) != 3:
            raise SystemExit("MariaDB account metadata has an unexpected shape; values suppressed.")
        user, host, plugin = fields
        if not re.fullmatch(r"[A-Za-z0-9_]{1,32}", user):
            raise SystemExit("MariaDB returned an unsafe account name; value suppressed.")
        if not re.fullmatch(r"[A-Za-z0-9_.:%-]{1,255}", host):
            raise SystemExit("MariaDB returned an unsafe account host; value suppressed.")
        rows.append({"user": user, "host": host, "plugin": plugin})
old_user, new_user = state["old"]["user"], state["new"]["user"]
roots = [row for row in rows if row["user"] == "root"]
old = [row for row in rows if row["user"] == old_user]
new = [row for row in rows if row["user"] == new_user]
if not roots or not old or new:
    raise SystemExit("Expected root/old accounts or candidate-account absence was not observed; values suppressed.")
if len(old) != 1 or old[0]["host"] != "%":
    raise SystemExit("The application account is not the expected single '%' account; no account was changed.")
unsupported = [row for row in roots if row["plugin"] != "mysql_native_password"]
if unsupported:
    raise SystemExit("A root account uses an unsupported authentication plugin; no account was changed.")
with open(sys.argv[3], "x", encoding="utf-8") as handle:
    json.dump({"root": roots, "old": old}, handle, sort_keys=True, separators=(",", ":"))
    handle.write("\n")
PY
then
    die 'MariaDB account safety validation failed.'
fi
chmod 0600 "$accounts_file"

root_auth_sql="$work_dir/root-auth.sql"
python3 - "$accounts_file" "$root_auth_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    accounts = json.load(handle)
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    for row in accounts["root"]:
        handle.write(f"SHOW CREATE USER 'root'@'{row['host']}';\n")
PY
chmod 0600 "$root_auth_sql"
run_db_sql "$old_root_remote" "$root_auth_sql" \
    || die 'Could not inspect MariaDB root authentication rules; output suppressed.'
if grep -Eiq '(^|[[:space:]])OR([[:space:]]|$)' "$DB_SQL_OUTPUT"; then
    die 'A root account has alternate authentication rules that this rotation will not overwrite.'
fi

grants_sql="$work_dir/old-user-grants.sql"
python3 - "$recovery_file" "$accounts_file" "$grants_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    accounts = json.load(handle)
user = state["old"]["user"]
with open(sys.argv[3], "x", encoding="utf-8") as handle:
    for row in accounts["old"]:
        handle.write(f"SHOW GRANTS FOR '{user}'@'{row['host']}';\n")
PY
chmod 0600 "$grants_sql"
run_db_sql "$old_root_remote" "$grants_sql" \
    || die 'Could not inspect grants for the existing app account; output suppressed.'
if ! python3 - "$recovery_file" "$DB_SQL_OUTPUT" <<'PY'
import json
import re
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
database = re.escape(state["database"])
allowed_db = re.compile(rf"^GRANT ALL PRIVILEGES ON `{database}`\.\* TO ", re.I)
with open(sys.argv[2], encoding="utf-8") as handle:
    grants = [line.rstrip("\n") for line in handle if line.strip()]
if not grants:
    raise SystemExit("No grants were returned for the existing app account.")
database_grants = 0
for grant in grants:
    if re.match(r"^GRANT USAGE ON \*\.\* TO ", grant, re.I):
        continue
    if allowed_db.match(grant):
        database_grants += 1
        continue
    raise SystemExit("The existing app account has privileges outside its application database; account was not changed.")
if database_grants == 0:
    raise SystemExit("The existing app account does not have the expected ALL PRIVILEGES database grant.")
PY
then
    die 'Existing application-account grants are not safe to retire.'
fi
old_grants_file="$state_dir/old-user-grants.txt"
install -m 0600 "$DB_SQL_OUTPUT" "$old_grants_file"
sync -f "$old_grants_file"

create_user_sql="$work_dir/create-candidate.sql"
python3 - "$recovery_file" "$create_user_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
user = state["new"]["user"]
password = state["new"]["password"]
database = state["database"]
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    handle.write(f"CREATE USER '{user}'@'%' IDENTIFIED BY '{password}';\n")
    handle.write(f"GRANT ALL PRIVILEGES ON `{database}`.* TO '{user}'@'%';\n")
PY
chmod 0600 "$create_user_sql"
set_phase bridge-user-create-pending
run_db_sql "$old_root_remote" "$create_user_sql" \
    || die 'Could not create and grant the bridge database account; output suppressed.'
set_phase bridge-user-created

candidate_test_sql="$work_dir/candidate-test.sql"
printf '%s\n' \
    'SELECT 1;' \
    'CREATE TEMPORARY TABLE conzent_rotation_probe (value INT NOT NULL);' \
    'INSERT INTO conzent_rotation_probe VALUES (1);' \
    'SELECT value FROM conzent_rotation_probe;' \
    'DROP TEMPORARY TABLE conzent_rotation_probe;' >"$candidate_test_sql"
chmod 0600 "$candidate_test_sql"
run_db_sql "$new_app_remote" "$candidate_test_sql" \
    || die 'The bridge database account failed its authentication/privilege test; output suppressed.'
[[ $(grep -Fxc '1' "$DB_SQL_OUTPUT") -eq 2 ]] \
    || die 'The bridge database account returned an unexpected privilege-test result.'
set_phase bridge-user-tested

rotate_root_sql="$work_dir/rotate-root.sql"
python3 - "$recovery_file" "$accounts_file" "$rotate_root_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    accounts = json.load(handle)
password = state["new"]["root_password"]
clauses = [f"'root'@'{row['host']}' IDENTIFIED BY '{password}'" for row in accounts["root"]]
if not clauses:
    raise SystemExit(1)
with open(sys.argv[3], "x", encoding="utf-8") as handle:
    handle.write("ALTER USER " + ", ".join(clauses) + ";\nFLUSH PRIVILEGES;\n")
PY
chmod 0600 "$rotate_root_sql"
set_phase root-rotation-pending
run_db_sql "$old_root_remote" "$rotate_root_sql" \
    || die 'MariaDB root credential rotation failed; output suppressed.'
set_phase root-rotated
run_db_sql "$new_root_remote" "$probe_sql" \
    || die 'The new MariaDB root credential did not authenticate; output suppressed.'
grep -Fxq '1' "$DB_SQL_OUTPUT" || die 'The new root probe returned an unexpected result.'
root_hash_sql="$work_dir/root-hashes.sql"
printf '%s\n' \
    "SELECT Host,plugin,authentication_string FROM mysql.user WHERE User='root' ORDER BY Host;" \
    >"$root_hash_sql"
chmod 0600 "$root_hash_sql"
run_db_sql "$new_root_remote" "$root_hash_sql" \
    || die 'Could not verify every MariaDB root account after rotation; output suppressed.'
if ! python3 - "$recovery_file" "$accounts_file" "$DB_SQL_OUTPUT" <<'PY'
import hashlib
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    accounts = json.load(handle)
rows = []
with open(sys.argv[3], encoding="utf-8") as handle:
    for raw in handle:
        fields = raw.rstrip("\n").split("\t")
        if len(fields) != 3:
            raise SystemExit(1)
        rows.append(tuple(fields))
expected_hosts = sorted(row["host"] for row in accounts["root"])
if sorted(row[0] for row in rows) != expected_hosts:
    raise SystemExit(1)
password = state["new"]["root_password"].encode("utf-8")
expected_hash = "*" + hashlib.sha1(hashlib.sha1(password).digest()).hexdigest().upper()
if any(plugin != "mysql_native_password" or auth_hash.upper() != expected_hash for _, plugin, auth_hash in rows):
    raise SystemExit(1)
PY
then
    die 'Not every MariaDB root account has the expected new credential; values suppressed.'
fi
if run_db_sql "$old_root_remote" "$probe_sql"; then
    die 'The old MariaDB root credential still authenticates; protected recovery state was retained.'
fi
set_phase root-verified

bulk_body="$work_dir/database-env.bulk.json"
python3 - "$recovery_file" "$bulk_body" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
new = state["new"]
items = [
    ("DB_USER", new["user"]),
    ("DB_PASSWORD", new["password"]),
    ("DB_ROOT_PASSWORD", new["root_password"]),
    ("DATABASE_URL", new["database_url"]),
]
payload = {"data": [
    {"key": key, "value": value, "is_literal": True, "is_preview": False, "is_multiline": False}
    for key, value in items
]}
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    json.dump(payload, handle, separators=(",", ":"))
    handle.write("\n")
PY
chmod 0600 "$bulk_body"

classify_env() {
    local response=${1:?environment response required}
    python3 - "$response" "$recovery_file" <<'PY'
import json
import re
import sys

def truthy(value):
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}

with open(sys.argv[1], encoding="utf-8") as handle:
    payload = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    state = json.load(handle)
if isinstance(payload, dict) and isinstance(payload.get("data"), list):
    payload = payload["data"]
if not isinstance(payload, list):
    raise SystemExit(1)
wanted = {"DB_USER", "DB_PASSWORD", "DB_ROOT_PASSWORD", "DATABASE_URL"}
found = {}
for item in payload:
    if not isinstance(item, dict) or truthy(item.get("is_preview", False)):
        continue
    key = str(item.get("key", ""))
    if key not in wanted:
        continue
    values = found.setdefault(key, [])
    for field in ("real_value", "value"):
        value = item.get(field)
        if value is not None and str(value) and not re.fullmatch(r"\*+", str(value)):
            values.append(str(value))
            break
if set(found) != wanted or any(len(values) != 1 for values in found.values()):
    print("unexpected")
    raise SystemExit(0)
old = {
    "DB_USER": state["old"]["user"],
    "DB_PASSWORD": state["old"]["password"],
    "DB_ROOT_PASSWORD": state["old"]["root_password"],
    "DATABASE_URL": state["old"]["database_url"],
}
new = {
    "DB_USER": state["new"]["user"],
    "DB_PASSWORD": state["new"]["password"],
    "DB_ROOT_PASSWORD": state["new"]["root_password"],
    "DATABASE_URL": state["new"]["database_url"],
}
actual = {key: values[0] for key, values in found.items()}
if actual == new:
    print("desired")
elif actual == old:
    print("before")
elif all(actual[key] in {old[key], new[key]} for key in wanted):
    print("mixed")
else:
    print("unexpected")
PY
}

set_phase coolify-env-staging
env_state=before
for stage_attempt in 1 2 3; do
    bulk_response="$work_dir/database-env.bulk-response-$stage_attempt.json"
    if api_request PATCH "/applications/$APP_UUID/envs/bulk" "$bulk_response" "$bulk_body"; then
        case $API_STATUS in
            200|201) ;;
            *) log "Coolify bulk environment attempt $stage_attempt returned HTTP $API_STATUS; reconciling by readback." ;;
        esac
    else
        log "Coolify bulk environment attempt $stage_attempt had a transport error; reconciling by readback."
    fi
    env_after="$work_dir/environments.after-$stage_attempt.json"
    if ! api_request GET "/applications/$APP_UUID/envs" "$env_after" || [[ $API_STATUS != 200 ]]; then
        if ((stage_attempt < 3)); then
            continue
        fi
        die 'Could not read back database environment variables after staging; response suppressed.'
    fi
    env_state=$(classify_env "$env_after") \
        || die 'Could not validate Coolify database environment readback; response suppressed.'
    case $env_state in
        desired) break ;;
        before|mixed)
            log "Coolify database variables are in the '$env_state' state; retrying the idempotent bulk update."
            ;;
        unexpected)
            die 'Coolify database variables contain an unexpected value/shape; no deployment was started.'
            ;;
        *) die 'Internal error while classifying database variables.' ;;
    esac
done
[[ $env_state == desired ]] \
    || die 'Coolify database variables did not converge to one coherent credential set; no deployment was started.'
set_phase coolify-env-coherent

remove_db_configs || die 'Could not remove transient database client configs before deployment.'
set_phase deployment-pending
if ! CONZENT_COOLIFY_LOCK_FD=9 \
    COOLIFY_ACCESS_TOKEN="$COOLIFY_ACCESS_TOKEN" \
    EXPECTED_HOST="$EXPECTED_HOST" \
    COOLIFY_API_URL="$COOLIFY_API_URL" \
    APP_UUID="$APP_UUID" \
    bash "$script_dir/41-coolify-deploy.sh"
then
    die 'Coolify deployment failed; the old app account remains available and recovery state was retained.'
fi
set_phase deployed

db_container=$(find_running_service mariadb)
remember_db_container "$db_container"
app_container=$(find_running_service app)
db_client=$(docker exec "$db_container" sh -c \
    'if command -v mariadb >/dev/null 2>&1; then command -v mariadb; elif command -v mysql >/dev/null 2>&1; then command -v mysql; else exit 1; fi') \
    || die 'No MariaDB client exists in the deployed database container.'
[[ $db_client == /* && $db_client != *[[:space:]]* ]] \
    || die 'The deployed database container returned an unsafe client path.'
install_all_db_configs || die 'Could not install protected client configs in the deployed database container.'

verify_runtime_env() {
    local service=${1:?service required}
    local kind=${2:?app or database required}
    local container inspect_file
    container=$(find_running_service "$service")
    inspect_file="$work_dir/$service.inspect.json"
    : >"$inspect_file"
    chmod 0600 "$inspect_file"
    docker inspect "$container" >"$inspect_file" \
        || die "Could not inspect the deployed $service container."
    python3 - "$inspect_file" "$recovery_file" "$kind" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    inspected = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    state = json.load(handle)
if not isinstance(inspected, list) or len(inspected) != 1:
    raise SystemExit(1)
environment = inspected[0].get("Config", {}).get("Env", [])
values = {}
for entry in environment:
    key, separator, value = str(entry).partition("=")
    if separator:
        values.setdefault(key, []).append(value)
new = state["new"]
if sys.argv[3] == "app":
    expected = {
        "DB_HOST": "mariadb",
        "DB_PORT": "3306",
        "DB_NAME": state["database"],
        "DB_USER": new["user"],
        "DB_PASSWORD": new["password"],
        "DATABASE_URL": new["database_url"],
    }
elif sys.argv[3] == "database":
    expected = {
        "MARIADB_DATABASE": state["database"],
        "MARIADB_USER": new["user"],
        "MARIADB_PASSWORD": new["password"],
        "MARIADB_ROOT_PASSWORD": new["root_password"],
    }
else:
    raise SystemExit(1)
if any(values.get(key) != [value] for key, value in expected.items()):
    raise SystemExit(1)
PY
}

for service in app worker scheduler beacon-worker autoseo-render; do
    verify_runtime_env "$service" app \
        || die "The deployed $service container does not have the coherent new database environment; values suppressed."
done
verify_runtime_env mariadb database \
    || die 'The deployed MariaDB container does not have the coherent new credential environment; values suppressed.'

run_db_sql "$new_root_remote" "$probe_sql" \
    || die 'The deployed MariaDB root credential failed authentication; output suppressed.'
run_db_sql "$new_app_remote" "$candidate_test_sql" \
    || die 'The deployed bridge app credential failed its privilege test; output suppressed.'
run_db_sql "$old_app_remote" "$probe_sql" \
    || die 'The old app account disappeared before cutover verification; recovery state was retained.'

check_application_health() {
    local cli_health="$work_dir/cli-health.txt"
    : >"$cli_health"
    chmod 0600 "$cli_health"
    docker exec "$app_container" php bin/oci health >"$cli_health" 2>&1 \
        || return 1
    grep -Fxq 'Database: OK' "$cli_health" \
        && grep -Fxq 'Redis: OK' "$cli_health" \
        && grep -Fxq 'Environment: prod' "$cli_health" \
        && grep -Fxq 'Debug: false' "$cli_health"
}
check_application_health \
    || die 'The deployed application CLI health check failed; output suppressed.'

http_body="$work_dir/http-health.json"
http_config="$work_dir/http-health.curl.conf"
: >"$http_body"
chmod 0600 "$http_body"
{
    printf 'silent\nshow-error\nconnect-timeout = 10\nmax-time = 30\n'
    printf 'url = "%s"\n' "$APP_HEALTH_URL"
    printf 'output = "%s"\n' "$http_body"
    printf 'write-out = "%%{http_code}"\n'
    printf 'header = "Accept: application/json"\n'
} >"$http_config"
chmod 0600 "$http_config"
if ! http_status=$(curl --config "$http_config"); then
    die 'The public application health request failed; response suppressed.'
fi
[[ $http_status == 200 ]] \
    || die "The public application health endpoint returned HTTP $http_status; response suppressed."
if ! python3 - "$http_body" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    payload = json.load(handle)
data = payload.get("data") if isinstance(payload, dict) else None
services = data.get("services") if isinstance(data, dict) else None
if not (
    payload.get("success") is True
    and data.get("status") == "ok"
    and data.get("environment") == "prod"
    and isinstance(services, dict)
    and services.get("database") is True
    and services.get("redis") is True
):
    raise SystemExit(1)
PY
then
    die 'The public application health payload was not fully healthy; response suppressed.'
fi
set_phase cutover-verified

drop_old_sql="$work_dir/drop-old-user.sql"
python3 - "$recovery_file" "$accounts_file" "$drop_old_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
with open(sys.argv[2], encoding="utf-8") as handle:
    accounts = json.load(handle)
user = state["old"]["user"]
accounts_to_drop = [f"'{user}'@'{row['host']}'" for row in accounts["old"]]
if not accounts_to_drop:
    raise SystemExit(1)
with open(sys.argv[3], "x", encoding="utf-8") as handle:
    handle.write("DROP USER IF EXISTS " + ", ".join(accounts_to_drop) + ";\n")
PY
chmod 0600 "$drop_old_sql"
set_phase old-user-drop-pending
run_db_sql "$new_root_remote" "$drop_old_sql" \
    || die 'Could not retire the old database app account; output suppressed.'
set_phase old-user-dropped

old_count_sql="$work_dir/old-user-count.sql"
python3 - "$recovery_file" "$old_count_sql" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    state = json.load(handle)
with open(sys.argv[2], "x", encoding="utf-8") as handle:
    handle.write(f"SELECT COUNT(*) FROM mysql.user WHERE User='{state['old']['user']}';\n")
PY
chmod 0600 "$old_count_sql"
run_db_sql "$new_root_remote" "$old_count_sql" \
    || die 'Could not verify retirement of the old app account; output suppressed.'
grep -Fxq '0' "$DB_SQL_OUTPUT" || die 'The old database app account still exists.'
if run_db_sql "$old_app_remote" "$probe_sql"; then
    die 'The retired database app credential still authenticates.'
fi
run_db_sql "$new_app_remote" "$candidate_test_sql" \
    || die 'The new app credential failed after old-account retirement; output suppressed.'
run_db_sql "$new_root_remote" "$probe_sql" \
    || die 'The new root credential failed after old-account retirement; output suppressed.'
check_application_health \
    || die 'The application health check failed after old-account retirement; output suppressed.'
remove_db_configs || die 'Could not remove transient database client configs after rotation.'

set_phase complete
rm -f -- "$accounts_file" "$old_grants_file" "$recovery_file"
[[ ! -e $recovery_file ]] || die 'Could not securely retire the completed recovery record.'
log 'Database credentials rotated, deployed, authenticated, and verified without a reboot.'
log "Non-secret completion marker: $state_dir/phase"
