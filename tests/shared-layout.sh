#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-.testenv/php/php}"
MAILPIT_BIN="${MAILPIT_BIN:-.testenv/mailpit/mailpit}"
BASE_URL="http://127.0.0.1:8083"
FAKE_URL="http://127.0.0.1:7284"
MAILPIT_URL="http://127.0.0.1:8027"
TMP="tests/tmp/shared"
WEB="$TMP/public_html"
APP="$WEB/_app"
COOKIES="$TMP/cookies.txt"
USER="eamtestshared$(date +%s)"
PASSWORD='Strong-Shared-Pass-2026!'
EMAIL='shared@example.com'

rm -rf "$TMP"
mkdir -p "$WEB" "$APP/config" "$APP/storage/sessions" "$APP/storage/data" "$APP/storage/logs"

cp -R src templates lang "$APP/"
cp -R public/assets "$WEB/assets"
cp deploy/shared/index.php "$WEB/index.php"
cp deploy/shared/.htaccess "$WEB/.htaccess"
cp tests/shared-config.test.php "$TMP/config.php"
PHP_BIN="$PHP_BIN" "$PHP_BIN" -r '
$s = file_get_contents("tests/shared-config.test.php");
$s = str_replace("EAM Shared Test", "EAM Shared Internal", $s);
file_put_contents("tests/tmp/shared/public_html/_app/config/config.php", $s);
'

cat > "$TMP/router.php" <<'PHP'
<?php
$web = getenv('EAM_SHARED_WEB') ?: __DIR__ . '/public_html';
$path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
if ($path === '/_app' || str_starts_with($path, '/_app/')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Forbidden\n";
    return true;
}
$webReal = realpath($web);
$file = realpath($web . $path);
if ($path !== '/' && $webReal !== false && $file !== false
    && str_starts_with($file, $webReal . DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}
require $web . '/index.php';
PHP

rm -f tests/tmp/fake-ejabberd.json
nohup "$PHP_BIN" -S 127.0.0.1:7284 tests/fake-ejabberd.php > "$TMP/fake.log" 2>&1 &
FAKE_PID=$!
nohup "$MAILPIT_BIN" --smtp 127.0.0.1:1027 --listen 127.0.0.1:8027 --database "$TMP/mailpit.db" --quiet > "$TMP/mailpit.log" 2>&1 &
MAILPIT_PID=$!
EAM_SHARED_WEB="$WEB" nohup "$PHP_BIN" -S 127.0.0.1:8083 -t "$WEB" "$TMP/router.php" > "$TMP/app.log" 2>&1 &
APP_PID=$!

cleanup() {
    kill "$APP_PID" "$FAKE_PID" "$MAILPIT_PID" 2>/dev/null || true
    wait "$APP_PID" "$FAKE_PID" "$MAILPIT_PID" 2>/dev/null || true
}
trap cleanup EXIT

wait_for() {
    local url="$1"
    for _ in $(seq 1 80); do
        if curl -fsS -o /dev/null --max-time 2 "$url" 2>/dev/null; then
            return 0
        fi
        sleep 0.2
    done
    echo "FAIL: timeout waiting for $url" >&2
    exit 1
}

wait_for "$FAKE_URL/health"
wait_for "$MAILPIT_URL/api/v1/info"
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

assert_node() {
    local label="$1"
    shift
    if node -e "$@"; then
        ok "$label"
    else
        fail "$label"
    fi
}

csrf() {
    node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");const m=s.match(/name="_csrf" value="([^"]+)"/);process.stdout.write(m?m[1]:"")' "$1"
}

latest_mail_link() {
    local id
    id=$(curl -sS "$MAILPIT_URL/api/v1/messages?limit=1" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);process.stdout.write((j.messages&&j.messages[0]&&j.messages[0].ID)||"")})')
    [ -n "$id" ] || return 1
    curl -sS "$MAILPIT_URL/api/v1/message/$id" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);const t=(j.HTML||"")+"\n"+(j.Text||"");const m=t.match(/https?:\/\/[^"<\s]+code=[a-f0-9]+/);process.stdout.write(m?m[0]:"")})'
}

http_status() {
    curl -sS -o /dev/null --max-time 5 -w '%{http_code}' "$BASE_URL$1"
}

# Layout and security
curl -sS --max-time 10 "$BASE_URL/" -o "$TMP/landing.html"
assert_file_contains "$TMP/landing.html" "EAM Shared Test" "external config wins"
assert_node "internal config does not override external" '
const fs=require("fs");const s=fs.readFileSync("tests/tmp/shared/landing.html","utf8");
if(s.includes("EAM Shared Internal"))process.exit(1);
'
[ "$(http_status /assets/css/style.css)" = "200" ] || fail "public assets are not served"
ok "public assets are served"
[ "$(http_status /_app/config/config.php)" = "403" ] || fail "_app config is not denied"
[ "$(http_status /_app/storage/eam.sqlite)" = "403" ] || fail "_app storage is not denied"
[ "$(http_status /_app/src/App.php)" = "403" ] || fail "_app source is not denied"
ok "private _app paths are denied"
[ -f "$APP/storage/eam.sqlite" ] || fail "storage is not inside _app"
ok "storage uses _app/storage"

# Registration + activation through the shared layout
rm -f "$COOKIES"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$BASE_URL/registration" -o "$TMP/reg.html" >/dev/null
csrf1=$(csrf "$TMP/reg.html")
[ -n "$csrf1" ] || fail "shared registration csrf is empty"
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf1" \
    --data-urlencode "username=$USER" \
    --data-urlencode "host=test.localhost" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "website=" \
    "$BASE_URL/registration" -o "$TMP/reg-post.html" >/dev/null
assert_file_contains "$TMP/reg-post.html" "activation link" "shared registration sends email"
assert_node "shared registration created banned account" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
const u=j.users["'"$USER"'"];if(!u||!u.banned||u.banned.reason!=="Activation required")process.exit(1);
'
link=$(latest_mail_link)
[ -n "$link" ] || fail "shared activation link not found"
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/activation.html" >/dev/null
assert_file_contains "$TMP/activation.html" "successfully activated" "shared activation works"
assert_node "shared activation cleared ban" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
const u=j.users["'"$USER"'"];if(!u||u.banned!==null)process.exit(1);
'

# Cleanup app account (only the account created by this test)
curl -sS --max-time 10 -u 'eam@test.localhost:test' -H 'Content-Type: application/json' \
    -d "{\"user\":\"$USER\",\"host\":\"test.localhost\"}" \
    "$FAKE_URL/api/unregister" >/dev/null || true
ok "test account removed from fake API"

# Fallback to the internal config after removing the external one
kill "$APP_PID" 2>/dev/null || true
wait "$APP_PID" 2>/dev/null || true
rm -f "$TMP/config.php"
EAM_SHARED_WEB="$WEB" nohup "$PHP_BIN" -S 127.0.0.1:8083 -t "$WEB" "$TMP/router.php" > "$TMP/app-internal.log" 2>&1 &
APP_PID=$!
wait_for "$BASE_URL/health"
curl -sS --max-time 10 "$BASE_URL/" -o "$TMP/landing-internal.html"
assert_file_contains "$TMP/landing-internal.html" "EAM Shared Internal" "internal config works after removing external one"

echo
echo "Shared layout tests passed."
