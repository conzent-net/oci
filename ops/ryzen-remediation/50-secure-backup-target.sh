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
for command_name in chmod findmnt install mountpoint realpath rm setpriv stat; do
    command -v "$command_name" >/dev/null 2>&1 || die "Required command not found: $command_name"
done

[[ $BACKUP_MOUNT == /* && $BACKUP_DEST == /* ]] \
    || die 'BACKUP_MOUNT and BACKUP_DEST must be absolute paths.'
[[ $BACKUP_DEST == "${BACKUP_MOUNT%/}/"* ]] \
    || die 'BACKUP_DEST must be below BACKUP_MOUNT.'
[[ -d $BACKUP_MOUNT && ! -L $BACKUP_MOUNT ]] || die "Mount directory is missing or is a symlink: $BACKUP_MOUNT"
mountpoint -q -- "$BACKUP_MOUNT" || die "$BACKUP_MOUNT is not an exact mount point."

mount_type=$(findmnt -n -o FSTYPE --mountpoint "$BACKUP_MOUNT")
[[ $mount_type == fuse.sshfs ]] || die "$BACKUP_MOUNT is $mount_type, not fuse.sshfs."

mount_target=$(findmnt -n -o TARGET --mountpoint "$BACKUP_MOUNT")
[[ $(realpath -e -- "$mount_target") == $(realpath -e -- "$BACKUP_MOUNT") ]] \
    || die 'The resolved SSHFS mount target does not match BACKUP_MOUNT.'

# Do not print the mount source: it can disclose the Storage Box account name.
mount_options=",$(findmnt -n -o OPTIONS --mountpoint "$BACKUP_MOUNT"),"
problems=()
[[ $mount_options != *,allow_other,* ]] || problems+=('remove allow_other')
[[ $mount_options == *,default_permissions,* ]] || problems+=('add default_permissions')
[[ $mount_options == *,umask=0077,* ]] || problems+=('add umask=0077')
[[ $mount_options == *,nosuid,* ]] || problems+=('add nosuid')
[[ $mount_options == *,nodev,* ]] || problems+=('add nodev')
[[ $mount_options == *,noexec,* ]] || problems+=('add noexec')

install -d -m 0700 -- "$BACKUP_DEST"
chmod 0700 -- "$BACKUP_DEST"
[[ ! -L $BACKUP_DEST ]] || die "Backup destination is a symlink: $BACKUP_DEST"

dest_mount=$(findmnt -n -o TARGET -T "$BACKUP_DEST")
[[ $(realpath -e -- "$dest_mount") == $(realpath -e -- "$BACKUP_MOUNT") ]] \
    || die 'BACKUP_DEST is not stored on the expected SSHFS mount.'

dest_mode=$(stat -c '%a' -- "$BACKUP_DEST")
dest_uid=$(stat -c '%u' -- "$BACKUP_DEST")
[[ $dest_mode == 700 ]] || problems+=("destination mode is $dest_mode, expected 700")
[[ $dest_uid == 0 ]] || problems+=("destination owner UID is $dest_uid, expected 0")

probe="$BACKUP_DEST/.permission-probe-$$"
cleanup() {
    rm -f -- "$probe"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
printf 'permission probe\n' >"$probe"
chmod 0600 -- "$probe"
[[ $(stat -c '%a' -- "$probe") == 600 ]] || problems+=('remote files do not retain mode 600')

if setpriv --reuid=65534 --regid=65534 --clear-groups test -r "$probe" 2>/dev/null; then
    problems+=('UID 65534 can read a root-only probe file')
fi
rm -f -- "$probe"

if ((${#problems[@]})); then
    printf 'The SSHFS backup target is not yet safe:\n' >&2
    for problem in "${problems[@]}"; do
        printf '  - %s\n' "$problem" >&2
    done
    printf '\nManual next step (this script will not unmount or edit fstab):\n' >&2
    printf '  1. Edit only the /etc/fstab entry whose mount point is %s.\n' "$BACKUP_MOUNT" >&2
    printf '  2. Remove allow_other and add: default_permissions,uid=0,gid=0,umask=0077,nosuid,nodev,noexec\n' >&2
    printf '  3. In an approved maintenance window, run exactly:\n' >&2
    printf '       fusermount3 -u -- %q && mount -- %q\n' "$BACKUP_MOUNT" "$BACKUP_MOUNT" >&2
    printf '  4. Re-run this script before running a backup.\n' >&2
    exit 1
fi

printf 'SSHFS target passed: %s (directory mode 0700; write/read isolation probe passed).\n' "$BACKUP_DEST"
printf 'The SSHFS source/account was intentionally not printed.\n'
