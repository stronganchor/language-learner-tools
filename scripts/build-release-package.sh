#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PLUGIN_SLUG="language-learner-tools"
MAIN_FILE="language-learner-tools.php"
REF="${1:-HEAD}"
OUTPUT_ARG="${2:-}"
REQUIRED_ASSETS_FILE="${SCRIPT_DIR}/required-runtime-assets.txt"

if [[ ! -f "${REQUIRED_ASSETS_FILE}" ]]; then
    printf 'Required runtime asset manifest not found: %s\n' "${REQUIRED_ASSETS_FILE}" >&2
    exit 1
fi

required_assets=()
while IFS= read -r asset || [[ -n "${asset}" ]]; do
    asset="${asset%%#*}"
    asset="${asset#"${asset%%[![:space:]]*}"}"
    asset="${asset%"${asset##*[![:space:]]}"}"
    if [[ -n "${asset}" ]]; then
        required_assets+=("${asset}")
    fi
done < "${REQUIRED_ASSETS_FILE}"

if (( ${#required_assets[@]} == 0 )); then
    printf 'Required runtime asset manifest is empty: %s\n' "${REQUIRED_ASSETS_FILE}" >&2
    exit 1
fi

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
VERSION="${VERSION%"${VERSION##*[![:space:]]}"}"

if [[ -z "${VERSION}" ]]; then
    printf 'Could not read plugin version from %s at %s\n' "${MAIN_FILE}" "${REF}" >&2
    exit 1
fi

VERSION_PATTERN='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
if [[ ! "${VERSION}" =~ ${VERSION_PATTERN} ]]; then
    printf 'Plugin version at %s must use three-part numeric x.y.z form; received: %s\n' "${REF}" "${VERSION}" >&2
    exit 1
fi

INTERNAL_VERSION="$(
    git -C "${ROOT_DIR}" show "${REF}:${MAIN_FILE}" \
        | sed -n "s/^define('LL_TOOLS_VERSION',[[:space:]]*'\([^']*\)');[[:space:]]*$/\1/p" \
        | head -n 1 \
        | tr -d '\r'
)"

if [[ -z "${INTERNAL_VERSION}" ]]; then
    printf 'Could not read LL_TOOLS_VERSION from %s at %s\n' "${MAIN_FILE}" "${REF}" >&2
    exit 1
fi

if [[ ! "${INTERNAL_VERSION}" =~ ${VERSION_PATTERN} ]]; then
    printf 'LL_TOOLS_VERSION at %s must use three-part numeric x.y.z form; received: %s\n' "${REF}" "${INTERNAL_VERSION}" >&2
    exit 1
fi

if [[ "${INTERNAL_VERSION}" != "${VERSION}" ]]; then
    printf 'Version header (%s) and LL_TOOLS_VERSION (%s) do not match at %s.\n' "${VERSION}" "${INTERNAL_VERSION}" "${REF}" >&2
    exit 1
fi

missing_assets=()
for asset in "${required_assets[@]}"; do
    if ! git -C "${ROOT_DIR}" cat-file -e "${REF}:${asset}" >/dev/null 2>&1; then
        missing_assets+=("${asset}")
    fi
done

if (( ${#missing_assets[@]} > 0 )); then
    printf 'Required runtime assets are missing from %s:\n' "${REF}" >&2
    printf '  %s\n' "${missing_assets[@]}" >&2
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

    if [[ "${OUTPUT_PATH}" =~ ^[A-Za-z]:[\\/].* ]]; then
        if ! command -v cygpath >/dev/null 2>&1; then
            printf 'A native Windows output path requires cygpath: %s\n' "${OUTPUT_PATH}" >&2
            exit 1
        fi
        OUTPUT_PATH="$(cygpath -u "${OUTPUT_PATH}")"
    fi

    if [[ "${OUTPUT_PATH}" != /* ]]; then
        OUTPUT_PATH="${ROOT_DIR}/${OUTPUT_PATH}"
    fi
fi

PYTHON_BIN=""
VALIDATOR=""
if command -v unzip >/dev/null 2>&1; then
    VALIDATOR="unzip"
elif command -v python3 >/dev/null 2>&1 && python3 -c 'import zipfile' >/dev/null 2>&1; then
    PYTHON_BIN="python3"
    VALIDATOR="python"
elif command -v python >/dev/null 2>&1 && python -c 'import zipfile' >/dev/null 2>&1; then
    PYTHON_BIN="python"
    VALIDATOR="python"
fi

if [[ -z "${VALIDATOR}" ]]; then
    printf 'Python or unzip is required to validate the release zip contents.\n' >&2
    exit 1
fi

mkdir -p "$(dirname "${OUTPUT_PATH}")"

ENTRY_LIST="$(mktemp)"
OUTPUT_OWNED=0
ARCHIVE_VALIDATED=0
cleanup() {
    local status=$?
    rm -f "${ENTRY_LIST}"
    if (( OUTPUT_OWNED == 1 && ARCHIVE_VALIDATED == 0 )); then
        rm -f "${OUTPUT_PATH}"
    fi
    return "${status}"
}
trap cleanup EXIT

rm -f "${OUTPUT_PATH}"
OUTPUT_OWNED=1

git -C "${ROOT_DIR}" -c core.autocrlf=false archive \
    --format=zip \
    --prefix="${PLUGIN_SLUG}/" \
    --output="${OUTPUT_PATH}" \
    "${REF}"

if [[ "${VALIDATOR}" == "python" ]]; then
    "${PYTHON_BIN}" - "${OUTPUT_PATH}" > "${ENTRY_LIST}" <<'PY'
import sys
import zipfile
from pathlib import Path

zip_path = Path(sys.argv[1])

with zipfile.ZipFile(zip_path) as archive:
    for name in archive.namelist():
        print(name)
PY
else
    unzip -Z1 "${OUTPUT_PATH}" > "${ENTRY_LIST}"
fi

forbidden_prefixes=(
    '.git/'
    '.github/'
    '.agents/'
    '.codex/'
    '.codex-remote-attachments/'
    '_codex_temp/'
    '.vscode/'
    'bin/'
    'dist/'
    'docs/'
    'node_modules/'
    'offline-app-builder/'
    'scripts/'
    'test-results/'
    'tests/'
)
forbidden_files=(
    '.gitattributes'
    '.gitignore'
    'AGENTS.md'
    'build-offline-app-apk.bat'
    'CODEBASE_ARCHITECTURE.md'
    'MAINTENANCE_BACKLOG.md'
    'RELEASING.md'
    'release-plugin.bat'
    'xampp-lltools.code-workspace'
)
invalid_entries=()
while IFS= read -r entry || [[ -n "${entry}" ]]; do
    if [[ "${entry}" != "${PLUGIN_SLUG}/"* ]]; then
        invalid_entries+=("${entry}")
        continue
    fi

    relative="${entry#"${PLUGIN_SLUG}/"}"
    if [[ -z "${relative}" ]]; then
        continue
    fi

    is_forbidden=0
    if [[ "${relative}" == *\\* || "${relative}" == /* || "${relative}" =~ (^|/)\.\.(/|$) ]]; then
        is_forbidden=1
    fi

    for prefix in "${forbidden_prefixes[@]}"; do
        if [[ "${relative}" == "${prefix}"* ]]; then
            is_forbidden=1
            break
        fi
    done

    if (( is_forbidden == 0 )); then
        for file in "${forbidden_files[@]}"; do
            if [[ "${relative}" == "${file}" ]]; then
                is_forbidden=1
                break
            fi
        done
    fi

    if [[ "${relative}" == languages/*.po~ ]]; then
        is_forbidden=1
    fi

    if (( is_forbidden == 1 )); then
        invalid_entries+=("${entry}")
    fi
done < "${ENTRY_LIST}"

if (( ${#invalid_entries[@]} > 0 )); then
    printf 'Release archive contains an invalid root or repository-only path:\n' >&2
    printf '  %s\n' "${invalid_entries[@]}" >&2
    exit 1
fi

missing_assets=()
for asset in "${required_assets[@]}"; do
    if ! grep -Fqx -- "${PLUGIN_SLUG}/${asset}" "${ENTRY_LIST}"; then
        missing_assets+=("${asset}")
    fi
done

if (( ${#missing_assets[@]} > 0 )); then
    printf 'Release zip is missing required runtime assets:\n' >&2
    printf '  %s\n' "${missing_assets[@]}" >&2
    exit 1
fi

ARCHIVE_VALIDATED=1

printf 'Built release package: %s\n' "${OUTPUT_PATH}"
