#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

image=${IMAGE:-${1:-}}
[[ -n $image ]] || { echo 'Set IMAGE to the rebuilt Conzent app image ID.' >&2; exit 2; }
for command_name in awk df docker find sed sort stat tar; do
    command -v "$command_name" >/dev/null 2>&1 || { echo "Required command not found: $command_name" >&2; exit 1; }
done
docker image inspect "$image" >/dev/null

work_dir=$(mktemp -d)
trap 'rm -rf -- "$work_dir"' EXIT
image_size=$(docker image inspect "$image" --format '{{.Size}}')
available_bytes=$(df -B1 --output=avail "$work_dir" | awk 'NR==2 {print $1}')
required_bytes=$((image_size * 3 + 536870912))
[[ $available_bytes =~ ^[0-9]+$ && $available_bytes -gt $required_bytes ]] || {
    echo "Need at least $required_bytes bytes free for the layer audit." >&2
    exit 1
}
docker image save -o "$work_dir/image.tar" "$image"
tar -xf "$work_dir/image.tar" -C "$work_dir"

bad=0
while IFS= read -r layer; do
    while IFS= read -r member; do
        normalized=${member#./}
        case $normalized in
            */.env|*/.env.*|*/.envyx|*/.conzent-credentials|*/.claude/settings.local.json|*/.git/*)
                printf 'Forbidden path found in an image layer: %s\n' "$normalized" >&2
                bad=1
                ;;
        esac
    done < <(tar -tf "$layer")
done < <(find "$work_dir" -type f -name layer.tar -print)

mapfile -t secret_env_names < <(
    docker image inspect "$image" --format '{{range .Config.Env}}{{println .}}{{end}}' \
        | sed -nE 's/^([A-Z0-9_]*(PASSWORD|SECRET|TOKEN|API_KEY)[A-Z0-9_]*)=.*/\1/p' \
        | sort -u
)
if ((${#secret_env_names[@]})); then
    printf 'Secret-like variables are embedded in image configuration (values suppressed):\n' >&2
    printf '  %s\n' "${secret_env_names[@]}" >&2
    bad=1
fi

((bad == 0)) || exit 1
echo 'Image-layer hygiene check passed.'
