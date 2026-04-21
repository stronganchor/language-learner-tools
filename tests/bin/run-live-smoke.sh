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

load_env_file_literal "$TESTS_DIR/.env"
load_env_file_literal "$TESTS_DIR/.env.local"

if [[ -z "${LL_LIVE_SITES_FILE:-}" ]]; then
    LL_LIVE_SITES_FILE="$E2E_DIR/live-smoke/sites.local.json"
fi
export LL_LIVE_SITES_FILE

for env_var in \
    LL_LIVE_SITES_FILE \
    LL_LIVE_SMOKE_TIMEOUT_MS \
    LL_LIVE_SMOKE_PAUSE_MS
do
    if [[ -n "${!env_var:-}" ]]; then
        export "$env_var"
    fi
done

append_wslenv_var "LL_LIVE_SITES_FILE/p"
append_wslenv_var "LL_LIVE_SMOKE_TIMEOUT_MS"
append_wslenv_var "LL_LIVE_SMOKE_PAUSE_MS"

if [[ ! -f "$LL_LIVE_SITES_FILE" ]]; then
    echo "Live smoke sites file was not found: $LL_LIVE_SITES_FILE" >&2
    echo "Copy tests/e2e/live-smoke/sites.example.json to tests/e2e/live-smoke/sites.local.json and edit it locally." >&2
    exit 1
fi

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

npx playwright install chromium

echo "Running live smoke tests from $LL_LIVE_SITES_FILE"
echo "This runner is serial, anonymous, and intended for low-impact public-page checks."

exec npx playwright test --config=live-smoke.config.js "$@"
