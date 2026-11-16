#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DIST="${DIST:-$ROOT/dist/eam-shared}"
BASE_PATH="${BASE_PATH:-/}"
PUBLIC="$DIST/public_html"
APP="$PUBLIC/_app"

case "$BASE_PATH" in
    /) REWRITE_BASE="/" ;;
    *)
        BASE_PATH="/${BASE_PATH#/}"
        BASE_PATH="${BASE_PATH%/}"
        REWRITE_BASE="$BASE_PATH/"
        ;;
esac

rm -rf "$DIST"
mkdir -p "$PUBLIC" "$APP/config" "$APP/storage/sessions" "$APP/storage/data" "$APP/storage/logs"

cp deploy/shared/index.php "$PUBLIC/index.php"
cp deploy/shared/.htaccess "$PUBLIC/.htaccess"
cp deploy/shared/README-SHARED.txt "$DIST/README-SHARED.txt"

if ! grep -q 'RewriteEngine On' "$PUBLIC/.htaccess"; then
    echo "ERROR: RewriteEngine On not found in deploy/shared/.htaccess" >&2
    exit 1
fi
sed -i "/RewriteEngine On/a\\    RewriteBase $REWRITE_BASE" "$PUBLIC/.htaccess"

mkdir -p "$PUBLIC/assets"
cp -R public/assets/. "$PUBLIC/assets/"

cp -R src templates lang "$APP/"
cp config/config.example.php "$APP/config/config.example.php"
cp config/config.example.php "$DIST/config.example.php"

cp README.md LICENSE "$DIST/"
cp -R docs "$DIST/docs"

touch "$APP/storage/.keep" "$APP/storage/sessions/.keep" "$APP/storage/data/.keep" "$APP/storage/logs/.keep"

if command -v zip >/dev/null 2>&1; then
    DIST_PARENT="$(dirname "$DIST")"
    DIST_NAME="$(basename "$DIST")"
    (cd "$DIST_PARENT" && rm -f "$DIST_NAME.zip" && zip -qr "$DIST_NAME.zip" "$DIST_NAME")
fi

echo "Shared-hosting package: $DIST"
echo "RewriteBase: $REWRITE_BASE"
