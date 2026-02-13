#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
E2E_DIR="$TESTS_DIR/e2e"

if [[ -f "$TESTS_DIR/.env" ]]; then
    # shellcheck disable=SC1091
    source "$TESTS_DIR/.env"
fi

if [[ -z "${LL_E2E_BASE_URL:-}" ]]; then
    eval "$("$SCRIPT_DIR/setup-local-http-env.sh")"
fi

if [[ -n "${LL_E2E_BASE_URL:-}" ]]; then
    export LL_E2E_BASE_URL
fi
if [[ -n "${LL_E2E_LEARN_PATH:-}" ]]; then
    export LL_E2E_LEARN_PATH
fi

append_wslenv_var() {
    local entry="$1"
    if [[ -z "${WSLENV:-}" ]]; then
        export WSLENV="$entry"
        return
    fi
    case ":${WSLENV}:" in
        *":${entry}:"*) ;;
        *) export WSLENV="${WSLENV}:${entry}" ;;
    esac
}

# `npx` is usually a Windows process in this workspace. Mirror env vars through
# WSLENV so Playwright receives base URL/path config.
append_wslenv_var "LL_E2E_BASE_URL"
append_wslenv_var "LL_E2E_LEARN_PATH"

if [[ ! -d "$E2E_DIR" ]]; then
    echo "E2E directory was not found: $E2E_DIR" >&2
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    echo "npm is required to run Playwright tests." >&2
    exit 1
fi

cd "$E2E_DIR"

if [[ ! -d "node_modules/@playwright/test" ]]; then
    npm install --no-audit --no-fund
fi

# Safe to run repeatedly; Playwright skips already-installed browsers.
npx playwright install chromium

echo "Running Playwright tests against ${LL_E2E_BASE_URL}${LL_E2E_LEARN_PATH:-/learn/}"

normalize_playwright_arg() {
    local arg="$1"
    if [[ "$arg" == "$E2E_DIR/"* ]]; then
        printf '%s\n' "${arg#$E2E_DIR/}"
        return 0
    fi
    if [[ "$arg" == tests/e2e/* ]]; then
        printf '%s\n' "${arg#tests/e2e/}"
        return 0
    fi
    if [[ "$arg" == ./tests/e2e/* ]]; then
        printf '%s\n' "${arg#./tests/e2e/}"
        return 0
    fi
    printf '%s\n' "$arg"
}

normalized_args=()
for arg in "$@"; do
    normalized_args+=("$(normalize_playwright_arg "$arg")")
done

exec npx playwright test "${normalized_args[@]}"
