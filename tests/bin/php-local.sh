#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

preserve_env=(
    WP_TESTS_DIR
    WP_CORE_DIR
    PHP_BIN
    MYSQL_BIN
    COMPOSER_PHAR
)

for key in "${preserve_env[@]}"; do
    if [[ -n "${!key+x}" ]]; then
        export "__LL_TOOLS_PRE_${key}=${!key}"
    fi
done

if [[ -f "$TESTS_DIR/.env" ]]; then
    # shellcheck disable=SC1091
    source "$TESTS_DIR/.env"
fi

for key in "${preserve_env[@]}"; do
    prev_key="__LL_TOOLS_PRE_${key}"
    if [[ -n "${!prev_key+x}" ]]; then
        export "$key=${!prev_key}"
        unset "$prev_key"
    fi
done

find_php_bin() {
    if [[ -n "${PHP_BIN:-}" && -x "${PHP_BIN}" ]]; then
        echo "${PHP_BIN}"
        return 0
    fi
    if command -v php >/dev/null 2>&1; then
        command -v php
        return 0
    fi
    local base candidate
    for base in \
        "$HOME/AppData/Roaming/Local/lightning-services" \
        "/mnt/c/Users/${USER}/AppData/Roaming/Local/lightning-services" \
        /mnt/c/Users/*/AppData/Roaming/Local/lightning-services
    do
        if [[ -d "$base" ]]; then
            candidate="$(find "$base" -maxdepth 6 -type f -iname php.exe 2>/dev/null | sort -V | tail -n 1 || true)"
            if [[ -n "$candidate" ]]; then
                echo "$candidate"
                return 0
            fi
        fi
    done
    return 1
}

convert_arg_for_windows_php() {
    local arg="$1"
    if [[ "$arg" == /mnt/* ]]; then
        if command -v wslpath >/dev/null 2>&1; then
            wslpath -w "$arg"
            return 0
        fi
    fi
    if [[ "$arg" == *=/mnt/* ]]; then
        local key="${arg%%=*}"
        local value="${arg#*=}"
        if command -v wslpath >/dev/null 2>&1; then
            printf '%s=%s\n' "$key" "$(wslpath -w "$value")"
            return 0
        fi
    fi
    printf '%s\n' "$arg"
}

PHP_BIN_DETECTED="$(find_php_bin || true)"
if [[ -z "$PHP_BIN_DETECTED" ]]; then
    echo "No PHP binary found. Set PHP_BIN or install PHP CLI." >&2
    exit 1
fi

if [[ "$PHP_BIN_DETECTED" == *.exe ]]; then
    if ! command -v wslpath >/dev/null 2>&1; then
        echo "wslpath is required to run Windows php.exe from this script." >&2
        exit 1
    fi

    php_dir="$(dirname "$PHP_BIN_DETECTED")"
    php_dir_win="$(wslpath -w "$php_dir")"
    ext_dir_win="${php_dir_win}\\ext"

    converted=()
    for arg in "$@"; do
        converted+=("$(convert_arg_for_windows_php "$arg")")
    done

    exec "$PHP_BIN_DETECTED" \
        -n \
        -d "extension_dir=${ext_dir_win}" \
        -d "extension=php_openssl.dll" \
        -d "extension=php_mbstring.dll" \
        -d "extension=php_curl.dll" \
        -d "extension=php_fileinfo.dll" \
        -d "extension=php_zip.dll" \
        -d "extension=php_mysqli.dll" \
        -d "extension=php_pdo_mysql.dll" \
        "${converted[@]}"
fi

exec "$PHP_BIN_DETECTED" "$@"
