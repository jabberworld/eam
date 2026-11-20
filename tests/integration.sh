#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PHP_BIN="${PHP_BIN:-.testenv/php/php}"
MAILPIT_BIN="${MAILPIT_BIN:-.testenv/mailpit/mailpit}"
BASE_URL="http://127.0.0.1:8080"
FAKE_URL="http://127.0.0.1:7283"
MAILPIT_URL="http://127.0.0.1:8025"
TMP="tests/tmp"
COOKIES="$TMP/cookies.txt"
USER="eamtest_integration"
PASSWORD='Strong-Test-Pass-2026!'
NEW_PASSWORD='New-Strong-Pass-2026!'
EMAIL='test@example.com'
NEW_EMAIL='new@example.com'

rm -rf "$TMP"
mkdir -p "$TMP/sessions" "$TMP/data" "$TMP/logs"

nohup "$PHP_BIN" -S 127.0.0.1:7283 tests/fake-ejabberd.php > "$TMP/fake.log" 2>&1 &
FAKE_PID=$!
nohup "$MAILPIT_BIN" --smtp 127.0.0.1:1025 --listen 127.0.0.1:8025 --database "$TMP/mailpit.db" --quiet > "$TMP/mailpit.log" 2>&1 &
MAILPIT_PID=$!
EAM_CONFIG="$PWD/tests/config.test.php" nohup "$PHP_BIN" -S 127.0.0.1:8080 server/router.php > "$TMP/app.log" 2>&1 &
APP_PID=$!

cleanup() {
    kill "$APP_PID" "$FAKE_PID" "$MAILPIT_PID" 2>/dev/null || true
    wait "$APP_PID" "$FAKE_PID" "$MAILPIT_PID" 2>/dev/null || true
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

wait_for "$FAKE_URL/health"
wait_for "$MAILPIT_URL/api/v1/info"
wait_for "$BASE_URL/health"

ok() { echo "[ok] $1"; }
fail() { echo "[FAIL] $1" >&2; echo "--- app.log ---" >&2; tail -30 "$TMP/app.log" >&2 || true; exit 1; }

http_status() {
    curl -sS -o /dev/null --max-time 5 -w '%{http_code}' "$BASE_URL$1"
}
[ "$(http_status /)" = "200" ] || fail "landing page status"
[ "$(http_status /assets/css/style.css)" = "200" ] || fail "assets status"
[ "$(http_status /_app/config/config.example.php)" = "403" ] || fail "_app is not denied"
[ "$(http_status /server/README.md)" = "403" ] || fail "server dir is not denied"
[ "$(http_status /docs/INSTALL.md)" = "403" ] || fail "docs dir is not denied"
[ "$(http_status /tests/config.test.php)" = "403" ] || fail "tests dir is not denied"
ok "private directories are denied"

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

hidden_code() {
    node -e 'const fs=require("fs");const s=fs.readFileSync(process.argv[1],"utf8");const m=s.match(/name="code" value="([^"]+)"/);process.stdout.write(m?m[1]:"")' "$1"
}

latest_mail_id() {
    curl -sS "$MAILPIT_URL/api/v1/messages?limit=1" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);process.stdout.write((j.messages&&j.messages[0]&&j.messages[0].ID)||"")})'
}

latest_mail_link() {
    local id
    id=$(latest_mail_id)
    if [ -z "$id" ]; then
        return 1
    fi
    curl -sS "$MAILPIT_URL/api/v1/message/$id" | node -e 'let s="";process.stdin.on("data",d=>s+=d).on("end",()=>{const j=JSON.parse(s);const t=(j.HTML||"")+"\n"+(j.Text||"");const m=t.match(/https?:\/\/[^"<\s]+code=[a-f0-9]+/);process.stdout.write(m?m[0]:"")})'
}

get_page() {
    local path="$1" file="$2"
    curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$BASE_URL$path" -o "$file" -w '%{http_code}'
}

rm -f "$COOKIES"

# 1. Registration form
status=$(get_page /registration "$TMP/reg.html")
[ "$status" = "200" ] || fail "registration page status $status"
csrf1=$(csrf "$TMP/reg.html")
[ -n "$csrf1" ] || fail "registration csrf token is empty"
assert_file_contains "$TMP/reg.html" ">test.localhost<" "registration host label without @"
assert_node "registration has no @-prefixed host label" '
const fs=require("fs");const s=fs.readFileSync("tests/tmp/reg.html","utf8");
if(s.includes(">@test.localhost<"))process.exit(1);
'
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$BASE_URL/" -o "$TMP/landing.html" >/dev/null
assert_file_contains "$TMP/landing.html" "test.localhost" "landing shows domain"
assert_node "landing has no @-prefixed domain" '
const fs=require("fs");const s=fs.readFileSync("tests/tmp/landing.html","utf8");
if(s.includes("@test.localhost"))process.exit(1);
'
ok "registration form"

# 2. Register
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf1" \
    --data-urlencode "username=$USER" \
    --data-urlencode "host=test.localhost" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "website=" \
    "$BASE_URL/registration" -o "$TMP/reg_post.html" >/dev/null
assert_file_contains "$TMP/reg_post.html" "activation link" "registration sends activation email"
assert_node "registration bans account until activation" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
const u=j.users["eamtest_integration"];if(!u)process.exit(1);
if(u.email!=="test@example.com")process.exit(1);
if(!u.banned||u.banned.reason!=="Activation required")process.exit(1);
'
link=$(latest_mail_link)
[ -n "$link" ] || fail "activation link not found in email"
ok "activation email link extracted"

# 3. Activate
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/activation.html" >/dev/null
assert_file_contains "$TMP/activation.html" "successfully activated" "account activated"
assert_node "activation restored password and cleared ban" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
const u=j.users["eamtest_integration"];if(!u||u.banned!==null)process.exit(1);
if(u.password!=="Strong-Test-Pass-2026!")process.exit(1);
'

# 4. Token reuse
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/reuse.html" >/dev/null
assert_file_contains "$TMP/reuse.html" "invalid or has expired" "activation token is single-use"

# 5. Change email
status=$(get_page /change-email "$TMP/change.html")
[ "$status" = "200" ] || fail "change-email page status $status"
csrf2=$(csrf "$TMP/change.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf2" \
    --data-urlencode "login=$USER@test.localhost" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$NEW_EMAIL" \
    --data-urlencode "website=" \
    "$BASE_URL/change-email" -o "$TMP/change_post.html" >/dev/null
assert_file_contains "$TMP/change_post.html" "confirmation link" "change email sends confirmation"
link=$(latest_mail_link)
[ -n "$link" ] || fail "change email link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/change_confirm.html" >/dev/null
assert_file_contains "$TMP/change_confirm.html" "$NEW_EMAIL" "change email confirmation page"
csrf3=$(csrf "$TMP/change_confirm.html")
code3=$(hidden_code "$TMP/change_confirm.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf3" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code3" \
    "$BASE_URL/change-email" -o "$TMP/change_done.html" >/dev/null
assert_file_contains "$TMP/change_done.html" "has been changed to" "email changed"
assert_node "fake API stored new email" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
if(j.users["eamtest_integration"].email!=="new@example.com")process.exit(1);
'

# 6. Reset password
status=$(get_page /reset-password "$TMP/reset.html")
[ "$status" = "200" ] || fail "reset-password page status $status"
csrf4=$(csrf "$TMP/reset.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf4" \
    --data-urlencode "login=$USER@test.localhost" \
    --data-urlencode "website=" \
    "$BASE_URL/reset-password" -o "$TMP/reset_post.html" >/dev/null
assert_file_contains "$TMP/reset_post.html" "password reset link" "reset sends email"
link=$(latest_mail_link)
[ -n "$link" ] || fail "reset link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/reset_confirm.html" >/dev/null
csrf5=$(csrf "$TMP/reset_confirm.html")
code5=$(hidden_code "$TMP/reset_confirm.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf5" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code5" \
    --data-urlencode "password=$NEW_PASSWORD" \
    "$BASE_URL/reset-password" -o "$TMP/reset_done.html" >/dev/null
assert_file_contains "$TMP/reset_done.html" "password has been successfully changed" "password changed"
assert_node "fake API stored new password" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
if(j.users["eamtest_integration"].password!=="New-Strong-Pass-2026!")process.exit(1);
'

# 7. Delete account
status=$(get_page /delete-account "$TMP/delete.html")
[ "$status" = "200" ] || fail "delete-account page status $status"
csrf6=$(csrf "$TMP/delete.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf6" \
    --data-urlencode "login=$USER@test.localhost" \
    --data-urlencode "password=$NEW_PASSWORD" \
    --data-urlencode "website=" \
    "$BASE_URL/delete-account" -o "$TMP/delete_post.html" >/dev/null
assert_file_contains "$TMP/delete_post.html" "confirmation link" "delete sends confirmation"
link=$(latest_mail_link)
[ -n "$link" ] || fail "delete link not found"
curl -sS --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' "$link" -o "$TMP/delete_confirm.html" >/dev/null
csrf7=$(csrf "$TMP/delete_confirm.html")
code7=$(hidden_code "$TMP/delete_confirm.html")
curl -sS -L --max-time 15 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf7" \
    --data-urlencode "action=confirm" \
    --data-urlencode "code=$code7" \
    --data-urlencode "password=$NEW_PASSWORD" \
    "$BASE_URL/delete-account" -o "$TMP/delete_done.html" >/dev/null
assert_file_contains "$TMP/delete_done.html" "Your account has been deleted" "account deleted"
assert_node "fake API removed account" '
const fs=require("fs");const j=JSON.parse(fs.readFileSync("tests/tmp/fake-ejabberd.json","utf8"));
if(j.users["eamtest_integration"])process.exit(1);
'

# 8. CSRF protection
status=$(get_page /registration "$TMP/csrf.html")
csrf8=$(csrf "$TMP/csrf.html")
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "username=no_csrf_user" \
    --data-urlencode "host=test.localhost" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$EMAIL" \
    "$BASE_URL/registration" -o "$TMP/csrf_post.html" >/dev/null
assert_file_contains "$TMP/csrf_post.html" "form has expired" "CSRF protection"

# 9. Honeypot
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" -H 'Accept-Language: en' \
    --data-urlencode "_csrf=$csrf8" \
    --data-urlencode "username=honeypot_user" \
    --data-urlencode "host=test.localhost" \
    --data-urlencode "password=$PASSWORD" \
    --data-urlencode "email=$EMAIL" \
    --data-urlencode "website=bot" \
    "$BASE_URL/registration" -o "$TMP/honeypot_post.html" >/dev/null
assert_file_contains "$TMP/honeypot_post.html" "could not be verified" "honeypot protection"

# 10. Username API
curl -sS --max-time 10 -H 'Accept-Language: en' -d 'username=admin&host=test.localhost' "$BASE_URL/api/check-username" -o "$TMP/username_admin.json" >/dev/null
assert_file_contains "$TMP/username_admin.json" '"status":"blocked"' "username API blocks forbidden name"
curl -sS --max-time 10 -H 'Accept-Language: en' -d 'username=eamtest_available&host=test.localhost' "$BASE_URL/api/check-username" -o "$TMP/username_ok.json" >/dev/null
assert_file_contains "$TMP/username_ok.json" '"status":"success"' "username API reports available name"

# 11. Language switch
curl -sS -L --max-time 10 -c "$COOKIES" -b "$COOKIES" "$BASE_URL/language?to=ru&return=%2Fregistration" -o "$TMP/lang_ru.html" >/dev/null
assert_file_contains "$TMP/lang_ru.html" '<html lang="ru">' "language switch to Russian"
assert_file_contains "$TMP/lang_ru.html" "Регистрация" "Russian translation rendered"

echo
echo "Integration tests passed."
