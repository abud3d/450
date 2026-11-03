#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"
PORT="${BALOUT_ROOM_TEST_PORT:-18766}"
export APP_ORIGIN="http://127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" -t "$ROOT/public" >"$TMP/server.log" 2>&1 &
SERVER_PID=$!
cleanup(){ kill "$SERVER_PID" 2>/dev/null || true; wait "$SERVER_PID" 2>/dev/null || true; rm -rf "$TMP"; }
trap cleanup EXIT
READY=0
for _ in $(seq 1 50); do
  if curl -fsS "http://127.0.0.1:$PORT/" >/dev/null 2>&1; then READY=1; break; fi
  sleep 0.2
done
if [ "$READY" -ne 1 ]; then cat "$TMP/server.log"; exit 1; fi
export ROOM_TEST_BASE="http://127.0.0.1:$PORT"
php "$ROOT/test-php/room-http-test.php"
