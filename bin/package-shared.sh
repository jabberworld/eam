#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

DIST="${DIST:-$ROOT/dist/eam-shared}"
PUBLIC="$DIST/public_html"
APP="$PUBLIC/_app"

rm -rf "$DIST"
mkdir -p "$PUBLIC" "$APP/config" "$APP/storage/sessions" "$APP/storage/data" "$APP/storage/logs"

cp deploy/shared/index.php "$PUBLIC/index.php"
cp deploy/shared/.htaccess "$PUBLIC/.htaccess"
cp deploy/shared/README-SHARED.txt "$DIST/README-SHARED.txt"

mkdir -p "$PUBLIC/assets"
cp -R public/assets/. "$PUBLIC/assets/"

cp -R src templates lang "$APP/"
cp config/config.example.php "$APP/config/config.example.php"
cp config/config.example.php "$DIST/config.example.php"

cp README.md LICENSE "$DIST/"
cp -R docs "$DIST/docs"

touch "$APP/storage/.keep" "$APP/storage/sessions/.keep" "$APP/storage/data/.keep" "$APP/storage/logs/.keep"

if command -v zip >/dev/null 2>&1; then
    rm -f "$DIST/../eam-shared.zip"
    (cd "$DIST/.." && zip -qr eam-shared.zip eam-shared)
fi

echo "Shared-hosting package: $DIST"
