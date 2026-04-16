#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_LOCAL="$SCRIPT_DIR/php-local.sh"

preserve_env=(
    WP_TEST_DB_NAME
    WP_TEST_DB_USER
    WP_TEST_DB_PASS
    WP_TEST_DB_HOST
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

for key in "${preserve_env[@]}"; do
    if [[ -n "${!key+x}" ]]; then
        export "$key"
    fi
done

if [[ -z "${WP_TESTS_DIR:-}" ]]; then
    export WP_TESTS_DIR="/tmp/wordpress-tests-lib"
fi
if [[ -z "${WP_CORE_DIR:-}" ]]; then
    export WP_CORE_DIR="/tmp/wordpress"
fi
if [[ -n "${LL_TOOLS_RESET_WP_TEST_DB:-}" ]]; then
    export RESET_DB="${LL_TOOLS_RESET_WP_TEST_DB}"
fi

get_windows_temp_dir_for_bootstrap() {
    if ! command -v cmd.exe >/dev/null 2>&1 || ! command -v wslpath >/dev/null 2>&1; then
        return 1
    fi

    local win_temp
    win_temp="$(cmd.exe /d /c echo %TEMP% 2>/dev/null | tr -d '\r' | tail -n 1)"
    if [[ -z "$win_temp" ]]; then
        return 1
    fi

    wslpath -u "$win_temp"
}

normalize_windows_php_bootstrap_paths() {
    if [[ "$php_family" != "Windows" ]]; then
        return
    fi
    if [[ "${LL_TOOLS_USE_WINDOWS_TEMP_WP_BOOTSTRAP:-1}" != "1" ]]; then
        return
    fi

    local win_temp target_tests_dir target_core_dir changed=0
    win_temp="$(get_windows_temp_dir_for_bootstrap || true)"
    if [[ -z "$win_temp" || ! -d "$win_temp" ]]; then
        return
    fi

    target_tests_dir="${win_temp}/wordpress-tests-lib"
    target_core_dir="${win_temp}/wordpress"

    if [[ -z "${WP_TESTS_DIR:-}" || "${WP_TESTS_DIR:-}" == "/tmp/wordpress-tests-lib" ]]; then
        export WP_TESTS_DIR="$target_tests_dir"
        changed=1
    fi
    if [[ -z "${WP_CORE_DIR:-}" || "${WP_CORE_DIR:-}" == "/tmp/wordpress" ]]; then
        export WP_CORE_DIR="$target_core_dir"
        changed=1
    fi

    if [[ "$changed" == "1" ]]; then
        echo "Using Windows-accessible WordPress test paths." >&2
        echo "WP_TESTS_DIR=$WP_TESTS_DIR" >&2
        echo "WP_CORE_DIR=$WP_CORE_DIR" >&2
    fi
}

ensure_wordpress_test_framework() {
    local tests_includes="$WP_TESTS_DIR/includes"
    local tests_functions="$tests_includes/functions.php"
    local tests_bootstrap="$tests_includes/bootstrap.php"
    local tests_config="$WP_TESTS_DIR/wp-tests-config.php"
    local core_includes="$WP_CORE_DIR/wp-includes"
    local core_settings="$WP_CORE_DIR/wp-settings.php"
    local needs_install=0

    if [[ ! -f "$tests_functions" ]]; then
        needs_install=1
    fi
    if [[ ! -f "$tests_bootstrap" ]]; then
        needs_install=1
    fi
    if [[ ! -f "$tests_config" ]]; then
        needs_install=1
    fi
    if [[ ! -d "$core_includes" ]]; then
        needs_install=1
    fi
    if [[ ! -f "$core_settings" ]]; then
        needs_install=1
    fi

    if [[ "$needs_install" == "1" ]]; then
        echo "WordPress test framework is missing or incomplete." >&2
        echo "WP_TESTS_DIR=$WP_TESTS_DIR" >&2
        echo "WP_CORE_DIR=$WP_CORE_DIR" >&2
        echo "Running tests/bin/install-wp-tests.sh to repair the local bootstrap..." >&2
        if ! "$SCRIPT_DIR/install-wp-tests.sh" \
            "${WP_TEST_DB_NAME:-wordpress_test}" \
            "${WP_TEST_DB_USER:-root}" \
            "${WP_TEST_DB_PASS:-root}" \
            "${WP_TEST_DB_HOST:-127.0.0.1:3306}"
        then
            echo "Automatic recovery failed." >&2
            echo "If WP_TESTS_DIR or WP_CORE_DIR are stale, rerun tests/bin/setup-local-env.sh and then tests/bin/install-wp-tests.sh manually." >&2
            exit 1
        fi
    fi
}

cd "$TESTS_DIR"

lock_dir="$TESTS_DIR/.run-tests.lock"
if ! mkdir "$lock_dir" 2>/dev/null; then
    echo "Another tests/bin/run-tests.sh process appears to be running (lock: $lock_dir)." >&2
    echo "Run PHPUnit serially to avoid wptests database deadlocks." >&2
    exit 1
fi
cleanup_ll_tools_test_lock() {
    rmdir "$lock_dir" >/dev/null 2>&1 || true
}
trap cleanup_ll_tools_test_lock EXIT

php_family="$("$PHP_LOCAL" -r "echo PHP_OS_FAMILY;")"
normalize_windows_php_bootstrap_paths

normalize_phpunit_arg() {
    local arg="$1"
    if [[ "$arg" == "$TESTS_DIR/"* ]]; then
        printf '%s\n' "${arg#$TESTS_DIR/}"
        return 0
    fi
    if [[ "$arg" == tests/* ]]; then
        printf '%s\n' "${arg#tests/}"
        return 0
    fi
    if [[ "$arg" == ./tests/* ]]; then
        printf '%s\n' "${arg#./tests/}"
        return 0
    fi
    printf '%s\n' "$arg"
}

normalized_args=()
for arg in "$@"; do
    normalized_args+=("$(normalize_phpunit_arg "$arg")")
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

if [[ "$php_family" == "Windows" ]]; then
    # When php-local resolves to Windows php.exe, path vars must be mirrored via WSLENV
    # so child Windows processes see WP_TESTS_DIR/WP_CORE_DIR correctly.
    append_wslenv_var "WP_TESTS_DIR/p"
    if [[ -n "${WP_CORE_DIR:-}" ]]; then
        append_wslenv_var "WP_CORE_DIR/p"
    fi
fi

ensure_wordpress_test_framework

needs_install=0
if [[ ! -f "vendor/autoload.php" ]]; then
    needs_install=1
elif [[ -f "composer.lock" && "composer.lock" -nt "vendor/autoload.php" ]]; then
    needs_install=1
fi

if [[ "$needs_install" == "1" ]]; then
    if [[ -n "${COMPOSER_PHAR:-}" && -f "${COMPOSER_PHAR}" ]]; then
        "$PHP_LOCAL" "$COMPOSER_PHAR" install --no-interaction --prefer-dist --working-dir="$TESTS_DIR"
    elif [[ -f "/mnt/c/ProgramData/ComposerSetup/bin/composer.phar" ]]; then
        "$PHP_LOCAL" "/mnt/c/ProgramData/ComposerSetup/bin/composer.phar" install --no-interaction --prefer-dist --working-dir="$TESTS_DIR"
    elif command -v composer >/dev/null 2>&1; then
        composer install --no-interaction --prefer-dist
    else
        echo "Composer not found. Set COMPOSER_PHAR or install Composer." >&2
        exit 1
    fi
fi

if [[ -f "$TESTS_DIR/vendor/phpunit/phpunit/phpunit" ]]; then
    "$PHP_LOCAL" "$TESTS_DIR/vendor/phpunit/phpunit/phpunit" -c "$TESTS_DIR/phpunit.xml.dist" "${normalized_args[@]}"
    exit $?
fi

if [[ -x "$TESTS_DIR/vendor/bin/phpunit" ]]; then
    "$PHP_LOCAL" "$TESTS_DIR/vendor/bin/phpunit" -c "$TESTS_DIR/phpunit.xml.dist" "${normalized_args[@]}"
    exit $?
fi

echo "PHPUnit was not found after dependency install." >&2
exit 1
