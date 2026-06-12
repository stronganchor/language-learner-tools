#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT_DIR="$(cd "$TESTS_DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT_DIR/../../.." && pwd)"
SEED_SCRIPT="$TESTS_DIR/performance/seed-performance-fixtures.php"
DEFAULT_HISTORY="$TESTS_DIR/performance/history/performance-history.jsonl"

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

to_runtime_path() {
    local path_value="$1"
    if [[ "${WP_CLI_BIN:-}" == *.exe ]]; then
        if command -v wslpath >/dev/null 2>&1 && [[ "$path_value" == /mnt/* ]]; then
            wslpath -w "$path_value"
            return
        fi
        if command -v cygpath >/dev/null 2>&1; then
            cygpath -w "$path_value"
            return
        fi
    fi

    printf '%s\n' "$path_value"
}

resolve_plugin_path() {
    local path_value="$1"
    if [[ "$path_value" == /* || "$path_value" =~ ^[A-Za-z]:[\\/].* ]]; then
        printf '%s\n' "$path_value"
        return
    fi

    printf '%s\n' "$ROOT_DIR/$path_value"
}

find_wp_cli() {
    WP_CLI_BIN="${WP_CLI:-wp}"
    WP_CLI_ARGS=()

    if command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
        return
    fi

    if [[ -n "${WP_CLI_PHAR:-}" && -f "${WP_CLI_PHAR:-}" ]]; then
        WP_CLI_BIN="${PHP_BIN:-php}"
        WP_CLI_ARGS=("$WP_CLI_PHAR")
    else
        local candidates=(
            "/mnt/c/Users/messy/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
            "/c/Users/messy/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
        )
        for candidate in "${candidates[@]}"; do
            if [[ -f "$candidate" ]]; then
                WP_CLI_BIN="${PHP_BIN:-php}"
                WP_CLI_ARGS=("$candidate")
                break
            fi
        done
    fi

    if ! "$WP_CLI_BIN" --version >/dev/null 2>&1; then
        local php_candidates=(
            "${PHP_BIN:-}"
            "/mnt/c/php/8.4/php.exe"
            "/c/php/8.4/php.exe"
        )
        for candidate in "${php_candidates[@]}"; do
            if [[ -n "$candidate" && -x "$candidate" ]]; then
                WP_CLI_BIN="$candidate"
                break
            fi
        done
    fi

    if [[ "$WP_CLI_BIN" == *.exe && "${#WP_CLI_ARGS[@]}" -gt 0 ]]; then
        local converted=()
        for arg in "${WP_CLI_ARGS[@]}"; do
            converted+=("$(to_runtime_path "$arg")")
        done
        WP_CLI_ARGS=("${converted[@]}")
    fi
}

load_env_file_literal "$TESTS_DIR/.env"
load_env_file_literal "$TESTS_DIR/.env.local"

if [[ -z "${LL_E2E_BASE_URL:-}" ]]; then
    eval "$("$SCRIPT_DIR/setup-local-http-env.sh")"
fi

configure_perf_profile() {
    local profile="${LL_PERF_PROFILE:-default}"
    case "$profile" in
        ""|"default")
            return
            ;;
        "xl")
            local xl_manifest="$TESTS_DIR/performance/fixtures/performance-wordsets-xl.json"
            local xl_manifest_rel="tests/performance/fixtures/performance-wordsets-xl.json"
            export LL_TOOLS_PERF_FIXTURE_MANIFEST="${LL_TOOLS_PERF_FIXTURE_MANIFEST:-$xl_manifest}"
            export LL_E2E_PERF_FIXTURE_MANIFEST="${LL_E2E_PERF_FIXTURE_MANIFEST:-$xl_manifest_rel}"
            export LL_E2E_PERF_HISTORY_FILE="${LL_E2E_PERF_HISTORY_FILE:-tests/performance/history/performance-history-xl.jsonl}"
            export LL_E2E_PERF_RUNS="${LL_E2E_PERF_RUNS:-1}"
            echo "Using LL Tools performance profile: xl"
            ;;
        *)
            echo "Unknown LL_PERF_PROFILE: $profile" >&2
            echo "Supported profiles: default, xl" >&2
            exit 1
            ;;
    esac
}

configure_perf_profile

if [[ "${LL_PERF_SKIP_SEED:-0}" != "1" ]]; then
    find_wp_cli
    echo "Seeding LL Tools performance fixture in ${WP_ROOT}"
    seed_env=()
    if [[ -n "${LL_TOOLS_PERF_FIXTURE_MANIFEST:-}" ]]; then
        seed_env+=("LL_TOOLS_PERF_FIXTURE_MANIFEST=$(to_runtime_path "$(resolve_plugin_path "$LL_TOOLS_PERF_FIXTURE_MANIFEST")")")
    fi
    if [[ -n "${LL_E2E_PERF_FIXTURE_MANIFEST:-}" ]]; then
        seed_env+=("LL_E2E_PERF_FIXTURE_MANIFEST=$(to_runtime_path "$(resolve_plugin_path "$LL_E2E_PERF_FIXTURE_MANIFEST")")")
    fi
    if [[ "${#seed_env[@]}" -gt 0 ]]; then
        env "${seed_env[@]}" "$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" --path="$(to_runtime_path "$WP_ROOT")" eval-file "$(to_runtime_path "$SEED_SCRIPT")"
    else
        "$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" --path="$(to_runtime_path "$WP_ROOT")" eval-file "$(to_runtime_path "$SEED_SCRIPT")"
    fi
else
    echo "Skipping performance fixture seeding because LL_PERF_SKIP_SEED=1"
fi

if [[ "${LL_PERF_SEED_ONLY:-0}" == "1" ]]; then
    exit 0
fi

export LL_E2E_PERF_ENABLED="${LL_E2E_PERF_ENABLED:-1}"
export LL_E2E_PERF_WRITE_HISTORY="${LL_E2E_PERF_WRITE_HISTORY:-1}"
export LL_E2E_PERF_COMPARE_HISTORY="${LL_E2E_PERF_COMPARE_HISTORY:-1}"
export LL_E2E_PERF_HISTORY_FILE="${LL_E2E_PERF_HISTORY_FILE:-$DEFAULT_HISTORY}"

"$SCRIPT_DIR/run-e2e.sh" specs/performance-benchmark.spec.js
