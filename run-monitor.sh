#!/usr/bin/env bash
# Run: ./run-monitor.sh  — or if you get "Permission denied": bash run-monitor.sh
set -euo pipefail
cd "$(dirname "$0")"

PHP_BIN="${PHP_BIN:-}"
if [[ -z "$PHP_BIN" ]]; then
  for candidate in php php8.3 php8.2 php8.1 php8.0 /usr/bin/php /usr/local/bin/php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      PHP_BIN="$candidate"
      break
    fi
  done
fi

if [[ -z "$PHP_BIN" ]]; then
  echo "No PHP CLI found. Set PHP_BIN or install php-cli." >&2
  exit 127
fi

exec "$PHP_BIN" monitor.php "$@"