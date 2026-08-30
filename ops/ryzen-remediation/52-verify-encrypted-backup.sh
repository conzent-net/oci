#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

BACKUP_MOUNT=${BACKUP_MOUNT:-/mnt/storagebox}
BACKUP_DEST=${BACKUP_DEST:-${BACKUP_MOUNT%/}/backups/conzent-encrypted}
EXPECTED_HOST=${EXPECTED_HOST:-ryzen-prod}

die() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

[[ ${EUID:-$(id -u)} -eq 0 ]] || die 'Run this script as root.'
[[ $(hostname -s) == "$EXPECTED_HOST" ]] || die "This script is pinned to $EXPECTED_HOST."
(($# <= 1)) || die 'Usage: 52-verify-encrypted-backup.sh [ARCHIVE.tar.age]'
for command_name in awk basename findmnt head mountpoint realpath sha256sum stat wc; do
    command -v "$command_name" >/dev/null 2>&1 || die "Required command not found: $command_name"
done

[[ -d $BACKUP_MOUNT && ! -L $BACKUP_MOUNT ]] || die 'The expected SSHFS mount is unavailable.'
mountpoint -q -- "$BACKUP_MOUNT" || die "$BACKUP_MOUNT is not an exact mount point."
[[ $(findmnt -n -o FSTYPE --mountpoint "$BACKUP_MOUNT") == fuse.sshfs ]] \
    || die 'The backup mount is not fuse.sshfs.'
[[ -d $BACKUP_DEST && ! -L $BACKUP_DEST ]] || die 'The encrypted backup directory is missing or unsafe.'
dest_real=$(realpath -e -- "$BACKUP_DEST")
dest_mount=$(findmnt -n -o TARGET -T "$dest_real")
[[ $(realpath -e -- "$dest_mount") == $(realpath -e -- "$BACKUP_MOUNT") ]] \
    || die 'BACKUP_DEST is not on the expected SSHFS mount.'
[[ $(stat -c '%u:%a' -- "$dest_real") == 0:700 ]] || die 'BACKUP_DEST must be root-owned mode 0700.'

if (($# == 1)); then
    archive=$1
else
    shopt -s nullglob
    candidates=("$dest_real"/conzent-full-*.tar.age)
    shopt -u nullglob
    ((${#candidates[@]})) || die 'No completed encrypted full backup was found.'
    archive=${candidates[0]}
    newest_mtime=$(stat -c '%Y' -- "$archive")
    for candidate in "${candidates[@]:1}"; do
        candidate_mtime=$(stat -c '%Y' -- "$candidate")
        if ((candidate_mtime > newest_mtime)); then
            archive=$candidate
            newest_mtime=$candidate_mtime
        fi
    done
fi

[[ -f $archive && ! -L $archive ]] || die 'The selected archive is missing, not regular, or a symlink.'
archive=$(realpath -e -- "$archive")
[[ $archive == "$dest_real/"* ]] || die 'The archive must be inside BACKUP_DEST.'
[[ $archive == *.tar.age && $archive != *.partial ]] || die 'Only a completed .tar.age artifact can be verified.'
sidecar="$archive.sha256"
[[ -f $sidecar && ! -L $sidecar ]] || die 'The ciphertext checksum sidecar is missing or unsafe.'
[[ $(wc -l <"$sidecar") -eq 1 ]] || die 'The checksum sidecar must contain exactly one line.'
[[ $(stat -c '%u:%a' -- "$archive") == 0:600 ]] || die 'The encrypted archive must be root-owned mode 0600.'
[[ $(stat -c '%u:%a' -- "$sidecar") == 0:600 ]] || die 'The checksum sidecar must be root-owned mode 0600.'
[[ $(stat -c '%s' -- "$archive") -gt 1024 ]] || die 'The encrypted archive is unexpectedly small.'
[[ $(head -n 1 "$archive") == age-encryption.org/v1 ]] || die 'The archive does not have an age v1 header.'

expected_hash=''
recorded_name=''
extra=''
read -r expected_hash recorded_name extra <"$sidecar" || die 'Could not parse the checksum sidecar.'
[[ $expected_hash =~ ^[0-9a-f]{64}$ && -z $extra ]] || die 'The checksum sidecar has an invalid format.'
[[ $recorded_name == "$(basename -- "$archive")" ]] || die 'The checksum sidecar names a different artifact.'
actual_hash=$(sha256sum "$archive" | awk '{print $1}')
[[ $actual_hash == "$expected_hash" ]] || die 'Ciphertext SHA-256 verification failed.'

printf 'Ciphertext verification passed: %s\n' "$archive"
printf 'Bytes: %s\n' "$(stat -c '%s' -- "$archive")"
printf 'This proves storage integrity and age framing, not decryptability. Run 53-restore-drill.sh on an isolated host with the age identity.\n'
