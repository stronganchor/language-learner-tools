#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="language-learner-tools"
MAIN_FILE="language-learner-tools.php"
REF="${1:-HEAD}"
OUTPUT_ARG="${2:-}"

if ! git -C "${ROOT_DIR}" rev-parse --verify "${REF}^{tree}" >/dev/null 2>&1; then
    printf 'Unknown git ref or tree: %s\n' "${REF}" >&2
    exit 1
fi

VERSION="$(
    git -C "${ROOT_DIR}" show "${REF}:${MAIN_FILE}" \
        | sed -n 's/^Version:[[:space:]]*//p' \
        | head -n 1 \
        | tr -d '\r'
)"

if [[ -z "${VERSION}" ]]; then
    printf 'Could not read plugin version from %s at %s\n' "${MAIN_FILE}" "${REF}" >&2
    exit 1
fi

if [[ -z "${OUTPUT_ARG}" ]]; then
    OUTPUT_PATH="${ROOT_DIR}/dist/${PLUGIN_SLUG}-${VERSION}.zip"
else
    if [[ "${OUTPUT_ARG}" = *.zip ]]; then
        OUTPUT_PATH="${OUTPUT_ARG}"
    else
        OUTPUT_PATH="${OUTPUT_ARG}/${PLUGIN_SLUG}-${VERSION}.zip"
    fi

    if [[ "${OUTPUT_PATH}" != /* ]]; then
        OUTPUT_PATH="${ROOT_DIR}/${OUTPUT_PATH}"
    fi
fi

mkdir -p "$(dirname "${OUTPUT_PATH}")"
rm -f "${OUTPUT_PATH}"

git -C "${ROOT_DIR}" archive \
    --format=zip \
    --prefix="${PLUGIN_SLUG}/" \
    --output="${OUTPUT_PATH}" \
    "${REF}"

printf 'Built release package: %s\n' "${OUTPUT_PATH}"
