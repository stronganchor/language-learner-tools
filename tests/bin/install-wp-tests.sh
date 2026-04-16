#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$TESTS_DIR/.." && pwd)"

DB_NAME="${1:-${WP_TEST_DB_NAME:-wordpress_test}}"
DB_USER="${2:-${WP_TEST_DB_USER:-root}}"
DB_PASS="${3:-${WP_TEST_DB_PASS:-root}}"
DB_HOST_RAW="${4:-${WP_TEST_DB_HOST:-127.0.0.1:3306}}"
WP_VERSION="${5:-${WP_VERSION:-latest}}"

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
SKIP_DB_CREATE="${SKIP_DB_CREATE:-0}"
RESET_DB="${RESET_DB:-0}"
MYSQL_BIN="${MYSQL_BIN:-mysql}"

db_host="$DB_HOST_RAW"
db_port=""
if [[ "$DB_HOST_RAW" == *:* ]]; then
    db_host="${DB_HOST_RAW%:*}"
    db_port="${DB_HOST_RAW##*:}"
fi

download() {
    local url="$1"
    local dest="$2"
    if [[ -s "$dest" ]]; then
        echo "Reusing cached archive: $dest"
        return 0
    fi
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$url" -o "$dest"
        return 0
    fi
    if command -v wget >/dev/null 2>&1; then
        wget -qO "$dest" "$url"
        return 0
    fi
    echo "curl or wget is required to download WordPress test assets." >&2
    return 1
}

detect_php_runtime_family() {
    if [[ -n "${LL_TOOLS_PHP_OS_FAMILY:-}" ]]; then
        printf '%s\n' "${LL_TOOLS_PHP_OS_FAMILY}"
        return 0
    fi

    local php_local="${SCRIPT_DIR}/php-local.sh"
    if [[ -x "$php_local" ]]; then
        "$php_local" -r "echo PHP_OS_FAMILY;" 2>/dev/null || true
        return 0
    fi

    if [[ -n "${PHP_BIN:-}" && -x "${PHP_BIN}" ]]; then
        "${PHP_BIN}" -r "echo PHP_OS_FAMILY;" 2>/dev/null || true
        return 0
    fi

    if command -v php >/dev/null 2>&1; then
        php -r "echo PHP_OS_FAMILY;" 2>/dev/null || true
        return 0
    fi

    return 1
}

runtime_uses_windows_php() {
    local family
    family="$(detect_php_runtime_family)"
    [[ "$family" == "Windows" ]]
}

detect_mysql_bin() {
    if [[ -n "${MYSQL_BIN:-}" && -x "${MYSQL_BIN}" ]]; then
        printf '%s\n' "${MYSQL_BIN}"
        return 0
    fi

    if command -v "${MYSQL_BIN:-mysql}" >/dev/null 2>&1; then
        command -v "${MYSQL_BIN:-mysql}"
        return 0
    fi

    local base candidate
    for base in \
        "$HOME/AppData/Roaming/Local/lightning-services" \
        "/mnt/c/Users/${USER}/AppData/Roaming/Local/lightning-services" \
        /mnt/c/Users/*/AppData/Roaming/Local/lightning-services
    do
        if [[ -d "$base" ]]; then
            candidate="$(find "$base" -maxdepth 6 -type f -iname mysql.exe 2>/dev/null | sort -V | tail -n 1 || true)"
            if [[ -n "$candidate" ]]; then
                printf '%s\n' "$candidate"
                return 0
            fi
        fi
    done

    return 1
}

detect_runtime_php_binary() {
    local php_local="${SCRIPT_DIR}/php-local.sh"
    local runtime_binary=""

    if [[ -n "${PHP_BIN:-}" && -x "${PHP_BIN}" ]]; then
        if [[ "${PHP_BIN}" == *.exe ]]; then
            printf '%s\n' "${PHP_BIN}"
            return 0
        fi

        runtime_binary="$("${PHP_BIN}" -r "echo PHP_BINARY;" 2>/dev/null || true)"
        if [[ -n "$runtime_binary" ]]; then
            printf '%s\n' "$runtime_binary"
            return 0
        fi
    fi

    if [[ -x "$php_local" ]]; then
        runtime_binary="$("$php_local" -r "echo PHP_BINARY;" 2>/dev/null || true)"
        if [[ -n "$runtime_binary" ]]; then
            printf '%s\n' "$runtime_binary"
            return 0
        fi
    fi

    if command -v php >/dev/null 2>&1; then
        runtime_binary="$(php -r "echo PHP_BINARY;" 2>/dev/null || true)"
        if [[ -n "$runtime_binary" ]]; then
            printf '%s\n' "$runtime_binary"
            return 0
        fi
    fi

    return 1
}

build_wp_php_binary() {
    if [[ -n "${WP_PHP_BINARY:-}" ]]; then
        printf '%s\n' "${WP_PHP_BINARY}"
        return 0
    fi

    if runtime_uses_windows_php && command -v wslpath >/dev/null 2>&1; then
        local runtime_binary runtime_binary_win runtime_binary_unix php_dir_win
        runtime_binary="$(detect_runtime_php_binary || true)"
        if [[ -n "$runtime_binary" ]]; then
            runtime_binary_win="$runtime_binary"
            if [[ "$runtime_binary_win" == /mnt/* ]]; then
                runtime_binary_win="$(wslpath -w "$runtime_binary_win")"
            fi

            if [[ "$runtime_binary_win" == [A-Za-z]:\\* ]]; then
                runtime_binary_unix="$(wslpath -u "$runtime_binary_win" 2>/dev/null || true)"
                if [[ -n "$runtime_binary_unix" ]]; then
                    php_dir_win="$(wslpath -w "$(dirname "$runtime_binary_unix")")"
                    printf '"%s" -n -d extension_dir="%s\\ext" -d extension=php_openssl.dll -d extension=php_mbstring.dll -d extension=php_curl.dll -d extension=php_fileinfo.dll -d extension=php_zip.dll -d extension=php_mysqli.dll -d extension=php_pdo_mysql.dll\n' \
                        "$runtime_binary_win" \
                        "$php_dir_win"
                    return 0
                fi
            fi
        fi
    fi

    if [[ -n "${PHP_BIN:-}" ]]; then
        printf '%s\n' "${PHP_BIN}"
        return 0
    fi

    printf 'php\n'
}

install_wp_core() {
    if [[ -d "$WP_CORE_DIR/wp-includes" && -f "$WP_CORE_DIR/wp-settings.php" ]]; then
        return
    fi
    rm -rf "$WP_CORE_DIR"
    mkdir -p "$WP_CORE_DIR"
    local archive="/tmp/wordpress-${WP_VERSION}.tar.gz"
    local slug="latest"
    if [[ "$WP_VERSION" != "latest" ]]; then
        slug="wordpress-${WP_VERSION}"
    fi
    download "https://wordpress.org/${slug}.tar.gz" "$archive"
    tar -xzf "$archive" --strip-components=1 -C "$WP_CORE_DIR"
    rm -f "$archive"
}

install_wp_tests_lib() {
    if [[ -f "$WP_TESTS_DIR/includes/functions.php" && -f "$WP_TESTS_DIR/includes/bootstrap.php" && -d "$WP_TESTS_DIR/data" ]]; then
        return
    fi

    rm -rf "$WP_TESTS_DIR"
    mkdir -p "$WP_TESTS_DIR"
    if command -v svn >/dev/null 2>&1; then
        svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
        svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"
        return
    fi

    local archive="/tmp/wordpress-develop-trunk.tar.gz"
    local extract_root
    extract_root="$(mktemp -d)"
    download "https://codeload.github.com/WordPress/wordpress-develop/tar.gz/refs/heads/trunk" "$archive"
    tar -xzf "$archive" -C "$extract_root"
    local src_root
    src_root="$(find "$extract_root" -maxdepth 1 -type d -name 'wordpress-develop-*' | head -n 1)"
    if [[ -z "$src_root" ]]; then
        echo "Unable to unpack wordpress-develop test library." >&2
        exit 1
    fi
    local includes_src
    local data_src
    includes_src="$(find "$src_root" -type d -path '*/tests/phpunit/includes' | head -n 1)"
    data_src="$(find "$src_root" -type d -path '*/tests/phpunit/data' | head -n 1)"
    if [[ -z "$includes_src" || -z "$data_src" ]]; then
        echo "Unable to locate wordpress-develop test library directories." >&2
        exit 1
    fi
    cp -R "$includes_src" "$WP_TESTS_DIR/includes"
    cp -R "$data_src" "$WP_TESTS_DIR/data"
    rm -rf "$extract_root" "$archive"
}

write_wp_tests_config() {
    local cfg="$WP_TESTS_DIR/wp-tests-config.php"
    local esc_db_name esc_db_user esc_db_pass esc_db_host esc_core esc_php_binary
    local wp_php_binary php_bin_win php_dir_win
    esc_db_name="${DB_NAME//\'/\'\\\'\'}"
    esc_db_user="${DB_USER//\'/\'\\\'\'}"
    esc_db_pass="${DB_PASS//\'/\'\\\'\'}"
    esc_db_host="${DB_HOST_RAW//\'/\'\\\'\'}"
    esc_core="${WP_CORE_DIR%/}/"
    if runtime_uses_windows_php && [[ "$esc_core" == /mnt/* ]] && command -v wslpath >/dev/null 2>&1; then
        esc_core="$(wslpath -w "${WP_CORE_DIR%/}")\\"
    fi
    esc_core="${esc_core//\\/\\\\}"
    esc_core="${esc_core//\'/\'\\\'\'}"

    wp_php_binary="$(build_wp_php_binary)"
    esc_php_binary="${wp_php_binary//\\/\\\\}"
    esc_php_binary="${esc_php_binary//\'/\'\\\'\'}"

    cat > "$cfg" <<PHP
<?php
define( 'DB_NAME', '${esc_db_name}' );
define( 'DB_USER', '${esc_db_user}' );
define( 'DB_PASSWORD', '${esc_db_pass}' );
define( 'DB_HOST', '${esc_db_host}' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
\$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'WordPress Test Site' );

define( 'WP_PHP_BINARY', '${esc_php_binary}' );
define( 'WPLANG', '' );
define( 'ABSPATH', '${esc_core}' );
PHP
}

create_test_database() {
    local mysql_bin

    if [[ "$SKIP_DB_CREATE" == "1" ]]; then
        echo "Skipping database creation (SKIP_DB_CREATE=1)."
        return
    fi

    mysql_bin="$(detect_mysql_bin || true)"
    if [[ -z "$mysql_bin" ]]; then
        echo "MySQL client not found (${MYSQL_BIN}); skipping DB create." >&2
        return
    fi

    local mysql_cmd=("$mysql_bin" "-h" "$db_host" "-u" "$DB_USER")
    if [[ -n "$db_port" ]]; then
        mysql_cmd+=("-P" "$db_port")
    fi
    if [[ -n "$DB_PASS" ]]; then
        mysql_cmd+=("--password=${DB_PASS}")
    fi

    if [[ "$RESET_DB" == "1" ]]; then
        echo "Resetting WordPress test database ${DB_NAME}."
        mysql_cmd+=("-e" "DROP DATABASE IF EXISTS \`${DB_NAME}\`; CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
    else
        mysql_cmd+=("-e" "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;")
    fi

    "${mysql_cmd[@]}"
}

install_wp_core
install_wp_tests_lib
write_wp_tests_config
create_test_database

echo "WordPress test framework installed."
echo "WP_CORE_DIR=${WP_CORE_DIR}"
echo "WP_TESTS_DIR=${WP_TESTS_DIR}"
echo "DB=${DB_NAME} @ ${DB_HOST_RAW}"
echo
echo "Next:"
echo "  cd \"${PROJECT_ROOT}/tests\""
echo "  composer install"
echo "  composer test"
