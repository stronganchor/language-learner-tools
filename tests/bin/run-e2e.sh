#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
E2E_DIR="$TESTS_DIR/e2e"
BASH_RUNNER="${BASH:-bash}"
caller_base_url_set=0
caller_base_url=""
if [[ -n "${LL_E2E_BASE_URL:-}" ]]; then
    caller_base_url_set=1
    caller_base_url="$LL_E2E_BASE_URL"
fi

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

perf_config_lock_requested="${LL_E2E_PERF_CONFIG_LOCKED:-0}"
required_locked_perf_vars=(
    LL_E2E_PERF_FIXTURE_MANIFEST
    LL_E2E_PERF_HISTORY_FILE
    LL_E2E_PERF_REPORT_FILE
    LL_E2E_PERF_MANIFEST_SHA256
)
locked_perf_vars=()
locked_perf_values=()
if [[ "$perf_config_lock_requested" == "1" ]]; then
    while IFS= read -r env_var; do
        [[ "$env_var" == LL_E2E_PERF_* ]] || continue
        [[ "$env_var" == "LL_E2E_PERF_CONFIG_LOCKED" ]] && continue
        locked_perf_vars+=("$env_var")
        locked_perf_values+=("${!env_var}")
    done < <(compgen -e)

    for env_var in "${required_locked_perf_vars[@]}"; do
        found_locked_perf_var=0
        for locked_index in "${!locked_perf_vars[@]}"; do
            if [[ "${locked_perf_vars[$locked_index]}" == "$env_var" && -n "${locked_perf_values[$locked_index]}" ]]; then
                found_locked_perf_var=1
                break
            fi
        done
        if [[ "$found_locked_perf_var" != "1" ]]; then
            echo "Locked performance configuration is missing ${env_var}." >&2
            exit 1
        fi
    done
fi

load_env_file_literal "$TESTS_DIR/.env"
load_env_file_literal "$TESTS_DIR/.env.local"

if [[ "$caller_base_url_set" == "1" ]]; then
    export LL_E2E_BASE_URL="$caller_base_url"
fi

if [[ "$perf_config_lock_requested" == "1" ]]; then
    export LL_E2E_PERF_CONFIG_LOCKED=1
    for locked_index in "${!locked_perf_vars[@]}"; do
        env_var="${locked_perf_vars[$locked_index]}"
        export "$env_var=${locked_perf_values[$locked_index]}"
    done
else
    unset LL_E2E_PERF_CONFIG_LOCKED
fi

if [[ "${LL_E2E_PERF_CONFIG_CONTRACT_ONLY:-0}" == "1" ]]; then
    for locked_index in "${!locked_perf_vars[@]}"; do
        printf '%s=%s\n' "${locked_perf_vars[$locked_index]}" "${locked_perf_values[$locked_index]}"
    done
    exit 0
fi

if [[ "$caller_base_url_set" != "1" && "${LL_TOOLS_SKIP_AUTO_LOCAL_HTTP_ENV:-0}" != "1" ]]; then
    configured_learn_path="${LL_E2E_LEARN_PATH:-}"
    if detected_http_env="$("$BASH_RUNNER" "$SCRIPT_DIR/setup-local-http-env.sh" 2>&1)"; then
        eval "$detected_http_env"
        if [[ -n "$configured_learn_path" ]]; then
            export LL_E2E_LEARN_PATH="$configured_learn_path"
        fi
    elif [[ -z "${LL_E2E_BASE_URL:-}" ]]; then
        echo "$detected_http_env" >&2
        exit 1
    else
        echo "Could not refresh Local HTTP settings; using the configured LL_E2E_BASE_URL." >&2
    fi
elif [[ -z "${LL_E2E_BASE_URL:-}" ]]; then
    echo "LL_E2E_BASE_URL is required when automatic Local HTTP detection is disabled." >&2
    exit 1
fi

for env_var in \
    LL_E2E_BASE_URL \
    LL_E2E_LEARN_PATH \
    LL_E2E_STANDALONE_PATH \
    LL_E2E_ADMIN_USER \
    LL_E2E_ADMIN_PASS \
    LL_E2E_PAGE_SPEED_PATH \
    LL_E2E_PAGE_SPEED_SELECTOR \
    LL_E2E_PAGE_SPEED_LATENCY_MS \
    LL_E2E_PAGE_SPEED_DOWNLOAD_KBPS \
    LL_E2E_PAGE_SPEED_UPLOAD_KBPS \
    LL_E2E_PAGE_SPEED_CPU_SLOWDOWN_RATE \
    LL_E2E_PAGE_SPEED_MAX_DOMCONTENTLOADED_MS \
    LL_E2E_PAGE_SPEED_MAX_ACTIONABLE_MS \
    LL_E2E_PAGE_SPEED_MAX_LOAD_MS \
    LL_E2E_PAGE_SPEED_WARMUP_ATTEMPTS \
    LL_E2E_PAGE_SPEED_WARMUP_RETRY_DELAY_MS \
    LL_E2E_PAGE_SPEED_WARMUP_TIMEOUT_MS \
    LL_E2E_PAGE_SPEED_MEASURE_ATTEMPTS \
    LL_E2E_PERF_ENABLED \
    LL_E2E_PERF_FIXTURE_MANIFEST \
    LL_E2E_PERF_MANIFEST_SHA256 \
    LL_E2E_PERF_HISTORY_FILE \
    LL_E2E_PERF_REPORT_FILE \
    LL_E2E_PERF_WRITE_HISTORY \
    LL_E2E_PERF_COMPARE_HISTORY \
    LL_E2E_PERF_RUNS \
    LL_E2E_PERF_WARMUP_ATTEMPTS \
    LL_E2E_PERF_WARMUP_RETRY_DELAY_MS \
    LL_E2E_PERF_MAX_DOMCONTENTLOADED_MS \
    LL_E2E_PERF_MAX_ACTIONABLE_MS \
    LL_E2E_PERF_MAX_LOAD_MS \
    LL_E2E_PERF_MAX_INTERACTION_MS \
    LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS \
    LL_E2E_PERF_MAX_REGRESSION_RATIO \
    LL_E2E_PERF_MAX_REGRESSION_MS \
    PHP_BIN \
    WP_CLI \
    WP_CLI_PHAR \
    LL_TOOLS_TEXT_TO_TEXT_INTRO_FIXTURE_MANIFEST
do
    if [[ -n "${!env_var:-}" ]]; then
        export "$env_var"
    fi
done

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
for env_var in \
    LL_E2E_BASE_URL \
    LL_E2E_LEARN_PATH \
    LL_E2E_STANDALONE_PATH \
    LL_E2E_ADMIN_USER \
    LL_E2E_ADMIN_PASS \
    LL_E2E_PAGE_SPEED_PATH \
    LL_E2E_PAGE_SPEED_SELECTOR \
    LL_E2E_PAGE_SPEED_LATENCY_MS \
    LL_E2E_PAGE_SPEED_DOWNLOAD_KBPS \
    LL_E2E_PAGE_SPEED_UPLOAD_KBPS \
    LL_E2E_PAGE_SPEED_CPU_SLOWDOWN_RATE \
    LL_E2E_PAGE_SPEED_MAX_DOMCONTENTLOADED_MS \
    LL_E2E_PAGE_SPEED_MAX_ACTIONABLE_MS \
    LL_E2E_PAGE_SPEED_MAX_LOAD_MS \
    LL_E2E_PAGE_SPEED_WARMUP_ATTEMPTS \
    LL_E2E_PAGE_SPEED_WARMUP_RETRY_DELAY_MS \
    LL_E2E_PAGE_SPEED_WARMUP_TIMEOUT_MS \
    LL_E2E_PAGE_SPEED_MEASURE_ATTEMPTS \
    LL_E2E_PERF_ENABLED \
    LL_E2E_PERF_FIXTURE_MANIFEST \
    LL_E2E_PERF_MANIFEST_SHA256 \
    LL_E2E_PERF_HISTORY_FILE \
    LL_E2E_PERF_REPORT_FILE \
    LL_E2E_PERF_WRITE_HISTORY \
    LL_E2E_PERF_COMPARE_HISTORY \
    LL_E2E_PERF_RUNS \
    LL_E2E_PERF_WARMUP_ATTEMPTS \
    LL_E2E_PERF_WARMUP_RETRY_DELAY_MS \
    LL_E2E_PERF_MAX_DOMCONTENTLOADED_MS \
    LL_E2E_PERF_MAX_ACTIONABLE_MS \
    LL_E2E_PERF_MAX_LOAD_MS \
    LL_E2E_PERF_MAX_INTERACTION_MS \
    LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS \
    LL_E2E_PERF_MAX_REGRESSION_RATIO \
    LL_E2E_PERF_MAX_REGRESSION_MS \
    PHP_BIN \
    WP_CLI \
    WP_CLI_PHAR \
    LL_TOOLS_TEXT_TO_TEXT_INTRO_FIXTURE_MANIFEST
do
    append_wslenv_var "$env_var"
done

if [[ "$perf_config_lock_requested" == "1" ]]; then
    for env_var in "${locked_perf_vars[@]}"; do
        append_wslenv_var "$env_var"
    done
fi

append_msys2_env_conv_excl_var() {
    local entry="$1"
    local current="${MSYS2_ENV_CONV_EXCL:-}"
    case ";${current};" in
        *";${entry};"*) ;;
        *)
            if [[ -z "$current" ]]; then
                export MSYS2_ENV_CONV_EXCL="$entry"
            else
                export MSYS2_ENV_CONV_EXCL="${current};${entry}"
            fi
            ;;
    esac
}

# Git Bash otherwise rewrites web-root values such as `/learn/` to a Windows
# filesystem path when it launches the Windows Node/npm process. These are URL
# paths, not local files; preserve them literally while still allowing MSYS to
# convert real fixture/report filesystem paths for Windows Playwright.
for env_var in \
    LL_E2E_LEARN_PATH \
    LL_E2E_STANDALONE_PATH \
    LL_E2E_PAGE_SPEED_PATH
do
    append_msys2_env_conv_excl_var "$env_var"
done

if [[ ! -d "$E2E_DIR" ]]; then
    echo "E2E directory was not found: $E2E_DIR" >&2
    exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
    echo "npm is required to run Playwright tests." >&2
    exit 1
fi

NPM_RUNNER=(npm)
if [[ -n "${MSYSTEM:-}" ]]; then
    # npm's extensionless shell shim can hand its shebang to Windows/WSL, and
    # even a resolved .cmd path may be re-selected through PATHEXT. Invoke the
    # npm JavaScript entry point with the already verified Node executable.
    node_install_dir="$(node -p 'require("path").dirname(process.execPath)')"
    if command -v cygpath >/dev/null 2>&1; then
        node_install_dir="$(cygpath -u "$node_install_dir")"
    fi
    npm_cli_candidate="$node_install_dir/node_modules/npm/bin/npm-cli.js"
    if [[ -f "$npm_cli_candidate" ]]; then
        NPM_RUNNER=(node "$npm_cli_candidate")
    fi
fi

cd "$E2E_DIR"

if [[ ! -d "node_modules/@playwright/test" ]]; then
    "${NPM_RUNNER[@]}" install --no-audit --no-fund
fi

PLAYWRIGHT_CLI="node_modules/@playwright/test/cli.js"
if [[ ! -f "$PLAYWRIGHT_CLI" ]]; then
    echo "Playwright CLI was not found after dependency installation: $E2E_DIR/$PLAYWRIGHT_CLI" >&2
    exit 1
fi

# Avoid invoking Playwright's installer (and its network/cache probes) when the
# exact bundled Chromium executable is already present. This keeps focused and
# full local gates deterministic in restricted/offline runners.
if ! node -e "const fs=require('fs'); const {chromium}=require('@playwright/test'); process.exit(fs.existsSync(chromium.executablePath()) ? 0 : 1);"; then
    if [[ "${LL_TOOLS_E2E_SKIP_BROWSER_INSTALL:-0}" == "1" || "${CODEX_SANDBOX_NETWORK_DISABLED:-0}" == "1" ]]; then
        echo "Chromium could not be inspected; skipping browser installation in the network-restricted runner." >&2
    else
        node "$PLAYWRIGHT_CLI" install chromium
    fi
fi

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

exec node "$PLAYWRIGHT_CLI" test "${normalized_args[@]}"
