#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
WP_ROOT="$(cd "${PLUGIN_ROOT}/../../.." && pwd)"

if ! command -v wp >/dev/null 2>&1; then
    echo "wp command not found in PATH. Install WP-CLI or run the ll-tools commands through your site's existing wp binary." >&2
    exit 127
fi

exec wp --path="${WP_ROOT}" ll-tools "$@"
