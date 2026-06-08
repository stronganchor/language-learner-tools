#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="language-learner-tools"
MAIN_FILE="language-learner-tools.php"
REF="${1:-HEAD}"
OUTPUT_ARG="${2:-}"
REQUIRED_ASSETS_FILE="${SCRIPT_DIR}/required-runtime-assets.txt"

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

if [[ -f "${REQUIRED_ASSETS_FILE}" ]]; then
    missing_assets=()
    while IFS= read -r asset || [[ -n "${asset}" ]]; do
        asset="${asset%%#*}"
        asset="${asset#"${asset%%[![:space:]]*}"}"
        asset="${asset%"${asset##*[![:space:]]}"}"
        if [[ -z "${asset}" ]]; then
            continue
        fi
        if ! git -C "${ROOT_DIR}" cat-file -e "${REF}:${asset}" >/dev/null 2>&1; then
            missing_assets+=("${asset}")
        fi
    done < "${REQUIRED_ASSETS_FILE}"

    if (( ${#missing_assets[@]} > 0 )); then
        printf 'Required runtime assets are missing from %s:\n' "${REF}" >&2
        printf '  %s\n' "${missing_assets[@]}" >&2
        exit 1
    fi
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

PYTHON_BIN=""
if command -v python3 >/dev/null 2>&1; then
    PYTHON_BIN="python3"
elif command -v python >/dev/null 2>&1; then
    PYTHON_BIN="python"
fi

if [[ -z "${PYTHON_BIN}" ]]; then
    printf 'Python is required to validate the release zip contents.\n' >&2
    exit 1
fi

"${PYTHON_BIN}" - "${OUTPUT_PATH}" "${PLUGIN_SLUG}" "${REQUIRED_ASSETS_FILE}" <<'PY'
import sys
import zipfile
from pathlib import Path

zip_path = Path(sys.argv[1])
plugin_slug = sys.argv[2].strip("/")
manifest_path = Path(sys.argv[3])

required = []
for raw_line in manifest_path.read_text(encoding="utf-8").splitlines():
    line = raw_line.split("#", 1)[0].strip()
    if line:
        required.append(line)

with zipfile.ZipFile(zip_path) as archive:
    names = set(archive.namelist())

missing = [asset for asset in required if f"{plugin_slug}/{asset}" not in names]
if missing:
    print("Release zip is missing required runtime assets:", file=sys.stderr)
    for asset in missing:
        print(f"  {asset}", file=sys.stderr)
    sys.exit(1)
PY

printf 'Built release package: %s\n' "${OUTPUT_PATH}"
