#!/usr/bin/env bash
set -euo pipefail

# Deploy Conzent CMP Matomo Plugin
# Copies the plugin from conzent-app to the public conzent-matomo-cmp repo and pushes.
#
# Usage:
#   bash scripts/deploy-matomo-plugin.sh              # deploy current version
#   bash scripts/deploy-matomo-plugin.sh 1.2.0        # deploy and tag as v1.2.0

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
PLUGIN_SRC="$PROJECT_DIR/plugins/conzent_matomo"
DEPLOY_DIR="/tmp/conzent-matomo-deploy"
VERSION="${1:-}"

# Auth: use MATOMO_DEPLOY_TOKEN env var or prompt
if [ -n "${MATOMO_DEPLOY_TOKEN:-}" ]; then
    REPO_URL="https://x-access-token:${MATOMO_DEPLOY_TOKEN}@github.com/conzent-net/conzent-matomo-cmp.git"
else
    echo "WARNING: MATOMO_DEPLOY_TOKEN not set. Git will prompt for credentials."
    echo "Set it with: export MATOMO_DEPLOY_TOKEN=ghp_xxxxx"
    REPO_URL="https://github.com/conzent-net/conzent-matomo-cmp.git"
fi

# Validate source exists
if [ ! -d "$PLUGIN_SRC" ]; then
    echo "ERROR: Plugin source not found at $PLUGIN_SRC"
    exit 1
fi

# Read version from plugin.json if not provided
if [ -z "$VERSION" ]; then
    VERSION=$(grep -o '"version"[[:space:]]*:[[:space:]]*"[^"]*"' "$PLUGIN_SRC/plugin.json" | grep -o '[0-9][0-9.]*')
    echo "Version from plugin.json: $VERSION"
fi

echo "=== Deploying Conzent CMP Matomo Plugin v$VERSION ==="
echo ""

# Clean up any previous deploy
rm -rf "$DEPLOY_DIR"

# Clone the public repo
echo ">>> Cloning $REPO_URL..."
git clone --quiet "$REPO_URL" "$DEPLOY_DIR"
cd "$DEPLOY_DIR"

# Remove old files (except .git)
find . -maxdepth 1 -not -name '.git' -not -name '.' -exec rm -rf {} +

# Copy plugin files
echo ">>> Copying plugin files..."
cp -r "$PLUGIN_SRC"/* .

# Check for changes
if git diff --quiet && git diff --cached --quiet && [ -z "$(git ls-files --others --exclude-standard)" ]; then
    echo "No changes to deploy."
    rm -rf "$DEPLOY_DIR"
    exit 0
fi

# Stage and commit
git add -A
echo ""
echo ">>> Changes:"
git diff --cached --stat
echo ""

git commit -m "Release v$VERSION"

# Tag if version provided
if [ -n "$VERSION" ]; then
    # Remove existing tag if it exists (for re-deploys)
    git tag -d "$VERSION" 2>/dev/null || true
    git push origin ":refs/tags/$VERSION" 2>/dev/null || true

    git tag "$VERSION"
    echo ">>> Tagged: $VERSION"
fi

# Push
echo ">>> Pushing to $REPO_URL..."
git push origin main
if [ -n "$VERSION" ]; then
    git push origin "$VERSION"
fi

# Clean up
rm -rf "$DEPLOY_DIR"

echo ""
echo "=== Deployed Conzent CMP Matomo Plugin v$VERSION ==="
echo "https://github.com/conzent-net/conzent-matomo-cmp"
