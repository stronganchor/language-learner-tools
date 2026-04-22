#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_ROOT/../../.." && pwd)"

if ! command -v wp >/dev/null 2>&1; then
  echo "wp is required in PATH before using ll-rest-local.sh" >&2
  exit 1
fi

if ! command -v curl.exe >/dev/null 2>&1; then
  echo "curl.exe is required in PATH before using ll-rest-local.sh" >&2
  exit 1
fi

BASE_URL="${LL_TOOLS_REST_BASE_URL:-$(wp --path="$WP_ROOT" option get home --skip-plugins --skip-themes)}"
REST_PATH="${1:-/wp-json/ll-tools/v1/automation/status}"

if [[ $# -gt 0 ]]; then
  shift
fi

if [[ "$REST_PATH" =~ ^https?:// ]]; then
  TARGET_URL="$REST_PATH"
else
  TARGET_URL="${BASE_URL%/}/${REST_PATH#/}"
fi

exec curl.exe -ksS "$TARGET_URL" "$@"
