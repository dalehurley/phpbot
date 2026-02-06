#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP_LOG="${PHP_LOG:-/tmp/phpbot-php.log}"
WS_LOG="${WS_LOG:-/tmp/phpbot-ws.log}"

if ! lsof -iTCP:8787 -sTCP:LISTEN >/dev/null 2>&1; then
  (cd "$ROOT_DIR" && php -d max_execution_time=0 -S localhost:8787 -t public >"$PHP_LOG" 2>&1 &)
  echo "PHP server started (logs: $PHP_LOG)"
else
  echo "PHP server already running on :8787"
fi

if ! lsof -iTCP:8788 -sTCP:LISTEN >/dev/null 2>&1; then
  (cd "$ROOT_DIR" && php bin/ws-server.php >"$WS_LOG" 2>&1 &)
  echo "WebSocket server started (logs: $WS_LOG)"
else
  echo "WebSocket server already running on :8788"
fi

cd "$ROOT_DIR/frontend"

if [ ! -d node_modules ]; then
  npm install
fi

npm run dev
