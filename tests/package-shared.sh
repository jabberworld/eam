#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

mkdir -p tests/tmp
DIST="$PWD/tests/tmp/package-shared"
rm -rf "$DIST"
DIST="$DIST" BASE_PATH=/eam bin/package-shared.sh > "tests/tmp/package.log"

check_file() {
    [ -f "$1" ] || { echo "FAIL: missing $1" >&2; exit 1; }
}

check_absent() {
    [ ! -e "$1" ] || { echo "FAIL: unexpected $1" >&2; exit 1; }
}

check_file "$DIST/public_html/index.php"
check_file "$DIST/public_html/.htaccess"
check_file "$DIST/public_html/assets/css/style.css"
check_file "$DIST/public_html/_app/config/config.example.php"
check_file "$DIST/public_html/_app/src/Bootstrap.php"
check_file "$DIST/config.example.php"
check_file "$DIST/README-SHARED.txt"
check_absent "$DIST/public_html/_app/config/config.php"
check_absent "$DIST/tests"
check_absent "$DIST/.testenv"

grep -q 'RewriteBase /eam/' "$DIST/public_html/.htaccess" || {
    echo "FAIL: RewriteBase /eam/ missing" >&2
    exit 1
}
grep -q 'RewriteRule \^_app' "$DIST/public_html/.htaccess" || {
    echo "FAIL: _app deny rule missing" >&2
    exit 1
}
grep -q 'RewriteRule \^ index.php' "$DIST/public_html/.htaccess" || {
    echo "FAIL: front controller rule missing" >&2
    exit 1
}

echo "Package tests passed."
