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
ALLOW_LIVE_SITE_TEST_DB="${ALLOW_LIVE_SITE_TEST_DB:-0}"

db_host="$DB_HOST_RAW"
db_port=""
if [[ "$DB_HOST_RAW" == *:* ]]; then
    db_host="${DB_HOST_RAW%:*}"
    db_port="${DB_HOST_RAW##*:}"
fi

find_up() {
    local target="$1"
    local dir="$2"
    while [[ "$dir" != "/" ]]; do
        if [[ -f "$dir/$target" ]]; then
            echo "$dir/$target"
            return 0
        fi
        dir="$(dirname "$dir")"
    done
    return 1
}

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

normalize_db_target() {
    local raw_target="$1"
    local host="$raw_target"
    local port="3306"

    if [[ "$raw_target" == *:* ]]; then
        host="${raw_target%:*}"
        port="${raw_target##*:}"
    fi

    if [[ "$host" == "localhost" ]]; then
        host="127.0.0.1"
    fi

    printf '%s:%s\n' "$host" "$port"
}

detect_live_site_db_target() {
    if [[ -n "${LOCAL_LIVE_DB_NAME:-}" && -n "${LOCAL_LIVE_DB_HOST:-}" ]]; then
        printf '%s\n%s\n' "${LOCAL_LIVE_DB_NAME}" "$(normalize_db_target "${LOCAL_LIVE_DB_HOST}")"
        return 0
    fi

    if ! command -v python3 >/dev/null 2>&1; then
        return 1
    fi

    local local_site_json
    local site_root
    local_site_json="${LOCAL_SITE_JSON:-}"
    if [[ -z "$local_site_json" ]]; then
        local_site_json="$(find_up "local-site.json" "$PROJECT_ROOT" || true)"
    fi
    if [[ -z "$local_site_json" || ! -f "$local_site_json" ]]; then
        return 1
    fi

    site_root="$(cd "$PROJECT_ROOT/../../.." && pwd -P)"

    readarray -t parsed < <(python3 - "$local_site_json" "$site_root" <<'PY'
import glob
import json
import pathlib
import re
import sys

local_site_json = pathlib.Path(sys.argv[1])
site_root = pathlib.Path(sys.argv[2]).resolve()
data = json.loads(local_site_json.read_text(encoding="utf-8"))
db = data.get("mysql", {})
db_name = db.get("database", "local")

port = ""
services = data.get("services", {})
mysql = services.get("mysql", {})
ports = mysql.get("ports", {})
if isinstance(ports, dict):
    mysql_ports = ports.get("MYSQL")
    if isinstance(mysql_ports, list) and mysql_ports:
        port = str(mysql_ports[0])

for conf_path in sorted(glob.glob("/mnt/c/Users/*/AppData/Roaming/Local/run/*/conf/nginx/site.conf")):
    conf_file = pathlib.Path(conf_path)
    try:
        nginx_text = conf_file.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        continue

    root_match = re.search(r'^\s*root\s+"([^"]+)";', nginx_text, re.MULTILINE)
    if not root_match:
        continue

    root_win = root_match.group(1).replace("\\", "/")
    if re.match(r"^[A-Za-z]:/", root_win):
        root_unix = "/mnt/" + root_win[0].lower() + "/" + root_win[3:]
    else:
        root_unix = root_win

    try:
        root_path = pathlib.Path(root_unix).resolve()
    except OSError:
        continue

    if root_path != site_root:
        continue

    mysql_conf = conf_file.parents[2] / "conf" / "mysql" / "my.cnf"
    if not mysql_conf.is_file():
        continue

    try:
        mysql_text = mysql_conf.read_text(encoding="utf-8", errors="ignore")
    except OSError:
        continue

    port_match = re.search(r'^\s*port\s*=\s*(\d+)\s*$', mysql_text, re.MULTILINE)
    if port_match:
        port = port_match.group(1)
        break

print(db_name)
print(f"127.0.0.1:{port or '3306'}")
PY
)

    if [[ ${#parsed[@]} -lt 2 ]]; then
        return 1
    fi

    printf '%s\n%s\n' "${parsed[0]}" "$(normalize_db_target "${parsed[1]}")"
}

ensure_safe_database_target() {
    local live_db_name=""
    local live_db_host=""
    local current_target

    readarray -t live_db < <(detect_live_site_db_target || true)
    if [[ ${#live_db[@]} -ge 2 ]]; then
        live_db_name="${live_db[0]}"
        live_db_host="${live_db[1]}"
    fi

    if [[ -z "$live_db_name" || -z "$live_db_host" ]]; then
        return 0
    fi

    current_target="$(normalize_db_target "$DB_HOST_RAW")"
    if [[ "$DB_NAME" != "$live_db_name" || "$current_target" != "$live_db_host" ]]; then
        return 0
    fi

    if [[ "$ALLOW_LIVE_SITE_TEST_DB" == "1" ]]; then
        echo "ALLOW_LIVE_SITE_TEST_DB=1 set; using live site database target ${DB_NAME} @ ${current_target}." >&2
        return 0
    fi

    echo "Refusing to use the live Local site database for WordPress tests." >&2
    echo "Live DB target: ${live_db_name} @ ${live_db_host}" >&2
    echo "Requested test DB target: ${DB_NAME} @ ${current_target}" >&2
    echo "Set WP_TEST_DB_NAME to an isolated database such as '${live_db_name}_test', or export ALLOW_LIVE_SITE_TEST_DB=1 to override intentionally." >&2
    exit 1
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

ensure_safe_database_target
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
