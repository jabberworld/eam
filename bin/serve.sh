#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
PHP_BIN="${PHP_BIN:-php}"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
exec "$PHP_BIN" -S "${HOST}:${PORT}" -t public public/router.php
