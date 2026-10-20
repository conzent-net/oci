#!/bin/sh
# Poll the app (:8100) and testsite (:8106) until both answer, or fail after
# ~120s. Used by the e2e workflow after `docker compose up`.
set -eu

wait_for() {
  url="$1"
  name="$2"
  i=0
  while [ $i -lt 60 ]; do
    if curl -sf -o /dev/null --max-time 3 "$url"; then
      echo "✓ ${name} is up (${url})"
      return 0
    fi
    i=$((i + 1))
    sleep 2
  done
  echo "✗ ${name} never became healthy (${url})" >&2
  return 1
}

wait_for "http://localhost:8100/health" "app"
wait_for "http://localhost:8106/index.html" "testsite"
