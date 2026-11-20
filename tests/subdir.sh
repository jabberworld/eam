#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-.testenv/php/php}"
TMP="tests/tmp/subdir"
DOCROOT="$TMP/public_html"
APP="$DOCROOT/eam"
BASE_URL="http://127.0.0.1:8084/eam"

rm -rf "$TMP"
mkdir -p "$APP/_app/config" "$APP/_app/storage/sessions" "$APP/_app/storage/data" "$APP/_app/storage/logs"
cp -R _app/src _app/templates _app/lang "$APP/_app/"
cp -R assets "$APP/assets"
cp index.php "$APP/index.php"
cp .htaccess "$APP/.htaccess"
cp tests/subdir-config.test.php "$APP/_app/config/config.php"
rm -f "$DOCROOT/config.php"

cat > "$TMP/router.php" <<'PHP'
<?php
$docroot = getenv('EAM_SUBDIR_DOCROOT') ?: __DIR__ . '/public_html';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$prefix = '/eam';
if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
    $relative = substr($path, strlen($prefix));
    if ($relative === '/_app' || str_starts_with($relative, '/_app/')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Forbidden\n";
        return true;
    }
    $docrootReal = realpath($docroot);
    $file = realpath($docroot . $path);
    if ($path !== '/' && $docrootReal !== false && $file !== false
        && str_starts_with($file, $docrootReal . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
    require $docroot . '/eam/index.php';
    return true;
}
http_response_code(404);
header('Content-Type: text/plain; charset=UTF-8');
echo "Not Found\n";
PHP

EAM_SUBDIR_DOCROOT="$DOCROOT" nohup "$PHP_BIN" -S 127.0.0.1:8084 -t "$DOCROOT" "$TMP/router.php" > "$TMP/app.log" 2>&1 &
APP_PID=$!

cleanup() {
    kill "$APP_PID" 2>/dev/null || true
    wait "$APP_PID" 2>/dev/null || true
}
trap cleanup EXIT

wait_for() {
    local url="$1"
    for _ in $(seq 1 60); do
        if curl -fsS -o /dev/null --max-time 2 "$url" 2>/dev/null; then
            return 0
        fi
        sleep 0.2
    done
    echo "FAIL: timeout waiting for $url" >&2
    exit 1
}

wait_for "$BASE_URL/health"

ok() { echo "[ok] $1"; }
fail() { echo "[FAIL] $1" >&2; tail -40 "$TMP/app.log" >&2 || true; exit 1; }

assert_file_contains() {
    local file="$1" needle="$2" label="$3"
    if node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");process.exit(s.includes(process.argv[2])?0:1)' "$file" "$needle"; then
        ok "$label"
    else
        fail "$label"
    fi
}

http_status() {
    curl -sS -o /dev/null --max-time 5 -w '%{http_code}' "$BASE_URL$1"
}

curl -sS --max-time 10 "$BASE_URL/registration" -o "$TMP/registration.html"
assert_file_contains "$TMP/registration.html" 'EAM_BASE_PATH = "/eam"' "subdirectory base path is exposed to JS"
assert_file_contains "$TMP/registration.html" 'app.js?v=1' "asset version is applied"
assert_file_contains "$TMP/registration.html" 'data:image/png;base64,' "built-in captcha is rendered"
[ "$(http_status /assets/css/style.css)" = "200" ] || fail "subdirectory assets are not served"
ok "subdirectory assets are served"
[ "$(http_status /_app/config/config.php)" = "403" ] || fail "subdirectory _app is not denied"
[ "$(http_status /_app/src/App.php)" = "403" ] || fail "subdirectory _app source is not denied"
ok "subdirectory _app is denied"

curl -sS --max-time 10 "$BASE_URL/api/captcha" -o "$TMP/captcha.json"
node -e '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/subdir/captcha.json","utf8"));
if(typeof j.html!=="string"||!j.html.includes("data:image/png;base64,"))process.exit(1);
' || fail "captcha reload endpoint under /eam"
ok "captcha reload endpoint works under /eam"

echo
echo "Subdirectory tests passed."
