#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

eval "$("$SCRIPT_DIR/setup-local-env.sh")"

if [[ ! -f "$TESTS_DIR/.env" ]]; then
    cp "$TESTS_DIR/.env.example" "$TESTS_DIR/.env"
fi

if ! grep -q "^WP_TEST_DB_NAME=" "$TESTS_DIR/.env"; then
    {
        echo "WP_TEST_DB_NAME=${WP_TEST_DB_NAME}"
        echo "WP_TEST_DB_USER=${WP_TEST_DB_USER}"
        echo "WP_TEST_DB_PASS=${WP_TEST_DB_PASS}"
        echo "WP_TEST_DB_HOST=${WP_TEST_DB_HOST}"
        echo "WP_TESTS_DIR=${WP_TESTS_DIR}"
        echo "WP_CORE_DIR=${WP_CORE_DIR}"
    } >> "$TESTS_DIR/.env"
fi

"$SCRIPT_DIR/install-wp-tests.sh" \
    "$WP_TEST_DB_NAME" \
    "$WP_TEST_DB_USER" \
    "$WP_TEST_DB_PASS" \
    "$WP_TEST_DB_HOST"

"$SCRIPT_DIR/run-tests.sh" "$@"
