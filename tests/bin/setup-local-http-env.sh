#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$TESTS_DIR/.." && pwd)"
SITE_ROOT="${LL_TOOLS_LOCAL_SITE_ROOT:-$(cd "$PROJECT_ROOT/../../.." && pwd -P)}"
BASH_RUNNER="${BASH:-bash}"
PHP_LOCAL=("$BASH_RUNNER" "$SCRIPT_DIR/php-local.sh")

find_up() {
    local target="$1"
    local dir="$2"
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/$target" ]]; then
            echo "$dir/$target"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

LOCAL_SITE_JSON="${LOCAL_SITE_JSON:-}"
if [[ -z "$LOCAL_SITE_JSON" ]]; then
    LOCAL_SITE_JSON="$(find_up "local-site.json" "$PROJECT_ROOT" || true)"
fi

if [[ -z "$LOCAL_SITE_JSON" ]]; then
    echo "local-site.json was not found. Set LOCAL_SITE_JSON explicitly." >&2
    exit 1
fi

shell_path_style="posix"
if [[ -n "${MSYSTEM:-}" ]]; then
    shell_path_style="msys"
elif [[ -n "${WSL_DISTRO_NAME:-}" ]]; then
    shell_path_style="wsl"
fi

resolver_args=(
    "--mode=http"
    "--site-root=$SITE_ROOT"
    "--local-site-json=$LOCAL_SITE_JSON"
    "--shell-path-style=$shell_path_style"
)

if [[ -n "${LL_TOOLS_LOCAL_RUNTIME_ROOT:-}" ]]; then
    resolver_args+=("--runtime-root=$LL_TOOLS_LOCAL_RUNTIME_ROOT")
fi

"${PHP_LOCAL[@]}" "$SCRIPT_DIR/resolve-local-runtime.php" "${resolver_args[@]}"
