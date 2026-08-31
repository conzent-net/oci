#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

STACK_UUID=${STACK_UUID:-brrmsbi50m4q02lqx35juhlr}
BACKUP_MOUNT=${BACKUP_MOUNT:-/mnt/storagebox}
BACKUP_DEST=${BACKUP_DEST:-${BACKUP_MOUNT%/}/backups/conzent-encrypted}
LOCAL_STAGE_ROOT=${LOCAL_STAGE_ROOT:-/var/backups/conzent-remediation/full-backup-staging}
STOP_TIMEOUT=${STOP_TIMEOUT:-45}
AGE_RECIPIENT_FILE=${AGE_RECIPIENT_FILE:-}
EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

log() {
    printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"
}

[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this script as root.'
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || die "This script is pinned to $EXPECTED_HOST."
[[ -n $AGE_RECIPIENT_FILE ]] || die 'Set AGE_RECIPIENT_FILE to a file containing the public age recipient.'
for command_name in age awk cat chmod date df du docker find findmnt flock grep gzip head install mktemp mountpoint \
    mv realpath rm sha256sum sleep sort stat sync tar wc xargs; do
    command -v "$command_name" >/dev/null 2>&1 || die "Required command not found: $command_name"
done
[[ $STACK_UUID =~ ^[a-zA-Z0-9_-]+$ ]] || die 'STACK_UUID contains unsafe characters.'
[[ $STOP_TIMEOUT =~ ^[1-9][0-9]*$ ]] || die 'STOP_TIMEOUT must be a positive integer.'
exec 9>/run/lock/conzent-full-backup.lock
flock -n 9 || die 'Another Conzent full backup is already running.'

AGE_RECIPIENT_FILE=$(realpath -e -- "$AGE_RECIPIENT_FILE")
[[ -f $AGE_RECIPIENT_FILE && ! -L $AGE_RECIPIENT_FILE ]] \
    || die 'AGE_RECIPIENT_FILE must be a regular file, not a symlink.'
[[ $(stat -c '%u' -- "$AGE_RECIPIENT_FILE") == 0 ]] \
    || die 'AGE_RECIPIENT_FILE must be owned by root.'
if find "$AGE_RECIPIENT_FILE" -prune -perm /022 -print -quit | grep -q .; then
    die 'AGE_RECIPIENT_FILE must not be group- or world-writable.'
fi

[[ $BACKUP_MOUNT == /* && $BACKUP_DEST == /* && $LOCAL_STAGE_ROOT == /* ]] \
    || die 'Backup and staging paths must be absolute.'
[[ $BACKUP_DEST == "${BACKUP_MOUNT%/}/"* ]] \
    || die 'BACKUP_DEST must be below BACKUP_MOUNT.'
[[ -d $BACKUP_MOUNT && ! -L $BACKUP_MOUNT ]] || die 'The expected SSHFS mount directory is unavailable.'
mountpoint -q -- "$BACKUP_MOUNT" || die "$BACKUP_MOUNT is not an exact mount point."
[[ $(findmnt -n -o FSTYPE --mountpoint "$BACKUP_MOUNT") == fuse.sshfs ]] \
    || die 'The backup mount is not fuse.sshfs.'
[[ -d $BACKUP_DEST && ! -L $BACKUP_DEST ]] \
    || die "Secure destination missing; run 50-secure-backup-target.sh first: $BACKUP_DEST"
dest_mount=$(findmnt -n -o TARGET -T "$BACKUP_DEST")
[[ $(realpath -e -- "$dest_mount") == $(realpath -e -- "$BACKUP_MOUNT") ]] \
    || die 'BACKUP_DEST is not on the expected SSHFS mount.'
[[ $(stat -c '%u:%a' -- "$BACKUP_DEST") == 0:700 ]] \
    || die 'BACKUP_DEST must be root-owned mode 0700.'
mount_options=",$(findmnt -n -o OPTIONS --mountpoint "$BACKUP_MOUNT"),"
[[ $mount_options != *,allow_other,* ]] || die 'Unsafe SSHFS option allow_other is active.'
[[ $mount_options == *,default_permissions,* ]] || die 'SSHFS option default_permissions is required.'
[[ $mount_options == *,umask=0077,* ]] || die 'SSHFS option umask=0077 is required.'

install -d -m 0700 -- "$LOCAL_STAGE_ROOT"
[[ ! -L $LOCAL_STAGE_ROOT ]] || die 'LOCAL_STAGE_ROOT must not be a symlink.'
case $(findmnt -n -o FSTYPE -T "$LOCAL_STAGE_ROOT") in
    fuse*|sshfs) die 'LOCAL_STAGE_ROOT must be local storage, not FUSE/SSHFS.' ;;
esac

recipient_test_dir=$(mktemp -d -p "$LOCAL_STAGE_ROOT" recipient-test.XXXXXX)
trap 'rm -rf -- "$recipient_test_dir"' EXIT
: >"$recipient_test_dir/plain"
age -R "$AGE_RECIPIENT_FILE" -o "$recipient_test_dir/encrypted" "$recipient_test_dir/plain" >/dev/null 2>&1 \
    || die 'The age recipient file could not encrypt a test payload.'
rm -rf -- "$recipient_test_dir"
trap - EXIT

declare -A service_cid=()
declare -A volume_name=()
declare -A volume_path=()
declare -A volume_seen=()
declare -A originally_running=()
declare -A original_service=()
original_running_cids=()
downtime_active=0
stage=''
remote_partial=''
remote_sidecar_partial=''
remote_final=''
remote_sidecar_final=''
remote_final_created=0
remote_sidecar_created=0

resolve_one_service() {
    local service=${1:?service required}
    local -a matches=()
    mapfile -t matches < <(
        docker ps -a \
            --filter "label=com.docker.compose.project=$compose_project" \
            --filter "label=com.docker.compose.service=$service" \
            --format '{{.ID}}'
    )
    ((${#matches[@]} == 1)) \
        || die "Expected exactly one '$service' container in project '$compose_project'; found ${#matches[@]}."
    service_cid[$service]=${matches[0]}
}

resolve_volume_mount() {
    local logical=${1:?logical name required}
    local service=${2:?service required}
    local destination=${3:?destination required}
    local cid=${service_cid[$service]:-}
    local -a matches=()
    local name driver mountpoint docker_root_real mountpoint_real

    [[ -n $cid ]] || die "Service '$service' was not resolved before volume '$logical'."
    mapfile -t matches < <(
        docker inspect --format \
            "{{range .Mounts}}{{if and (eq .Type \"volume\") (eq .Destination \"$destination\")}}{{println .Name}}{{end}}{{end}}" \
            "$cid" | awk 'NF'
    )
    ((${#matches[@]} == 1)) \
        || die "Expected one named volume on $service:$destination; found ${#matches[@]}."
    name=${matches[0]}
    [[ -z ${volume_seen[$name]:-} ]] || die "Volume '$name' resolved for more than one logical payload."
    volume_seen[$name]=1

    driver=$(docker volume inspect --format '{{.Driver}}' "$name")
    [[ $driver == local ]] || die "Volume '$name' uses driver '$driver'; host-mount archiving is refused."
    mountpoint=$(docker volume inspect --format '{{.Mountpoint}}' "$name")
    [[ -d $mountpoint && ! -L $mountpoint ]] || die "Unsafe or missing Docker mountpoint for '$logical'."
    mountpoint_real=$(realpath -e -- "$mountpoint")
    docker_root_real=$(realpath -e -- "$(docker info --format '{{.DockerRootDir}}')")
    [[ $mountpoint_real == "${docker_root_real%/}/volumes/"* ]] \
        || die "Volume '$logical' is outside Docker's local volume directory."

    volume_name[$logical]=$name
    volume_path[$logical]=$mountpoint_real
}

container_is_running() {
    [[ $(docker inspect --format '{{.State.Running}}' "$1" 2>/dev/null) == true ]]
}

wait_for_redis() {
    local cid=${service_cid[redis]}
    local attempt
    for attempt in {1..60}; do
        docker exec "$cid" redis-cli ping 2>/dev/null | grep -qx PONG && return 0
        sleep 1
    done
    return 1
}

wait_for_elasticsearch() {
    local cid=${service_cid[elasticsearch]}
    local attempt
    for attempt in {1..90}; do
        docker exec "$cid" sh -c \
            'curl -fsS "http://127.0.0.1:9200/_cluster/health?wait_for_status=yellow&timeout=1s" >/dev/null' \
            2>/dev/null && return 0
        sleep 1
    done
    return 1
}

restore_original_services() {
    local cid service attempt all_ready health_status failed=0
    ((downtime_active)) || return 0
    log 'Restoring the service set that was running before the snapshot.'

    for service in redis elasticsearch; do
        cid=${service_cid[$service]:-}
        [[ -n $cid && -n ${originally_running[$cid]:-} ]] || continue
        if ! container_is_running "$cid"; then
            docker start "$cid" >/dev/null || failed=1
        fi
    done
    wait_for_redis || failed=1
    wait_for_elasticsearch || failed=1

    for cid in "${original_running_cids[@]}"; do
        service=${original_service[$cid]}
        case $service in mariadb|redis|elasticsearch) continue ;; esac
        if ! container_is_running "$cid"; then
            docker start "$cid" >/dev/null || failed=1
        fi
    done
    for attempt in {1..120}; do
        all_ready=1
        for cid in "${original_running_cids[@]}"; do
            if ! container_is_running "$cid"; then all_ready=0; continue; fi
            health_status=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$cid")
            case $health_status in healthy|none) ;; *) all_ready=0 ;; esac
        done
        if ((all_ready)) && docker exec "${service_cid[app]}" php bin/oci health >/dev/null 2>&1; then
            failed=0
            break
        fi
        failed=1
        sleep 1
    done
    if ((failed)); then downtime_active=1; else downtime_active=0; fi
    return "$failed"
}

cleanup() {
    local rc=$?
    trap - EXIT
    trap '' INT TERM
    set +e
    if [[ -n $stage && $stage == "${LOCAL_STAGE_ROOT%/}/"backup.* ]]; then
        rm -rf -- "$stage"
    fi
    if ((downtime_active)); then
        restore_original_services || printf 'CRITICAL: one or more original services could not be restarted.\n' >&2
    fi
    [[ -z $remote_partial ]] || rm -f -- "$remote_partial"
    [[ -z $remote_sidecar_partial ]] || rm -f -- "$remote_sidecar_partial"
    if ((rc != 0)); then
        ((remote_sidecar_created == 0)) || rm -f -- "$remote_sidecar_final"
        ((remote_final_created == 0)) || rm -f -- "$remote_final"
    fi
    exit "$rc"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

log 'Resolving the active Coolify Compose project from the live app service.'
mapfile -t app_candidates < <(
    docker ps \
        --filter "label=com.docker.compose.project=$STACK_UUID" \
        --filter 'label=com.docker.compose.service=app' \
        --format '{{.ID}}'
)
((${#app_candidates[@]} == 1)) \
    || die "Expected exactly one running app container labeled for project '$STACK_UUID'; found ${#app_candidates[@]}."
app_cid=${app_candidates[0]}
compose_project=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$app_cid")
[[ -n $compose_project && $compose_project != '<no value>' ]] || die 'The app container has no Compose project label.'
[[ $compose_project =~ ^[A-Za-z0-9_.-]+$ ]] || die 'The Compose project label contains unsafe characters.'

for service in app mariadb redis elasticsearch www wix; do
    resolve_one_service "$service"
done
[[ ${service_cid[app]} == "$app_cid" ]] || die 'The labeled app container does not match the live stack candidate.'
for service in app mariadb redis elasticsearch www wix; do
    container_is_running "${service_cid[$service]}" || die "Core service '$service' is not running; refusing a degraded backup."
done

resolve_volume_mount app-public app /var/www/html/public
resolve_volume_mount app-var app /var/www/html/var
resolve_volume_mount www-blog www /usr/share/nginx/html/blog
resolve_volume_mount redis redis /data
resolve_volume_mount elasticsearch elasticsearch /usr/share/elasticsearch/data
resolve_volume_mount wix wix /app/data

mapfile -t original_running_cids < <(
    docker ps --filter "label=com.docker.compose.project=$compose_project" --format '{{.ID}}' | sort
)
((${#original_running_cids[@]} > 0)) || die 'No running containers were found in the resolved Compose project.'
for cid in "${original_running_cids[@]}"; do
    service=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$cid")
    [[ -n $service && $service != '<no value>' ]] || die "Project container '$cid' has no Compose service label."
    originally_running[$cid]=1
    original_service[$cid]=$service
done

estimated_source_bytes=0
for logical in app-public app-var www-blog redis elasticsearch wix; do
    source_bytes=$(du -sx --block-size=1 "${volume_path[$logical]}" | awk '{print $1}')
    [[ $source_bytes =~ ^[0-9]+$ ]] || die "Could not estimate local size for $logical."
    ((estimated_source_bytes += source_bytes))
done
db_source_bytes=$(docker exec "${service_cid[mariadb]}" du -sx -B1 /var/lib/mysql | awk '{print $1}')
[[ $db_source_bytes =~ ^[0-9]+$ ]] || die 'Could not estimate MariaDB source size.'
((estimated_source_bytes += db_source_bytes))
local_available=$(df -B1 --output=avail "$LOCAL_STAGE_ROOT" | awk 'NR==2 {print $1}')
required_local_bytes=$((estimated_source_bytes * 2 + 1073741824))
[[ $local_available =~ ^[0-9]+$ && $local_available -gt $required_local_bytes ]] \
    || die "Local staging needs at least $required_local_bytes bytes free before downtime."

image_ref_for_container() {
    local cid=${1:?container required}
    local image_id ref
    image_id=$(docker inspect --format '{{.Image}}' "$cid")
    ref=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$image_id" | head -n 1)
    [[ -n $ref ]] || ref=$(docker inspect --format '{{.Config.Image}}' "$cid")
    [[ $ref =~ ^[A-Za-z0-9][A-Za-z0-9._/@:+-]*$ ]] || die 'A container image reference contains unsafe characters.'
    printf '%s\n' "$ref"
}

mariadb_image=$(image_ref_for_container "${service_cid[mariadb]}")
redis_image=$(image_ref_for_container "${service_cid[redis]}")
elasticsearch_image=$(image_ref_for_container "${service_cid[elasticsearch]}")

stamp=$(date -u +%Y%m%dT%H%M%SZ)
stage=$(mktemp -d -p "$LOCAL_STAGE_ROOT" "backup.${stamp}.XXXXXX")
install -d -m 0700 "$stage/payload/volumes"

log 'Entering the short consistent-snapshot window.'
downtime_started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
downtime_active=1
phase_one=()
for cid in "${original_running_cids[@]}"; do
    case ${original_service[$cid]} in mariadb|redis|elasticsearch) continue ;; esac
    phase_one+=("$cid")
done
if ((${#phase_one[@]})); then
    docker stop -t "$STOP_TIMEOUT" "${phase_one[@]}" >/dev/null
fi

wix_json_files=$(find "${volume_path[wix]}" -xdev -type f -name '*.json' -printf '1\n' | wc -l)
[[ $wix_json_files =~ ^[0-9]+$ ]] || die 'Could not record the Wix JSON-file baseline.'

log 'Flushing Redis and Elasticsearch after application writers stopped.'
docker exec "${service_cid[redis]}" redis-cli SAVE >/dev/null \
    || die 'Redis SAVE failed; services will be restored by the exit trap.'
docker exec "${service_cid[elasticsearch]}" sh -c \
    'curl -fsS -X POST "http://127.0.0.1:9200/_flush?wait_if_ongoing=true" >/dev/null' \
    || die 'Elasticsearch flush failed; services will be restored by the exit trap.'
elasticsearch_user_indices=$(docker exec "${service_cid[elasticsearch]}" sh -c \
    "curl -fsS 'http://127.0.0.1:9200/_cat/indices?h=index' | awk '\$1 !~ /^\\./ { count++ } END { print count + 0 }'")
[[ $elasticsearch_user_indices =~ ^[0-9]+$ ]] \
    || die 'Could not record the Elasticsearch user-index baseline.'
docker stop -t "$STOP_TIMEOUT" "${service_cid[redis]}" "${service_cid[elasticsearch]}" >/dev/null

log 'Creating a logical MariaDB dump without putting passwords in process arguments or logs.'
mariadb_tables=$(docker exec "${service_cid[mariadb]}" sh -eu -c '
    : "${MARIADB_ROOT_PASSWORD:?}" "${MARIADB_DATABASE:?}"
    option_file=$(mktemp)
    trap '\''rm -f -- "$option_file"'\'' EXIT INT TERM
    chmod 0600 "$option_file"
    printf "[client]\nuser=root\npassword=%s\n" "$MARIADB_ROOT_PASSWORD" >"$option_file"
    mariadb --defaults-extra-file="$option_file" --batch --skip-column-names \
        "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"
')
[[ $mariadb_tables =~ ^[0-9]+$ && $mariadb_tables -gt 0 ]] \
    || die 'Could not record a non-empty MariaDB table baseline.'
docker exec -i "${service_cid[mariadb]}" sh -eu -c '
    : "${MARIADB_ROOT_PASSWORD:?}" "${MARIADB_DATABASE:?}"
    option_file=$(mktemp)
    trap '\''rm -f -- "$option_file"'\'' EXIT INT TERM
    chmod 0600 "$option_file"
    printf "[client]\nuser=root\npassword=%s\n" "$MARIADB_ROOT_PASSWORD" >"$option_file"
    mariadb-dump --defaults-extra-file="$option_file" \
        --single-transaction --quick --routines --events --triggers --hex-blob \
        --default-character-set=utf8mb4 --no-tablespaces "$MARIADB_DATABASE"
' | gzip -1 >"$stage/payload/database.sql.gz"
gzip -t "$stage/payload/database.sql.gz"
[[ $(stat -c '%s' "$stage/payload/database.sql.gz") -gt 1000 ]] || die 'The compressed database dump is unexpectedly small.'

archive_volume() {
    local logical=${1:?logical volume required}
    local source=${volume_path[$logical]}
    local output="$stage/payload/volumes/${logical}.tar.gz"
    log "Archiving stopped volume: $logical"
    tar --numeric-owner --acls --xattrs --one-file-system -C "$source" -cpf - . \
        | gzip -1 >"$output.partial"
    gzip -t "$output.partial"
    mv -- "$output.partial" "$output"
}

for logical in app-public app-var www-blog redis elasticsearch wix; do
    archive_volume "$logical"
done

downtime_ended_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
restore_original_services || die 'Snapshot succeeded, but the original service set did not restart cleanly.'
log 'The consistent-snapshot window is closed; encryption continues with services running.'

cat >"$stage/manifest.txt" <<MANIFEST
backup_format=3
created_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
stack_uuid=$STACK_UUID
compose_project=$compose_project
downtime_started_at=$downtime_started_at
downtime_ended_at=$downtime_ended_at
mariadb_image=$mariadb_image
redis_image=$redis_image
elasticsearch_image=$elasticsearch_image
wix_json_files=$wix_json_files
mariadb_tables=$mariadb_tables
elasticsearch_user_indices=$elasticsearch_user_indices
payload=database.sql.gz,app-public,app-var,www-blog,redis,elasticsearch,wix
MANIFEST

(
    cd "$stage"
    find payload -type f -print0 | sort -z | xargs -0 sha256sum >SHA256SUMS
)

archive_name="conzent-full-${stamp}.tar.age"
local_cipher="$stage/$archive_name"
log 'Encrypting the complete backup locally with the public age recipient.'
tar -C "$stage" -cf - manifest.txt SHA256SUMS payload \
    | age -R "$AGE_RECIPIENT_FILE" -o "$local_cipher"
[[ $(head -n 1 "$local_cipher") == age-encryption.org/v1 ]] || die 'The local ciphertext has no age v1 header.'
local_hash=$(sha256sum "$local_cipher" | awk '{print $1}')
local_size=$(stat -c '%s' "$local_cipher")
[[ $local_size -gt 1024 ]] || die 'The encrypted backup is unexpectedly small.'

remote_available=$(df -B1 --output=avail "$BACKUP_DEST" | awk 'NR==2 {print $1}')
[[ $remote_available =~ ^[0-9]+$ && $remote_available -gt $local_size ]] \
    || die 'The SSHFS target does not report enough free space for the ciphertext.'

remote_final="$BACKUP_DEST/$archive_name"
remote_sidecar_final="$remote_final.sha256"
remote_partial="$remote_final.partial"
remote_sidecar_partial="$remote_sidecar_final.partial"
[[ ! -e $remote_final && ! -e $remote_sidecar_final && ! -e $remote_partial && ! -e $remote_sidecar_partial ]] \
    || die 'A destination artifact with this timestamp already exists.'

log 'Copying ciphertext (never plaintext) to the SSHFS destination.'
install -m 0600 -- "$local_cipher" "$remote_partial"
[[ $(sha256sum "$remote_partial" | awk '{print $1}') == "$local_hash" ]] \
    || die 'Ciphertext hash changed while copying to SSHFS.'
sync -f "$remote_partial"
mv -- "$remote_partial" "$remote_final"
remote_partial=''
remote_final_created=1

local_sidecar="$stage/$archive_name.sha256"
printf '%s  %s\n' "$local_hash" "$archive_name" >"$local_sidecar"
install -m 0600 -- "$local_sidecar" "$remote_sidecar_partial"
sync -f "$remote_sidecar_partial"
mv -- "$remote_sidecar_partial" "$remote_sidecar_final"
remote_sidecar_partial=''
remote_sidecar_created=1

[[ $(stat -c '%a' "$remote_final") == 600 && $(stat -c '%a' "$remote_sidecar_final") == 600 ]] \
    || die 'Remote artifact permissions are not mode 0600.'

log "Encrypted full backup complete: $remote_final"
printf 'Ciphertext bytes: %s\n' "$local_size"
printf 'Run 52-verify-encrypted-backup.sh against this archive, then run the isolated restore drill off-host.\n'
