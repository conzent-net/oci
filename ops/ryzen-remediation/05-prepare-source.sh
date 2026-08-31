#!/usr/bin/env bash

set -Eeuo pipefail
export LC_ALL=C
umask 077

repo_root=$(git rev-parse --show-toplevel 2>/dev/null) || {
    echo 'Run this from the conzent-app Git checkout.' >&2
    exit 1
}
cd "$repo_root"

[[ -f docker-compose.coolify.yaml && -f docker/php/Dockerfile ]] || {
    echo 'This does not look like the conzent-app repository.' >&2
    exit 1
}

origin_url=$(git remote get-url origin 2>/dev/null || true)
case $origin_url in
    *sitepointsystems/conzent-app*) ;;
    *) echo 'Unexpected origin URL (suppressed in case it contains credentials).' >&2; exit 1 ;;
esac

# .gitignore alone does not remove an already tracked file. The local Claude
# settings file contained live credentials, so remove its working copy too.
git rm -f --ignore-unmatch -- .claude/settings.local.json
rm -f -- .claude/settings.local.json
git rm --cached --ignore-unmatch -- .env.production plugins/getconzent_wix/.envyx

required_files=(
    .dockerignore
    .gitignore
    deploy.sh
    docker-compose.coolify.yaml
    legacy/app/admin/autologin.php
    legacy/app/api/v1/geo_ip.php
    legacy/app/classes/freshdesk.php
    legacy/app/custom_functions.php
    legacy/app/classes/tagmanagerAPI.php
    legacy/app/cron/freshdesk.php
    scripts/publish.php
    scripts/deploy-matomo-plugin.sh
    plugins/getconzent_wix/.dockerignore
)
for path in "${required_files[@]}"; do
    [[ -f $path ]] || { echo "Missing remediation file: $path" >&2; exit 1; }
done

for path in .env .env.production .claude/settings.local.json plugins/getconzent_wix/.envyx; do
    if git ls-files --error-unmatch -- "$path" >/dev/null 2>&1; then
        echo "Still tracked: $path" >&2
        exit 1
    fi
done

mapfile -t suspected_files < <(
    git grep -IlE \
        '(sk-or-v1-[A-Za-z0-9_-]{40,}|AKIA[0-9A-Z]{16}|GOCSPX-[A-Za-z0-9_-]{20,}|https://[^[:space:]/:]+:[^@[:space:]]+@|CLOUDFLARE_API_TOKEN=[A-Za-z0-9_-]{30,}|WIX_APP_SECRET=[0-9a-fA-F]{8}-[0-9a-fA-F-]{20,}|MAIL_PASSWORD=[A-Za-z0-9+/]{25,}|key=[0-9a-fA-F]{8}-[0-9a-fA-F-]{20,})' \
        -- ':!*.example' ':!*.dist' ':!ops/ryzen-remediation/*' || true
)
if ((${#suspected_files[@]})); then
    printf 'High-confidence credential patterns remain in tracked files (values suppressed):\n' >&2
    printf '  %s\n' "${suspected_files[@]}" >&2
    exit 1
fi

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
    APP_SECRET=config-check \
    DATABASE_URL='mysql://oci:config-check@mariadb:3306/oci?charset=utf8mb4' \
    DB_PASSWORD=config-check \
    DB_ROOT_PASSWORD=config-check \
    SCANNER_API_KEY=config-check \
        docker compose -p brrmsbi50m4q02lqx35juhlr -f docker-compose.coolify.yaml config --quiet
else
    echo 'WARNING: Docker Compose is unavailable; run the Compose validation in CI before pushing.' >&2
fi

git diff --check -- .dockerignore .gitignore deploy.sh docker-compose.coolify.yaml \
    legacy/app/admin/autologin.php legacy/app/api/v1/geo_ip.php \
    legacy/app/classes/freshdesk.php legacy/app/classes/tagmanagerAPI.php \
    legacy/app/cron/freshdesk.php legacy/app/custom_functions.php \
    scripts/publish.php \
    scripts/deploy-matomo-plugin.sh \
    plugins/getconzent_wix/.dockerignore
git diff --cached --check

printf '\nSource hygiene checks passed. Review and stage only these paths:\n'
printf '  git add .dockerignore .gitignore deploy.sh docker-compose.coolify.yaml legacy/app/admin/autologin.php legacy/app/api/v1/geo_ip.php legacy/app/classes/freshdesk.php legacy/app/classes/tagmanagerAPI.php legacy/app/cron/freshdesk.php legacy/app/custom_functions.php scripts/publish.php scripts/deploy-matomo-plugin.sh plugins/getconzent_wix/.dockerignore ops/ryzen-remediation\n'
printf '  git diff --cached --stat\n'
printf 'Do not run an unrestricted git diff on the staged secret-file deletions; it would print old values. Use the redacted review commands in ops/ryzen-remediation/README.md.\n'
printf 'Then use the README commit --only command so pre-existing staged work is excluded. Do not use git add -A in this dirty checkout.\n'
