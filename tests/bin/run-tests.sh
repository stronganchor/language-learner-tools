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

cd "$TESTS_DIR"

php_family="$("$PHP_LOCAL" -r "echo PHP_OS_FAMILY;")"

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
    exec "$PHP_LOCAL" "$TESTS_DIR/vendor/phpunit/phpunit/phpunit" -c "$TESTS_DIR/phpunit.xml.dist" "${normalized_args[@]}"
fi

if [[ -x "$TESTS_DIR/vendor/bin/phpunit" ]]; then
    exec "$PHP_LOCAL" "$TESTS_DIR/vendor/bin/phpunit" -c "$TESTS_DIR/phpunit.xml.dist" "${normalized_args[@]}"
fi

echo "PHPUnit was not found after dependency install." >&2
exit 1
