#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-.testenv/php/php}"
MAILPIT_BIN="${MAILPIT_BIN:-.testenv/mailpit/mailpit}"
CONFIG="$PWD/.testenv/config.real.php"
BASE_URL="http://127.0.0.1:8081"
MAILPIT_URL="http://127.0.0.1:8026"
TMP="tests/tmp"
COOKIES="$TMP/real-cookies.txt"
USER="eamtestreal$(date +%s)"
PASSWORD='Strong-Real-Pass-2026!'
NEW_PASSWORD='New-Real-Pass-2026!'
EMAIL='real@example.com'
NEW_EMAIL='newreal@example.com'

if [ ! -f "$CONFIG" ]; then
    echo "Missing $CONFIG (local real smoke config, gitignored)." >&2
    exit 1
fi

readarray -t CREDS < <(EAM_CONFIG="$CONFIG" "$PHP_BIN" -r '$c=require getenv("EAM_CONFIG"); echo $c["ejabberd"]["rest_url"],"\n",$c["ejabberd"]["login"],":",$c["ejabberd"]["password"];')
API="${CREDS[0]}"
AUTH="${CREDS[1]}"

mkdir -p "$TMP/real-sessions" "$TMP/real-data" "$TMP/real-logs"
rm -f "$TMP/real-mailpit.db" "$COOKIES"

nohup "$MAILPIT_BIN" --smtp 127.0.0.1:1026 --listen 127.0.0.1:8026 --database "$TMP/real-mailpit.db" --quiet > "$TMP/real-mailpit.log" 2>&1 &
MAILPIT_PID=$!
EAM_CONFIG="$CONFIG" nohup "$PHP_BIN" -S 127.0.0.1:8081 -t public public/router.php > "$TMP/real-app.log" 2>&1 &
APP_PID=$!

cleanup() {
    curl -sS --max-time 10 -u "$AUTH" -H 'Content-Type: application/json' \
        -d "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\"}" \
        "$API/unregister" >/dev/null 2>&1 || true
    kill "$APP_PID" "$MAILPIT_PID" 2>/dev/null || true
    wait "$APP_PID" "$MAILPIT_PID" 2>/dev/null || true
}
trap cleanup EXIT

wait_for() {
    local url="$1"
    for _ in $(seq 1 80); do
        if curl -fsS -o /dev/null --max-time 3 "$url" 2>/dev/null; then
            return 0
        fi
        sleep 0.25
    done
    echo "FAIL: timeout waiting for $url" >&2
    exit 1
}

wait_for "$MAILPIT_URL/api/v1/info"
wait_for "$BASE_URL/health"

ok() { echo "[ok] $1"; }
fail() { echo "[FAIL] $1" >&2; tail -40 "$TMP/real-app.log" >&2 || true; exit 1; }

assert_file_contains() {
    local file="$1" needle="$2" label="$3"
    if node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");process.exit(s.includes(process.argv[2])?0:1)' "$file" "$needle"; then
        ok "$label"
    else
        fail "$label"
    fi
}

api_post() {
    local command="$1" payload="$2"
    curl -sS --max-time 20 -u "$AUTH" -H 'Content-Type: application/json' -d "$payload" "$API/$command"
}

csrf() {
    node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");const m=s.match(/name="_csrf" value="([^"]+)"/);process.stdout.write(m?m[1]:"")' "$1"
}

hidden_code() {
    node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");const m=s.match(/name="code" value="([^"]+)"/);process.stdout.write(m?m[1]:"")' "$1"
}

latest_mail_link() {
    local id
    id=$(curl -sS "$MAILPIT_URL/api/v1/messages?limit=1" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);process.stdout.write((j.messages&&j.messages[0]&&j.messages[0].ID)||"")})')
    [ -n "$id" ] || return 1
    curl -sS "$MAILPIT_URL/api/v1/message/$id" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);const t=(j.HTML||"")+"\n"+(j.Text||"");const m=t.match(/https?:\/\/[^"<\s]+code=[a-f0-9]+/);process.stdout.write(m?m[0]:"")})'
}

get_page() {
    curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$BASE_URL$1" -o "$2" -w '%{http_code}'
}

# 1. Register
get_page /registration "$TMP/real-reg.html" >/dev/null
csrf1=$(csrf "$TMP/real-reg.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf1" \
    --data-urlencode "username=$USER" \
    --data-urlencode "host=ets.jabberworld.info" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "website=" \
    "$BASE_URL/registration" -o "$TMP/real-reg-post.html" >/dev/null
assert_file_contains "$TMP/real-reg-post.html" "activation link" "real registration sends activation email"
[ "$(api_post check_account "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\"}")" = "0" ] || fail "real account was not created"
[ "$(api_post get_ban_details "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\"}")" != "{}" ] || fail "real account is not banned before activation"
ok "real registration created and banned the account"

# 2. Activate
link=$(latest_mail_link)
[ -n "$link" ] || fail "real activation link not found"
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/real-activation.html" >/dev/null
assert_file_contains "$TMP/real-activation.html" "successfully activated" "real account activated"
[ "$(api_post check_password "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\",\"password\":\"$PASSWORD\"}")" = "0" ] || fail "real password was not restored after activation"
[ "$(api_post get_ban_details "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\"}")" = "{}" ] || fail "real account is still banned after activation"
ok "real activation unban works"

# 3. Change email
get_page /change-email "$TMP/real-change.html" >/dev/null
csrf2=$(csrf "$TMP/real-change.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf2" \
    --data-urlencode "login=$USER@ets.jabberworld.info" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$NEW_EMAIL" \
    --data-urlencode "website=" \
    "$BASE_URL/change-email" -o "$TMP/real-change-post.html" >/dev/null
assert_file_contains "$TMP/real-change-post.html" "confirmation link" "real change email sends confirmation"
link=$(latest_mail_link)
[ -n "$link" ] || fail "real change email link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/real-change-confirm.html" >/dev/null
csrf3=$(csrf "$TMP/real-change-confirm.html")
code3=$(hidden_code "$TMP/real-change-confirm.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf3" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code3" \
    "$BASE_URL/change-email" -o "$TMP/real-change-done.html" >/dev/null
assert_file_contains "$TMP/real-change-done.html" "has been changed to" "real email changed"
api_post private_get "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\",\"element\":\"private\",\"ns\":\"email\"}" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const t=JSON.parse(s);process.exit(t.includes("newreal@example.com")?0:1)})' || fail "real private email was not changed"
ok "real private email change works"

# 4. Reset password
get_page /reset-password "$TMP/real-reset.html" >/dev/null
csrf4=$(csrf "$TMP/real-reset.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf4" \
    --data-urlencode "login=$USER@ets.jabberworld.info" \
    --data-urlencode "website=" \
    "$BASE_URL/reset-password" -o "$TMP/real-reset-post.html" >/dev/null
assert_file_contains "$TMP/real-reset-post.html" "password reset link" "real reset sends email"
link=$(latest_mail_link)
[ -n "$link" ] || fail "real reset link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/real-reset-confirm.html" >/dev/null
csrf5=$(csrf "$TMP/real-reset-confirm.html")
code5=$(hidden_code "$TMP/real-reset-confirm.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf5" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code5" \
    --data-urlencode "password=$NEW_PASSWORD" \
    "$BASE_URL/reset-password" -o "$TMP/real-reset-done.html" >/dev/null
assert_file_contains "$TMP/real-reset-done.html" "password has been successfully changed" "real password changed"
[ "$(api_post check_password "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\",\"password\":\"$NEW_PASSWORD\"}")" = "0" ] || fail "real new password does not work"
ok "real password reset works"

# 5. Delete account
get_page /delete-account "$TMP/real-delete.html" >/dev/null
csrf6=$(csrf "$TMP/real-delete.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf6" \
    --data-urlencode "login=$USER@ets.jabberworld.info" \
    --data-urlencode "password=$NEW_PASSWORD" \
    --data-urlencode "website=" \
    "$BASE_URL/delete-account" -o "$TMP/real-delete-post.html" >/dev/null
assert_file_contains "$TMP/real-delete-post.html" "confirmation link" "real delete sends email"
link=$(latest_mail_link)
[ -n "$link" ] || fail "real delete link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/real-delete-confirm.html" >/dev/null
csrf7=$(csrf "$TMP/real-delete-confirm.html")
code7=$(hidden_code "$TMP/real-delete-confirm.html")
curl -sS -L --max-time 20 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf7" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code7" \
    --data-urlencode "password=$NEW_PASSWORD" \
    "$BASE_URL/delete-account" -o "$TMP/real-delete-done.html" >/dev/null
assert_file_contains "$TMP/real-delete-done.html" "Your account has been deleted" "real account deleted"
[ "$(api_post check_account "{\"user\":\"$USER\",\"host\":\"ets.jabberworld.info\"}")" = "1" ] || fail "real account still exists after deletion"
ok "real account deletion works"

trap - EXIT
cleanup
echo
echo "Real integration smoke passed."
