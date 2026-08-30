#!/usr/bin/env bash

set -Eeuo pipefail
set +x
export LC_ALL=C
umask 077

template=${1:-ops/ryzen-remediation/60-provider-secrets.conf.example}
destination=${ROTATION_FILE:-/root/conzent-provider-rotation.secret.conf}

[[ ${EUID:-$(id -u)} -eq 0 ]] || { echo 'Run as root on ryzen-prod.' >&2; exit 1; }
[[ $(hostname -s) == ryzen-prod ]] || { echo 'This script is pinned to ryzen-prod.' >&2; exit 1; }
[[ -f $template && ! -L $template ]] || { echo "Template not found: $template" >&2; exit 1; }
[[ $destination == /* ]] || { echo 'ROTATION_FILE must be an absolute path.' >&2; exit 1; }
if [[ -e $destination ]]; then
    echo "Refusing to overwrite existing rotation file: $destination" >&2
    exit 1
fi

install -m 0600 -- "$template" "$destination"
cat <<EOF
Created root-only rotation worksheet:
  $destination

Next steps:
  1. Create replacement credentials at each external provider.
  2. Edit the worksheet locally as root; never paste secrets into shell arguments or chat.
  3. In Coolify, replace the matching variables using its environment-variable UI.
  4. Run 41-coolify-deploy.sh and 42-verify.sh.
  5. Test mail, scanner, Wix, AI/chat, billing and OAuth integrations.
  6. Revoke each old provider credential only after its replacement works.

Do not rotate DB_PASSWORD with this provider worksheet. After the encrypted
backup/restore drill succeeds, use 61-rotate-database-credentials.sh for the
guarded database-account and Coolify cutover.
EOF
