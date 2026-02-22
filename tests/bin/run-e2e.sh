#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
E2E_DIR="$TESTS_DIR/e2e"

load_env_file_literal() {
    local file="$1"
    [[ -f "$file" ]] || return 0

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ -z "$line" ]] && continue
        [[ "$line" == \#* ]] && continue

        if [[ "$line" == export\ * ]]; then
            line="${line#export }"
        fi

        [[ "$line" == *=* ]] || continue

        local key="${line%%=*}"
        local value="${line#*=}"

        key="${key#"${key%%[![:space:]]*}"}"
        key="${key%"${key##*[![:space:]]}"}"
        [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || continue

        if [[ ${#value} -ge 2 ]]; then
            local first_char="${value:0:1}"
            local last_char="${value: -1}"
            if [[ "$first_char" == '"' && "$last_char" == '"' ]] || [[ "$first_char" == "'" && "$last_char" == "'" ]]; then
                value="${value:1:${#value}-2}"
            fi
        fi

        export "$key=$value"
    done < "$file"
}

load_env_file_literal "$TESTS_DIR/.env"
load_env_file_literal "$TESTS_DIR/.env.local"

if [[ -z "${LL_E2E_BASE_URL:-}" ]]; then
    eval "$("$SCRIPT_DIR/setup-local-http-env.sh")"
fi

if [[ -n "${LL_E2E_BASE_URL:-}" ]]; then
    export LL_E2E_BASE_URL
fi
if [[ -n "${LL_E2E_LEARN_PATH:-}" ]]; then
    export LL_E2E_LEARN_PATH
fi
if [[ -n "${LL_E2E_ADMIN_USER:-}" ]]; then
    export LL_E2E_ADMIN_USER
fi
if [[ -n "${LL_E2E_ADMIN_PASS:-}" ]]; then
    export LL_E2E_ADMIN_PASS
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
append_wslenv_var "LL_E2E_ADMIN_USER"
append_wslenv_var "LL_E2E_ADMIN_PASS"

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
