#!/usr/bin/env bash
#
# Marketing-site URL smoke test.
#
# Fetches every canonical page and fails on any non-200, or on any redirect
# chain longer than one hop. Guards against the class of bug where a routing
# or locale change silently makes subpages unreachable while the root keeps
# resolving — the root is special-cased in most routing schemes, so testing
# "/" alone proves nothing.
#
# Trailing-slash URLs must answer 200 with ZERO redirects. A canonical page
# that costs a redirect is a (smaller) bug of the same family.
#
# Usage:
#   scripts/smoke-urls.sh                        # test https://getconzent.com
#   scripts/smoke-urls.sh http://localhost:8102  # test a local build
#

set -uo pipefail

BASE="${1:-https://getconzent.com}"
BASE="${BASE%/}"

# Canonical paths. Every one of these must be reachable directly.
PATHS=(
  /
  /pricing/
  /features/
  /oci/
  /oci/self-host/
  /oci/sponsors/
  /integrations/
  /compliance/gdpr/
  /compliance/iab-tcf/
  /compliance/gcm-v2/
  /docs/
  /help/
  /blog/
  /about/
  /license/
  /da/
  /de/
)

# Slashless forms. These are expected to 301 to the canonical slashed URL in
# exactly one hop — never two, and never via an http:// downgrade.
REDIRECT_PATHS=(
  /pricing
  /features
  /about
)

fail=0

printf '%s\n' "Smoke-testing $BASE"
printf '%s\n' "─────────────────────────────────────────────────────────"

for p in "${PATHS[@]}"; do
  read -r code redirects <<<"$(curl -sS -o /dev/null --max-time 30 \
    -w '%{http_code} %{num_redirects}' -L --max-redirs 5 "$BASE$p" 2>/dev/null)" || {
      printf 'FAIL  %-28s request failed\n' "$p"; fail=1; continue; }

  if [ "$code" != "200" ]; then
    printf 'FAIL  %-28s HTTP %s\n' "$p" "$code"; fail=1
  elif [ "$redirects" != "0" ]; then
    printf 'FAIL  %-28s 200 but via %s redirect(s) — not canonical\n' "$p" "$redirects"; fail=1
  else
    printf 'ok    %-28s 200\n' "$p"
  fi
done

for p in "${REDIRECT_PATHS[@]}"; do
  loc=$(curl -sS -o /dev/null --max-time 30 -w '%{redirect_url}' "$BASE$p" 2>/dev/null)
  read -r code redirects <<<"$(curl -sS -o /dev/null --max-time 30 \
    -w '%{http_code} %{num_redirects}' -L --max-redirs 5 "$BASE$p" 2>/dev/null)"

  if [ "$code" != "200" ]; then
    printf 'FAIL  %-28s HTTP %s after redirect\n' "$p" "$code"; fail=1
  elif [ "${redirects:-0}" -gt 1 ]; then
    printf 'FAIL  %-28s %s hops (expected 1) — via %s\n' "$p" "$redirects" "$loc"; fail=1
  elif [ "$BASE" != "${BASE#https}" ] && [ "$loc" != "${loc#http://}" ]; then
    # https base must never be redirected to a plain-http Location.
    printf 'FAIL  %-28s scheme downgrade to %s\n' "$p" "$loc"; fail=1
  else
    printf 'ok    %-28s 1 hop -> 200\n' "$p"
  fi
done

printf '%s\n' "─────────────────────────────────────────────────────────"
if [ "$fail" -ne 0 ]; then
  printf '%s\n' "SMOKE TEST FAILED"
  exit 1
fi
printf '%s\n' "All URLs reachable."
