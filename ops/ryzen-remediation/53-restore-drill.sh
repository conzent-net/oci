#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

ARCHIVE=${1:-${ARCHIVE:-}}
AGE_IDENTITY_FILE=${AGE_IDENTITY_FILE:-}
DRILL_ROOT=${DRILL_ROOT:-/var/backups/conzent-remediation/restore-drills}
ALLOW_IMAGE_PULL=${ALLOW_IMAGE_PULL:-0}
RUN_DATA_SERVICES=${RUN_DATA_SERVICES:-1}

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

(($# <= 1)) || die 'Usage: AGE_IDENTITY_FILE=/root/key.txt 53-restore-drill.sh ARCHIVE.tar.age'
[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this script as root on an isolated drill host.'
if [[ $(hostname -s) == ryzen-prod && ${ALLOW_PRODUCTION_DRILL:-0} != 1 ]]; then
    die 'Refusing to run a restore drill on ryzen-prod. Use a separate isolated host.'
fi
[[ -n $ARCHIVE ]] || die 'Usage: AGE_IDENTITY_FILE=/root/key.txt 53-restore-drill.sh ARCHIVE.tar.age'
[[ -n $AGE_IDENTITY_FILE ]] || die 'Set AGE_IDENTITY_FILE to the private age identity file.'
[[ $ALLOW_IMAGE_PULL =~ ^[01]$ ]] || die 'ALLOW_IMAGE_PULL must be 0 or 1.'
[[ $RUN_DATA_SERVICES =~ ^[01]$ ]] || die 'RUN_DATA_SERVICES must be 0 or 1.'
for command_name in age awk basename chmod date docker find findmnt grep gzip head install mktemp \
    openssl python3 realpath rm sha256sum sleep stat tar tr wc; do
    command -v "$command_name" >/dev/null 2>&1 || die "Required command not found: $command_name"
done

[[ -f $ARCHIVE && ! -L $ARCHIVE ]] || die 'The encrypted archive is missing, not regular, or a symlink.'
ARCHIVE=$(realpath -e -- "$ARCHIVE")
[[ $ARCHIVE == *.tar.age && $ARCHIVE != *.partial ]] || die 'Only a completed .tar.age artifact can be drilled.'
sidecar="$ARCHIVE.sha256"
[[ -f $sidecar && ! -L $sidecar ]] || die 'The archive checksum sidecar is missing or unsafe.'
[[ $(wc -l <"$sidecar") -eq 1 ]] || die 'The checksum sidecar must contain exactly one line.'
AGE_IDENTITY_FILE=$(realpath -e -- "$AGE_IDENTITY_FILE")
[[ -f $AGE_IDENTITY_FILE && ! -L $AGE_IDENTITY_FILE ]] || die 'AGE_IDENTITY_FILE must be a regular file, not a symlink.'
[[ $(stat -c '%u' -- "$AGE_IDENTITY_FILE") == 0 ]] || die 'AGE_IDENTITY_FILE must be owned by root.'
if find "$AGE_IDENTITY_FILE" -prune -perm /077 -print -quit | grep -q .; then
    die 'AGE_IDENTITY_FILE must not be accessible by group or other users.'
fi

expected_hash=''
recorded_name=''
extra=''
read -r expected_hash recorded_name extra <"$sidecar" || die 'Could not parse the checksum sidecar.'
[[ $expected_hash =~ ^[0-9a-f]{64}$ && -z $extra ]] || die 'The checksum sidecar has an invalid format.'
[[ $recorded_name == "$(basename -- "$ARCHIVE")" ]] || die 'The checksum sidecar names a different artifact.'
actual_hash=$(sha256sum "$ARCHIVE" | awk '{print $1}')
[[ $actual_hash == "$expected_hash" ]] || die 'Ciphertext SHA-256 verification failed.'
[[ $(head -n 1 "$ARCHIVE") == age-encryption.org/v1 ]] || die 'The archive does not have an age v1 header.'

[[ $DRILL_ROOT == /* ]] || die 'DRILL_ROOT must be an absolute path.'
install -d -m 0700 -- "$DRILL_ROOT"
[[ ! -L $DRILL_ROOT ]] || die 'DRILL_ROOT must not be a symlink.'
case $(findmnt -n -o FSTYPE -T "$DRILL_ROOT") in
    fuse*|sshfs) die 'DRILL_ROOT must be local storage, not FUSE/SSHFS.' ;;
esac

stamp=$(date -u +%Y%m%dT%H%M%SZ)
random_suffix=$(openssl rand -hex 4)
prefix="conzent-drill-${stamp,,}-${random_suffix}"
[[ $prefix =~ ^conzent-drill-[a-z0-9-]+$ ]] || die 'Internal drill prefix validation failed.'
stage=$(mktemp -d -p "$DRILL_ROOT" "${prefix}.XXXXXX")
bundle="$stage/bundle.tar"
extract_dir="$stage/extracted"
install -d -m 0700 "$extract_dir"

created_containers=()
created_volumes=()
network_name="$prefix-net"
network_created=0

safe_drill_name() {
    [[ $1 == "$prefix-"* ]]
}

container_is_owned() {
    [[ $(docker container inspect --format '{{index .Config.Labels "conzent.restore-drill.prefix"}}' "$1" 2>/dev/null) == "$prefix" ]]
}

network_is_owned() {
    [[ $(docker network inspect --format '{{index .Labels "conzent.restore-drill.prefix"}}' "$1" 2>/dev/null) == "$prefix" ]]
}

volume_is_owned() {
    [[ $(docker volume inspect --format '{{index .Labels "conzent.restore-drill.prefix"}}' "$1" 2>/dev/null) == "$prefix" ]]
}

cleanup() {
    local rc=$? index name cleanup_failed=0
    trap - EXIT INT TERM
    set +e
    for ((index=${#created_containers[@]}-1; index>=0; index--)); do
        name=${created_containers[$index]}
        if ! docker container inspect "$name" >/dev/null 2>&1; then
            continue
        elif safe_drill_name "$name" && container_is_owned "$name"; then
            docker rm -f "$name" >/dev/null 2>&1 || cleanup_failed=1
        else
            cleanup_failed=1
        fi
    done
    if ((network_created)); then
        if ! docker network inspect "$network_name" >/dev/null 2>&1; then
            :
        elif safe_drill_name "$network_name" && network_is_owned "$network_name"; then
            docker network rm "$network_name" >/dev/null 2>&1 || cleanup_failed=1
        else
            cleanup_failed=1
        fi
    fi
    for ((index=${#created_volumes[@]}-1; index>=0; index--)); do
        name=${created_volumes[$index]}
        if ! docker volume inspect "$name" >/dev/null 2>&1; then
            continue
        elif safe_drill_name "$name" && volume_is_owned "$name"; then
            docker volume rm "$name" >/dev/null 2>&1 || cleanup_failed=1
        else
            cleanup_failed=1
        fi
    done
    if [[ -n ${stage:-} && $stage == "${DRILL_ROOT%/}/${prefix}."* ]]; then
        rm -rf -- "$stage" || cleanup_failed=1
    else
        cleanup_failed=1
    fi
    if ((cleanup_failed)); then
        printf 'WARNING: disposable drill cleanup was incomplete; inspect resources labeled conzent.restore-drill=true.\n' >&2
        ((rc != 0)) || rc=1
    elif ((rc == 0)); then
        printf 'Disposable drill containers, network, volumes, and plaintext staging data were removed.\n'
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

log 'Decrypting into root-only local staging (never into a production volume).'
age --decrypt -i "$AGE_IDENTITY_FILE" -o "$bundle" "$ARCHIVE"
chmod 0600 "$bundle"

python3 - "$bundle" <<'PY'
import pathlib
import sys
import tarfile

archive = sys.argv[1]
required = {
    "manifest.txt",
    "SHA256SUMS",
    "payload/database.sql.gz",
    "payload/volumes/app-public.tar.gz",
    "payload/volumes/app-var.tar.gz",
    "payload/volumes/www-blog.tar.gz",
    "payload/volumes/redis.tar.gz",
    "payload/volumes/elasticsearch.tar.gz",
    "payload/volumes/wix.tar.gz",
}
regular = set()
with tarfile.open(archive, "r:") as handle:
    for member in handle.getmembers():
        name = member.name
        if any(ord(ch) < 32 or ord(ch) == 127 for ch in name) or "\\" in name:
            raise SystemExit("unsafe control character or backslash in outer archive path")
        path = pathlib.PurePosixPath(name)
        if path.is_absolute() or ".." in path.parts:
            raise SystemExit("unsafe outer archive path")
        clean = str(path)
        if clean not in {".", "payload", "payload/volumes"} and clean not in required:
            raise SystemExit("unexpected outer archive member")
        if not (member.isdir() or member.isfile()):
            raise SystemExit("outer archive contains a link or special file")
        if member.isfile():
            if clean in regular:
                raise SystemExit("outer archive contains a duplicate regular file")
            regular.add(clean)
if regular != required:
    raise SystemExit("outer archive payload is incomplete or duplicated")
PY

tar --no-same-owner --no-same-permissions -xf "$bundle" -C "$extract_dir"

python3 - "$extract_dir/SHA256SUMS" <<'PY'
import re
import sys

expected = {
    "payload/database.sql.gz",
    "payload/volumes/app-public.tar.gz",
    "payload/volumes/app-var.tar.gz",
    "payload/volumes/www-blog.tar.gz",
    "payload/volumes/redis.tar.gz",
    "payload/volumes/elasticsearch.tar.gz",
    "payload/volumes/wix.tar.gz",
}
seen = set()
with open(sys.argv[1], "r", encoding="ascii") as handle:
    for raw in handle:
        match = re.fullmatch(r"[0-9a-f]{64}  (payload/[A-Za-z0-9./_-]+)\n?", raw)
        if not match or match.group(1) in seen:
            raise SystemExit("invalid or duplicate SHA256SUMS entry")
        seen.add(match.group(1))
if seen != expected:
    raise SystemExit("SHA256SUMS does not cover the exact expected payload")
PY
(
    cd "$extract_dir"
    sha256sum --check --strict SHA256SUMS >/dev/null
)

for payload in "$extract_dir/payload/database.sql.gz" "$extract_dir"/payload/volumes/*.tar.gz; do
    gzip -t "$payload"
done

python3 - "$extract_dir/payload/volumes"/*.tar.gz <<'PY'
import posixpath
import sys
import tarfile

def safe_path(value):
    if not value or value.startswith("/") or "\\" in value:
        return False
    if any(ord(ch) < 32 or ord(ch) == 127 for ch in value):
        return False
    normalized = posixpath.normpath(value)
    return normalized not in ("..", "/") and not normalized.startswith("../")

for archive in sys.argv[1:]:
    with tarfile.open(archive, "r:gz") as handle:
        for member in handle.getmembers():
            if not safe_path(member.name):
                raise SystemExit("unsafe path in a volume archive")
            if member.ischr() or member.isblk() or member.isfifo() or member.isdev():
                raise SystemExit("special device/FIFO in a volume archive")
            if member.issym():
                target = posixpath.normpath(posixpath.join(posixpath.dirname(member.name), member.linkname))
                if not safe_path(target):
                    raise SystemExit("escaping symlink in a volume archive")
            elif member.islnk():
                if not safe_path(posixpath.normpath(member.linkname)):
                    raise SystemExit("escaping hardlink in a volume archive")
            elif not (member.isfile() or member.isdir()):
                raise SystemExit("unsupported member type in a volume archive")
PY

manifest_value() {
    local key=${1:?manifest key required}
    awk -F= -v key="$key" '
        $1 == key { count++; value=substr($0, length(key)+2) }
        END { if (count != 1) exit 2; print value }
    ' "$extract_dir/manifest.txt"
}

[[ $(manifest_value backup_format) == 3 ]] || die 'Unsupported backup manifest format.'
[[ $(manifest_value stack_uuid) == brrmsbi50m4q02lqx35juhlr ]] || die 'Backup belongs to an unexpected stack UUID.'
[[ $(manifest_value payload) == database.sql.gz,app-public,app-var,www-blog,redis,elasticsearch,wix ]] \
    || die 'Backup manifest payload is incomplete or unexpected.'
expected_wix_json_files=$(manifest_value wix_json_files)
expected_mariadb_tables=$(manifest_value mariadb_tables)
expected_elasticsearch_user_indices=$(manifest_value elasticsearch_user_indices)
for expected_count in "$expected_wix_json_files" "$expected_mariadb_tables" "$expected_elasticsearch_user_indices"; do
    [[ $expected_count =~ ^[0-9]+$ ]] || die 'A manifest source-count baseline is invalid.'
done
((expected_mariadb_tables > 0)) || die 'The source database baseline contains no tables.'

mariadb_image=${MARIADB_IMAGE:-$(manifest_value mariadb_image)}
redis_image=${REDIS_IMAGE:-$(manifest_value redis_image)}
elasticsearch_image=${ELASTICSEARCH_IMAGE:-$(manifest_value elasticsearch_image)}
for image_ref in "$mariadb_image" "$redis_image" "$elasticsearch_image"; do
    [[ $image_ref =~ ^[A-Za-z0-9][A-Za-z0-9._/@:+-]*$ ]] || die 'A drill image reference contains unsafe characters.'
done

ensure_image() {
    local image=${1:?image required}
    if docker image inspect "$image" >/dev/null 2>&1; then return 0; fi
    ((ALLOW_IMAGE_PULL == 1)) || die "Required image is not local: $image (set ALLOW_IMAGE_PULL=1 deliberately)."
    docker pull "$image" >/dev/null
}

ensure_image "$mariadb_image"
if ((RUN_DATA_SERVICES)); then
    ensure_image "$redis_image"
    ensure_image "$elasticsearch_image"
fi

docker network inspect "$network_name" >/dev/null 2>&1 && die 'Random drill network already exists; refusing reuse.'
docker network create --internal \
    --label conzent.restore-drill=true --label "conzent.restore-drill.prefix=$prefix" \
    "$network_name" >/dev/null
network_created=1

declare -A drill_volume=()
for logical in mariadb app-public app-var www-blog redis elasticsearch wix; do
    name="$prefix-vol-$logical"
    safe_drill_name "$name" || die 'Internal drill volume name validation failed.'
    docker volume inspect "$name" >/dev/null 2>&1 && die "Random drill volume already exists: $name"
    docker volume create --driver local \
        --label conzent.restore-drill=true \
        --label "conzent.restore-drill.prefix=$prefix" \
        --label "conzent.restore-drill.logical=$logical" \
        "$name" >/dev/null
    created_volumes+=("$name")
    drill_volume[$logical]=$name
done

restore_volume() {
    local logical=${1:?logical required}
    local archive="$extract_dir/payload/volumes/${logical}.tar.gz"
    local name=${drill_volume[$logical]}
    local mountpoint
    [[ $(docker volume inspect --format '{{.Driver}}' "$name") == local ]] || die 'Drill volume is not local.'
    mountpoint=$(docker volume inspect --format '{{.Mountpoint}}' "$name")
    [[ -d $mountpoint && ! -L $mountpoint ]] || die 'A drill volume mountpoint is unsafe.'
    tar --numeric-owner --acls --xattrs -xzpf "$archive" -C "$mountpoint"
    printf 'Restored %-13s entries: %s\n' "$logical" "$(find "$mountpoint" -xdev -mindepth 1 | wc -l)"
}

for logical in app-public app-var www-blog redis elasticsearch wix; do
    restore_volume "$logical"
done

wix_mount=$(docker volume inspect --format '{{.Mountpoint}}' "${drill_volume[wix]}")
wix_json_count=$(python3 - "$wix_mount" <<'PY'
import json
import pathlib
import sys

count = 0
for path in pathlib.Path(sys.argv[1]).rglob("*.json"):
    if path.is_file():
        with path.open("r", encoding="utf-8") as handle:
            json.load(handle)
        count += 1
print(count)
PY
)
[[ $wix_json_count == "$expected_wix_json_files" ]] \
    || die 'The restored Wix JSON-file count does not match the source baseline.'
printf 'Wix JSON files parsed: %s\n' "$wix_json_count"

root_secret="$stage/mariadb-root-password"
openssl rand -hex 32 >"$root_secret"
chmod 0600 "$root_secret"
db_name="$prefix-mariadb"
safe_drill_name "$db_name" || die 'Internal drill container name validation failed.'
docker container inspect "$db_name" >/dev/null 2>&1 && die 'Random drill DB container already exists.'
created_containers+=("$db_name")
docker run -d --name "$db_name" --network "$network_name" --network-alias mariadb \
    --label conzent.restore-drill=true --label "conzent.restore-drill.prefix=$prefix" \
    --mount "type=volume,src=${drill_volume[mariadb]},dst=/var/lib/mysql" \
    --mount "type=bind,src=$root_secret,dst=/run/secrets/mariadb-root,readonly" \
    -e MARIADB_ROOT_PASSWORD_FILE=/run/secrets/mariadb-root \
    -e MARIADB_DATABASE=oci_drill \
    "$mariadb_image" >/dev/null

for attempt in {1..120}; do
    docker exec "$db_name" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1 && break
    ((attempt < 120)) || die 'Disposable MariaDB did not become ready.'
    sleep 1
done

log 'Importing the logical dump into disposable MariaDB.'
gzip -dc "$extract_dir/payload/database.sql.gz" | docker exec -i "$db_name" sh -eu -c '
    option_file=$(mktemp)
    trap '\''rm -f -- "$option_file"'\'' EXIT INT TERM
    chmod 0600 "$option_file"
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb-root)" >"$option_file"
    mariadb --defaults-extra-file="$option_file" oci_drill
'

table_count=$(docker exec "$db_name" sh -eu -c '
    option_file=$(mktemp)
    trap '\''rm -f -- "$option_file"'\'' EXIT INT TERM
    chmod 0600 "$option_file"
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb-root)" >"$option_file"
    mariadb --defaults-extra-file="$option_file" --batch --skip-column-names \
        oci_drill -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"
' 2>/dev/null)
[[ $table_count =~ ^[0-9]+$ && $table_count -gt 0 ]] || die 'The disposable database contains no tables after import.'
[[ $table_count == "$expected_mariadb_tables" ]] \
    || die 'The restored MariaDB table count does not match the source baseline.'
docker exec "$db_name" sh -eu -c '
    option_file=$(mktemp)
    trap '\''rm -f -- "$option_file"'\'' EXIT INT TERM
    chmod 0600 "$option_file"
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb-root)" >"$option_file"
    mariadb-check --defaults-extra-file="$option_file" oci_drill >/dev/null
'
printf 'MariaDB tables restored and checked: %s\n' "$table_count"

if ((RUN_DATA_SERVICES)); then
    redis_name="$prefix-redis"
    safe_drill_name "$redis_name" || die 'Internal Redis drill name validation failed.'
    docker container inspect "$redis_name" >/dev/null 2>&1 && die 'Random drill Redis container already exists.'
    created_containers+=("$redis_name")
    docker run -d --name "$redis_name" --network "$network_name" \
        --label conzent.restore-drill=true --label "conzent.restore-drill.prefix=$prefix" \
        --mount "type=volume,src=${drill_volume[redis]},dst=/data" \
        "$redis_image" redis-server --appendonly no >/dev/null
    for attempt in {1..60}; do
        docker exec "$redis_name" redis-cli ping 2>/dev/null | grep -qx PONG && break
        ((attempt < 60)) || die 'Disposable Redis did not load the restored volume.'
        sleep 1
    done
    redis_keys=$(docker exec "$redis_name" redis-cli DBSIZE | tr -d '\r')
    [[ $redis_keys =~ ^[0-9]+$ ]] || die 'Could not read a key count from disposable Redis.'
    printf 'Redis loaded; key count: %s\n' "$redis_keys"

    elasticsearch_name="$prefix-elasticsearch"
    safe_drill_name "$elasticsearch_name" || die 'Internal Elasticsearch drill name validation failed.'
    docker container inspect "$elasticsearch_name" >/dev/null 2>&1 && die 'Random drill Elasticsearch container already exists.'
    created_containers+=("$elasticsearch_name")
    docker run -d --name "$elasticsearch_name" --network "$network_name" \
        --label conzent.restore-drill=true --label "conzent.restore-drill.prefix=$prefix" \
        --mount "type=volume,src=${drill_volume[elasticsearch]},dst=/usr/share/elasticsearch/data" \
        --memory 1g \
        -e discovery.type=single-node -e xpack.security.enabled=false \
        -e 'ES_JAVA_OPTS=-Xms256m -Xmx256m' \
        "$elasticsearch_image" >/dev/null
    for attempt in {1..180}; do
        docker exec "$elasticsearch_name" sh -c \
            'curl -fsS "http://127.0.0.1:9200/_cluster/health?wait_for_status=yellow&timeout=1s" >/dev/null' \
            2>/dev/null && break
        ((attempt < 180)) || die 'Disposable Elasticsearch did not load the restored volume.'
        sleep 1
    done
    elasticsearch_indices=$(docker exec "$elasticsearch_name" sh -c \
        "curl -fsS 'http://127.0.0.1:9200/_cat/indices?h=index' | awk '\$1 !~ /^\\./ { count++ } END { print count + 0 }'")
    [[ $elasticsearch_indices =~ ^[0-9]+$ ]] || die 'Could not read a user-index count from disposable Elasticsearch.'
    [[ $elasticsearch_indices == "$expected_elasticsearch_user_indices" ]] \
        || die 'The restored Elasticsearch user-index count does not match the source baseline.'
    printf 'Elasticsearch loaded; user-index count: %s\n' "$elasticsearch_indices"
fi

printf '\nRestore drill passed with isolated prefix: %s\n' "$prefix"
printf 'No production Compose project, container, network, or volume was referenced.\n'
