#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PROJECT_ROOT="$(cd "$TESTS_DIR/.." && pwd)"

if ! command -v python3 >/dev/null 2>&1; then
    echo "python3 is required for setup-local-env.sh" >&2
    exit 1
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

LOCAL_SITE_JSON="${LOCAL_SITE_JSON:-}"
if [[ -z "$LOCAL_SITE_JSON" ]]; then
    LOCAL_SITE_JSON="$(find_up "local-site.json" "$PROJECT_ROOT" || true)"
fi

if [[ -z "$LOCAL_SITE_JSON" || ! -f "$LOCAL_SITE_JSON" ]]; then
    echo "local-site.json was not found. Set LOCAL_SITE_JSON explicitly." >&2
    exit 1
fi

readarray -t parsed < <(python3 - "$LOCAL_SITE_JSON" <<'PY'
import json,sys
from pathlib import Path
p = Path(sys.argv[1])
data = json.loads(p.read_text(encoding='utf-8'))
db = data.get('mysql', {})
services = data.get('services', {})
mysql = services.get('mysql', {})
ports = mysql.get('ports', {})
port = ''
if isinstance(ports, dict):
    mysql_ports = ports.get('MYSQL')
    if isinstance(mysql_ports, list) and mysql_ports:
        port = str(mysql_ports[0])
print(db.get('database', 'local_test'))
print(db.get('user', 'root'))
print(db.get('password', 'root'))
print(port if port else '3306')
PY
)

db_name="${parsed[0]:-local_test}"
db_user="${parsed[1]:-root}"
db_pass="${parsed[2]:-root}"
db_port="${parsed[3]:-3306}"

host_value="127.0.0.1:${db_port}"
tests_dir_default="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
core_dir_default="${WP_CORE_DIR:-/tmp/wordpress}"

php_candidate=""
mysql_candidate=""
for base in \
    "$HOME/AppData/Roaming/Local/lightning-services" \
    "/mnt/c/Users/${USER}/AppData/Roaming/Local/lightning-services" \
    /mnt/c/Users/*/AppData/Roaming/Local/lightning-services
do
    if [[ -z "$php_candidate" && -d "$base" ]]; then
        php_candidate="$(find "$base" -maxdepth 6 -type f -iname php.exe 2>/dev/null | sort -V | tail -n 1 || true)"
    fi
    if [[ -z "$mysql_candidate" && -d "$base" ]]; then
        mysql_candidate="$(find "$base" -maxdepth 8 -type f -iname mysql.exe 2>/dev/null | sort -V | tail -n 1 || true)"
    fi
done

if [[ -n "$php_candidate" && "$php_candidate" == *.exe ]]; then
    if [[ "$php_candidate" =~ ^/mnt/c/Users/([^/]+)/ ]]; then
        local_user="${BASH_REMATCH[1]}"
        win_temp="/mnt/c/Users/${local_user}/AppData/Local/Temp"
        if [[ -d "$win_temp" ]]; then
            if [[ -z "${WP_TESTS_DIR:-}" ]]; then
                tests_dir_default="${win_temp}/wordpress-tests-lib"
            fi
            if [[ -z "${WP_CORE_DIR:-}" ]]; then
                core_dir_default="${win_temp}/wordpress"
            fi
        fi
    fi
fi

echo "export WP_TEST_DB_NAME='${db_name//\'/\'\\\'\'}'"
echo "export WP_TEST_DB_USER='${db_user//\'/\'\\\'\'}'"
echo "export WP_TEST_DB_PASS='${db_pass//\'/\'\\\'\'}'"
echo "export WP_TEST_DB_HOST='${host_value//\'/\'\\\'\'}'"
echo "export WP_TESTS_DIR='${tests_dir_default//\'/\'\\\'\'}'"
echo "export WP_CORE_DIR='${core_dir_default//\'/\'\\\'\'}'"

if [[ -n "$php_candidate" ]]; then
    echo "export PHP_BIN='${php_candidate//\'/\'\\\'\'}'"
fi
if [[ -n "$mysql_candidate" ]]; then
    echo "export MYSQL_BIN='${mysql_candidate//\'/\'\\\'\'}'"
fi

echo "export LOCAL_SITE_JSON='${LOCAL_SITE_JSON//\'/\'\\\'\'}'"
