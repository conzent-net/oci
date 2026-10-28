#!/usr/bin/env bash
# Build the WordPress plugin zip for a wordpress.org release.
#
# Checks that the three version stamps agree (plugin header, the PHP constant,
# readme.txt's Stable tag), that readme.txt carries a changelog entry for that
# version, that both PHP files parse, then stages a copy minus .distignore and
# zips it to plugins/getconzent_wp.zip. The wordpress.org SVN upload stays a
# manual step (no credentials in the repository).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/plugins/getconzent_wp"
OUT="$ROOT/plugins/getconzent_wp.zip"

HDR=$(grep -m1 -oE '^\s*\*\s*Version:\s*[0-9.]+' "$SRC/conzent.php" | grep -oE '[0-9.]+$')
CONST=$(grep -m1 -oE "GETCONZENT_CMP_PLUGIN_VERSION', '[0-9.]+'" "$SRC/conzent.php" | grep -oE '[0-9.]+')
STABLE=$(grep -m1 -oE '^Stable tag:\s*[0-9.]+' "$SRC/readme.txt" | grep -oE '[0-9.]+$')

if [ "$HDR" != "$CONST" ] || [ "$HDR" != "$STABLE" ]; then
  echo "Version mismatch: header $HDR, constant $CONST, stable tag $STABLE" >&2
  exit 1
fi
if ! grep -q "= $HDR =" "$SRC/readme.txt"; then
  echo "readme.txt has no changelog entry for $HDR" >&2
  exit 1
fi

php -l "$SRC/conzent.php" >/dev/null
php -l "$SRC/uninstall.php" >/dev/null

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/getconzent_wp"
cp -r "$SRC"/. "$STAGE/getconzent_wp/"
grep -vE '^\s*(#|$)' "$SRC/.distignore" | while read -r path; do
  rm -rf "$STAGE/getconzent_wp/$path"
done

rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
  (cd "$STAGE" && zip -qr "$OUT" getconzent_wp)
elif command -v 7z >/dev/null 2>&1; then
  (cd "$STAGE" && 7z a -tzip -bso0 "$OUT" getconzent_wp >/dev/null)
else
  # PHP is already required above; its ZipArchive writes forward-slash entries.
  php -r '
    [$stage, $out] = [$argv[1], $argv[2]];
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { fwrite(STDERR, "cannot open $out\n"); exit(1); }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $path => $info) {
        $rel = "getconzent_wp/" . str_replace("\\", "/", substr($path, strlen($stage) + 1));
        $info->isDir() ? $zip->addEmptyDir($rel) : $zip->addFile($path, $rel);
    }
    $zip->close();
  ' "$STAGE/getconzent_wp" "$OUT"
fi

echo "Contents of $OUT:"
php -r '$z = new ZipArchive(); $z->open($argv[1]); for ($i = 0; $i < $z->numFiles; $i++) { $s = $z->statIndex($i); printf("%9d  %s\n", $s["size"], $s["name"]); }' "$OUT"
echo "Built $OUT (version $HDR)"
