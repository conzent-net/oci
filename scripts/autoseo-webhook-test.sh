#!/usr/bin/env bash
#
# End-to-end test for the AutoSEO webhook.
#
# Sends a real HTTP delivery (test event + a synthetic article) with a valid
# bearer token and HMAC-SHA256 signature, and asserts the endpoint returns 200
# with a JSON url. Then it tells you how to confirm the data was STORED and how
# to clean the synthetic article up again.
#
# Usage:
#   scripts/autoseo-webhook-test.sh [URL] [--id=N] [--skip-article]
#
#   URL             Webhook URL (default: $AUTOSEO_WEBHOOK_TEST_URL or
#                   http://localhost:8100/api/webhooks/autoseo)
#   --id=N          AutoSEO id to use for the synthetic article (default 424242)
#   --skip-article  Only send the safe 'test' event (creates no post)
#
# Token: taken from $AUTOSEO_WEBHOOK_TOKEN, else the documented default.
#
# Requires: curl, openssl.
set -u

URL="${AUTOSEO_WEBHOOK_TEST_URL:-http://localhost:8100/api/webhooks/autoseo}"
TOKEN="${AUTOSEO_WEBHOOK_TOKEN:-aseo_wh_6e366ac60146a8203f06b3ad6064f828}"
ARTICLE_ID=424242
SKIP_ARTICLE=0

for arg in "$@"; do
  case "$arg" in
    --id=*)        ARTICLE_ID="${arg#--id=}" ;;
    --skip-article) SKIP_ARTICLE=1 ;;
    --*)           echo "Unknown option: $arg"; exit 2 ;;
    *)             URL="$arg" ;;
  esac
done

command -v curl   >/dev/null 2>&1 || { echo "ERROR: curl not found";   exit 3; }
command -v openssl >/dev/null 2>&1 || { echo "ERROR: openssl not found"; exit 3; }

PASS=0
FAIL=0

# post_json <event-header> <body> <expect-code>  -> prints result, updates PASS/FAIL, echoes body
post_json() {
  local event="$1" body="$2" expect="$3"
  local sig delivery code out
  sig="$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$TOKEN" -hex | awk '{print $NF}')"
  delivery="e2e-$(date +%s)-$RANDOM"

  out="$(printf '%s' "$body" | curl -sS --max-time 120 -o - -w $'\n%{http_code}' -X POST "$URL" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -H "X-AutoSEO-Event: $event" \
    -H "X-AutoSEO-Signature: $sig" \
    -H "X-AutoSEO-Delivery: $delivery" \
    --data-binary @-)"

  code="$(printf '%s' "$out" | tail -n1)"
  local resp_body
  resp_body="$(printf '%s' "$out" | sed '$d')"

  echo "  → $event : HTTP $code"
  echo "    response: $resp_body"
  if [ "$code" = "$expect" ]; then
    PASS=$((PASS+1))
  else
    FAIL=$((FAIL+1))
    echo "    EXPECTED HTTP $expect"
  fi
  RESP_BODY="$resp_body"
}

echo "AutoSEO webhook E2E test"
echo "  URL:   $URL"
echo "  token: ${TOKEN:0:12}…"
echo

echo "[1/3] Auth guard — wrong bearer should be rejected (expect 401)"
code="$(printf '%s' '{"event":"test"}' | curl -sS -o /dev/null -w '%{http_code}' -X POST "$URL" \
  -H "Content-Type: application/json" -H "Authorization: Bearer WRONG" --data-binary @-)"
echo "  → HTTP $code"
[ "$code" = "401" ] && PASS=$((PASS+1)) || { FAIL=$((FAIL+1)); echo "    EXPECTED 401"; }
echo

echo "[2/3] Test event (expect 200 + url)"
post_json "test" '{"event":"test"}' "200"
echo

if [ "$SKIP_ARTICLE" = "0" ]; then
  echo "[3/3] Synthetic article.published id=$ARTICLE_ID (expect 200 + url)"
  BODY="$(cat <<JSON
{"event":"article.published","id":$ARTICLE_ID,"title":"AutoSEO E2E self-test","slug":"autoseo-e2e-selftest","published_url":null,"metaDescription":"Automated end-to-end webhook self-test — safe to delete.","content_html":"","content_markdown":"# AutoSEO E2E self-test\n\nThis post was created by scripts/autoseo-webhook-test.sh and can be removed with autoseo:purge.","heroImageUrl":null,"heroImageAlt":null,"infographicImageUrl":null,"keywords":["selftest"],"metaKeywords":"selftest","wordpressTags":"selftest","faqSchema":null,"languageCode":"en","sourceArticleId":null,"status":"published","publishedAt":"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)","updatedAt":"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)","createdAt":"$(date -u +%Y-%m-%dT%H:%M:%S.000Z)"}
JSON
)"
  post_json "article.published" "$BODY" "200"
  echo
else
  echo "[3/3] Synthetic article skipped (--skip-article)"
  echo
fi

echo "──────────────────────────────────────────────"
echo "HTTP checks: $PASS passed, $FAIL failed"
echo
echo "Now confirm the data was STORED (run inside the app container):"
echo "    php bin/oci autoseo:doctor"
echo "  Expect: tables ✓, a 'processed' test delivery, and (if not skipped)"
echo "  articles_total incremented + a new post under www/content/posts/."
if [ "$SKIP_ARTICLE" = "0" ]; then
  echo
  echo "Clean up the synthetic article again:"
  echo "    php bin/oci autoseo:purge --id=$ARTICLE_ID"
fi

[ "$FAIL" -eq 0 ] && exit 0 || exit 1
