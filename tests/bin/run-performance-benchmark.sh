#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ROOT_DIR="$(cd "$TESTS_DIR/.." && pwd)"
WP_ROOT="$(cd "$ROOT_DIR/../../.." && pwd)"
SEED_SCRIPT="$TESTS_DIR/performance/seed-performance-fixtures.php"
DEFAULT_HISTORY="$TESTS_DIR/performance/history/performance-history.jsonl"
DEFAULT_MANIFEST_REL="tests/performance/fixtures/performance-wordsets.json"
DEFAULT_HISTORY_REL="tests/performance/history/performance-history.jsonl"
DEFAULT_REPORT_REL="tests/performance/reports/performance-latest.json"

# Preserve normal configuration precedence for this runner: an explicit
# process environment wins over .env.local, which wins over .env. The env files
# still populate credentials and other values that the caller did not supply.
LL_TOOLS_PERF_CALLER_ENV_KEYS=()
while IFS= read -r key; do
    [[ -n "$key" ]] || continue
    LL_TOOLS_PERF_CALLER_ENV_KEYS+=("$key")
done < <(compgen -e)

ll_tools_perf_caller_env_has_key() {
    local expected_key="$1"
    local caller_key
    for caller_key in "${LL_TOOLS_PERF_CALLER_ENV_KEYS[@]}"; do
        if [[ "$caller_key" == "$expected_key" ]]; then
            return 0
        fi
    done
    return 1
}

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

        if ll_tools_perf_caller_env_has_key "$key"; then
            continue
        fi

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

to_runtime_path_list() {
    local raw="$1"
    local converted=()
    local old_ifs="$IFS"
    IFS='|'
    for item in $raw; do
        [[ -n "$item" ]] || continue
        converted+=("$(to_runtime_path "$item")")
    done
    IFS="$old_ifs"
    local joined=""
    for item in "${converted[@]}"; do
        if [[ -n "$joined" ]]; then
            joined+="|"
        fi
        joined+="$item"
    done
    printf '%s\n' "$joined"
}

resolve_plugin_path() {
    local path_value="$1"
    if [[ "$path_value" == /* || "$path_value" =~ ^[A-Za-z]:[\\/].* ]]; then
        printf '%s\n' "$path_value"
        return
    fi

    printf '%s\n' "$ROOT_DIR/$path_value"
}

first_existing_dir() {
    for candidate in "$@"; do
        if [[ -n "$candidate" && -d "$candidate" ]]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done
    return 1
}

configure_wordboat_media_sources() {
    local wordboat_root
    wordboat_root="$(first_existing_dir \
        "${LL_PERF_WORDBOAT_ROOT:-}" \
        "/mnt/c/Users/messy/OneDrive/Websites/wordboat" \
        "/c/Users/messy/OneDrive/Websites/wordboat" \
        "C:/Users/messy/OneDrive/Websites/wordboat" || true)"
    if [[ -z "$wordboat_root" ]]; then
        echo "Word Boat media root was not found; stress fixture will use generated fallback media."
        return
    fi

    if [[ -z "${LL_PERF_SOURCE_IMAGE_DIRS:-}" ]]; then
        if [[ -d "$wordboat_root/Images for word boat" ]]; then
            export LL_PERF_SOURCE_IMAGE_DIRS="$wordboat_root/Images for word boat"
        elif [[ -d "$wordboat_root/_review_artifacts" ]]; then
            export LL_PERF_SOURCE_IMAGE_DIRS="$wordboat_root/_review_artifacts"
        fi
    fi
    if [[ -z "${LL_PERF_SOURCE_AUDIO_DIRS:-}" && -d "$wordboat_root/audio_downloads" ]]; then
        export LL_PERF_SOURCE_AUDIO_DIRS="$wordboat_root/audio_downloads"
    fi
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
    local manifest_rel=""
    local history_rel=""
    local report_rel=""
    case "$profile" in
        ""|"default")
            profile="default"
            local seed_manifest_raw="${LL_TOOLS_PERF_FIXTURE_MANIFEST:-}"
            local e2e_manifest_raw="${LL_E2E_PERF_FIXTURE_MANIFEST:-}"
            local seed_manifest_path=""
            local e2e_manifest_path=""
            if [[ -n "$seed_manifest_raw" ]]; then
                seed_manifest_path="$(canonical_perf_manifest_path "$seed_manifest_raw")"
            fi
            if [[ -n "$e2e_manifest_raw" ]]; then
                e2e_manifest_path="$(canonical_perf_manifest_path "$e2e_manifest_raw")"
            fi
            if [[ -n "$seed_manifest_path" && -n "$e2e_manifest_path" && "$seed_manifest_path" != "$e2e_manifest_path" ]]; then
                echo "Default performance manifest overrides disagree; seeder and Playwright must use one file." >&2
                exit 1
            fi

            PERF_MANIFEST_PATH="${seed_manifest_path:-${e2e_manifest_path:-$(canonical_perf_manifest_path "$DEFAULT_MANIFEST_REL")}}"
            export LL_TOOLS_PERF_FIXTURE_MANIFEST="$PERF_MANIFEST_PATH"
            if [[ "$PERF_MANIFEST_PATH" == "$ROOT_DIR/"* ]]; then
                export LL_E2E_PERF_FIXTURE_MANIFEST="${PERF_MANIFEST_PATH#"$ROOT_DIR/"}"
            else
                export LL_E2E_PERF_FIXTURE_MANIFEST="${e2e_manifest_raw:-$PERF_MANIFEST_PATH}"
            fi
            export LL_E2E_PERF_HISTORY_FILE="${LL_E2E_PERF_HISTORY_FILE:-$DEFAULT_HISTORY_REL}"
            export LL_E2E_PERF_REPORT_FILE="${LL_E2E_PERF_REPORT_FILE:-$DEFAULT_REPORT_REL}"
            ;;
        "xl")
            manifest_rel="tests/performance/fixtures/performance-wordsets-xl.json"
            history_rel="tests/performance/history/performance-history-xl.jsonl"
            report_rel="tests/performance/reports/performance-latest-xl.json"
            export LL_E2E_PERF_RUNS="${LL_E2E_PERF_RUNS:-1}"
            ;;
        "genc")
            manifest_rel="tests/performance/fixtures/performance-wordsets-genc.json"
            history_rel="tests/performance/history/performance-history-genc.jsonl"
            report_rel="tests/performance/reports/performance-latest-genc.json"
            export LL_E2E_PERF_RUNS="${LL_E2E_PERF_RUNS:-1}"
            export LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS="${LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS:-120000}"
            export LL_PERF_SOURCE_IMAGE_LIMIT="${LL_PERF_SOURCE_IMAGE_LIMIT:-24}"
            export LL_PERF_SOURCE_AUDIO_LIMIT="${LL_PERF_SOURCE_AUDIO_LIMIT:-24}"
            configure_wordboat_media_sources
            ;;
        "stress-2x")
            manifest_rel="tests/performance/fixtures/performance-wordsets-stress-2x.json"
            history_rel="tests/performance/history/performance-history-stress-2x.jsonl"
            report_rel="tests/performance/reports/performance-latest-stress-2x.json"
            export LL_E2E_PERF_RUNS="${LL_E2E_PERF_RUNS:-1}"
            export LL_PERF_SOURCE_IMAGE_LIMIT="${LL_PERF_SOURCE_IMAGE_LIMIT:-24}"
            export LL_PERF_SOURCE_AUDIO_LIMIT="${LL_PERF_SOURCE_AUDIO_LIMIT:-24}"
            configure_wordboat_media_sources
            ;;
        *)
            echo "Unknown LL_PERF_PROFILE: $profile" >&2
            echo "Supported profiles: default, xl, genc, stress-2x" >&2
            exit 1
            ;;
    esac

    if [[ "$profile" != "default" ]]; then
        PERF_MANIFEST_PATH="$(canonical_perf_manifest_path "$manifest_rel")"
        # Named profiles are intentionally authoritative. Per-path values from
        # process env or env files cannot split seeding, measurement, history,
        # and reporting while the runner claims to measure this profile.
        export LL_TOOLS_PERF_FIXTURE_MANIFEST="$PERF_MANIFEST_PATH"
        export LL_E2E_PERF_FIXTURE_MANIFEST="$manifest_rel"
        export LL_E2E_PERF_HISTORY_FILE="$history_rel"
        export LL_E2E_PERF_REPORT_FILE="$report_rel"
    fi

    PERF_PROFILE="$profile"
    echo "Using LL Tools performance profile: $PERF_PROFILE"
}

canonical_perf_manifest_path() {
    local raw_path="$1"
    local resolved
    resolved="$(resolve_plugin_path "$raw_path")"
    if [[ "$resolved" =~ ^[A-Za-z]:[\\/].* ]] && command -v wslpath >/dev/null 2>&1; then
        resolved="$(wslpath -u "$resolved")"
    fi
    if [[ ! -f "$resolved" ]]; then
        echo "Performance fixture manifest was not found: $raw_path" >&2
        return 1
    fi

    local resolved_dir
    resolved_dir="$(cd "$(dirname "$resolved")" && pwd -P)"
    printf '%s/%s\n' "$resolved_dir" "$(basename "$resolved")"
}

describe_perf_manifest() {
    local description
    description="$("$SCRIPT_DIR/php-local.sh" \
        "$TESTS_DIR/performance/verify-performance-manifest.php" \
        --describe "$PERF_MANIFEST_PATH")"
    IFS=$'\t' read -r PERF_FIXTURE_VERSION PERF_MANIFEST_CHECKSUM PERF_MANIFEST_CHECKSUM_FORMAT <<< "$description"
    if [[ -z "$PERF_FIXTURE_VERSION" || -z "$PERF_MANIFEST_CHECKSUM" || "$PERF_MANIFEST_CHECKSUM_FORMAT" != "canonical-json-v1" ]]; then
        echo "Unable to resolve the canonical performance manifest contract." >&2
        exit 1
    fi

    export LL_E2E_PERF_MANIFEST_SHA256="$PERF_MANIFEST_CHECKSUM"
    echo "Performance fixture manifest: ${LL_E2E_PERF_FIXTURE_MANIFEST}"
    echo "Performance fixture version: $PERF_FIXTURE_VERSION"
    echo "Performance fixture canonical SHA-256: $PERF_MANIFEST_CHECKSUM"
    echo "Performance history file: ${LL_E2E_PERF_HISTORY_FILE}"
    echo "Performance report file: ${LL_E2E_PERF_REPORT_FILE}"
}

verify_seeded_perf_fixture() {
    local stored_fixture_json
    local option_command=(
        "${WP_CLI_ARGS[@]}"
        option get ll_tools_performance_fixture_manifest
        --format=json
        --path="$(to_runtime_path "$WP_ROOT")"
        --skip-plugins
        --skip-themes
    )
    if ! stored_fixture_json="$("$WP_CLI_BIN" "${option_command[@]}")"; then
        echo "Unable to read the stored LL Tools performance fixture manifest." >&2
        return 1
    fi

    if ! printf '%s' "$stored_fixture_json" | "$SCRIPT_DIR/php-local.sh" \
        "$TESTS_DIR/performance/verify-performance-manifest.php" \
        --verify-stored "$PERF_MANIFEST_PATH"; then
        echo "Stored LL Tools performance fixture does not match the selected profile; reseed before benchmarking." >&2
        return 1
    fi
}

configure_perf_profile
describe_perf_manifest

find_wp_cli

if [[ "${LL_PERF_SKIP_SEED:-0}" != "1" ]]; then
    echo "Seeding LL Tools performance fixture in ${WP_ROOT}"
    seed_env=()
    seed_args=()
    if [[ -n "${LL_TOOLS_PERF_FIXTURE_MANIFEST:-}" ]]; then
        runtime_manifest="$(to_runtime_path "$(resolve_plugin_path "$LL_TOOLS_PERF_FIXTURE_MANIFEST")")"
        seed_env+=("LL_TOOLS_PERF_FIXTURE_MANIFEST=$runtime_manifest")
        seed_args+=("manifest=$runtime_manifest")
    fi
    if [[ -n "${LL_E2E_PERF_FIXTURE_MANIFEST:-}" ]]; then
        runtime_e2e_manifest="$(to_runtime_path "$(resolve_plugin_path "$LL_E2E_PERF_FIXTURE_MANIFEST")")"
        seed_env+=("LL_E2E_PERF_FIXTURE_MANIFEST=$runtime_e2e_manifest")
        if [[ "${#seed_args[@]}" -eq 0 ]]; then
            seed_args+=("manifest=$runtime_e2e_manifest")
        fi
    fi
    if [[ -n "${LL_PERF_SOURCE_IMAGE_DIRS:-}" ]]; then
        runtime_source_image_dirs="$(to_runtime_path_list "$LL_PERF_SOURCE_IMAGE_DIRS")"
        seed_env+=("LL_PERF_SOURCE_IMAGE_DIRS=$runtime_source_image_dirs")
        seed_args+=("source-image-dirs=$runtime_source_image_dirs")
    fi
    if [[ -n "${LL_PERF_SOURCE_AUDIO_DIRS:-}" ]]; then
        runtime_source_audio_dirs="$(to_runtime_path_list "$LL_PERF_SOURCE_AUDIO_DIRS")"
        seed_env+=("LL_PERF_SOURCE_AUDIO_DIRS=$runtime_source_audio_dirs")
        seed_args+=("source-audio-dirs=$runtime_source_audio_dirs")
    fi
    if [[ -n "${LL_PERF_SOURCE_IMAGE_LIMIT:-}" ]]; then
        seed_env+=("LL_PERF_SOURCE_IMAGE_LIMIT=$LL_PERF_SOURCE_IMAGE_LIMIT")
        seed_args+=("source-image-limit=$LL_PERF_SOURCE_IMAGE_LIMIT")
    fi
    if [[ -n "${LL_PERF_SOURCE_AUDIO_LIMIT:-}" ]]; then
        seed_env+=("LL_PERF_SOURCE_AUDIO_LIMIT=$LL_PERF_SOURCE_AUDIO_LIMIT")
        seed_args+=("source-audio-limit=$LL_PERF_SOURCE_AUDIO_LIMIT")
    fi
    if [[ -n "${LL_PERF_FORCE_SEED:-}" ]]; then
        seed_env+=("LL_PERF_FORCE_SEED=$LL_PERF_FORCE_SEED")
        seed_args+=("force-seed=$LL_PERF_FORCE_SEED")
    fi
    seed_command=("${WP_CLI_ARGS[@]}" --path="$(to_runtime_path "$WP_ROOT")" eval-file "$(to_runtime_path "$SEED_SCRIPT")")
    if [[ "${#seed_args[@]}" -gt 0 ]]; then
        seed_command+=(-- "${seed_args[@]}")
    fi
    if [[ "${#seed_env[@]}" -gt 0 ]]; then
        env "${seed_env[@]}" "$WP_CLI_BIN" "${seed_command[@]}"
    else
        "$WP_CLI_BIN" "${seed_command[@]}"
    fi
else
    echo "Skipping performance fixture seeding because LL_PERF_SKIP_SEED=1; verifying the existing fixture read-only."
fi

verify_seeded_perf_fixture

if [[ "${LL_PERF_SEED_ONLY:-0}" == "1" ]]; then
    exit 0
fi

export LL_E2E_PERF_ENABLED="${LL_E2E_PERF_ENABLED:-1}"
export LL_E2E_PERF_WRITE_HISTORY="${LL_E2E_PERF_WRITE_HISTORY:-1}"
export LL_E2E_PERF_COMPARE_HISTORY="${LL_E2E_PERF_COMPARE_HISTORY:-1}"
export LL_E2E_PERF_HISTORY_FILE="${LL_E2E_PERF_HISTORY_FILE:-$DEFAULT_HISTORY}"
export LL_E2E_PERF_REPORT_FILE="${LL_E2E_PERF_REPORT_FILE:-tests/performance/reports/performance-latest.json}"
export LL_E2E_PERF_CONFIG_LOCKED=1

"$SCRIPT_DIR/run-e2e.sh" specs/performance-benchmark.spec.js
