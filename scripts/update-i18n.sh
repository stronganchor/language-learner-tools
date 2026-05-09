#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

WP_CLI="${WP_CLI:-wp}"
POT_FILE="languages/ll-tools-text-domain.pot"
TR_PO_FILE="languages/ll-tools-text-domain-tr_TR.po"

WP_CLI_BIN="$WP_CLI"
WP_CLI_ARGS=()
if ! command -v "$WP_CLI_BIN" >/dev/null 2>&1; then
  if [[ -n "${WP_CLI_PHAR:-}" && -f "${WP_CLI_PHAR:-}" ]]; then
    WP_CLI_BIN="php"
    WP_CLI_ARGS=("$WP_CLI_PHAR")
  else
    LOCAL_WP_CLI_CANDIDATES=(
      "/mnt/c/Users/messy/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
      "/c/Users/messy/AppData/Local/Programs/Local/resources/extraResources/bin/wp-cli/wp-cli.phar"
    )
    for candidate in "${LOCAL_WP_CLI_CANDIDATES[@]}"; do
      if [[ -f "$candidate" ]]; then
        WP_CLI_BIN="php"
        WP_CLI_ARGS=("$candidate")
        break
      fi
    done
  fi
fi

"${WP_CLI_BIN}" --version >/dev/null 2>&1 || {
  # In some Windows/WSL Local setups, `bash` runs in WSL but `php` is only
  # available as a Windows binary.
  if [[ "$WP_CLI_BIN" == "php" ]]; then
    PHP_BIN="${PHP_BIN:-}"
    PHP_CANDIDATES=(
      "${PHP_BIN:-}"
      "/mnt/c/php/8.4/php.exe"
      "/c/php/8.4/php.exe"
    )
    for candidate in "${PHP_CANDIDATES[@]}"; do
      if [[ -n "$candidate" && -x "$candidate" ]]; then
        WP_CLI_BIN="$candidate"
        break
      fi
    done
  fi
}

# If we're using a Windows PHP binary from WSL, convert the WP-CLI phar path to
# a Windows path so `php.exe` can open it.
if [[ "$WP_CLI_BIN" == *.exe && "${#WP_CLI_ARGS[@]}" -gt 0 ]]; then
  if command -v wslpath >/dev/null 2>&1; then
    for idx in "${!WP_CLI_ARGS[@]}"; do
      if [[ "${WP_CLI_ARGS[$idx]}" == /mnt/* ]]; then
        WP_CLI_ARGS[$idx]="$(wslpath -w "${WP_CLI_ARGS[$idx]}")"
      fi
    done
  fi
fi

"$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" i18n make-pot . "$POT_FILE" \
  --slug=language-learner-tools \
  --domain=ll-tools-text-domain \
  --exclude=offline-app-builder,tests \
  --skip-audit

# When WP-CLI runs through Windows PHP, it may emit absolute drive-letter paths in
# `#:` location lines inside the POT. Those paths are machine-specific noise and
# make diffs harder to review, so normalize back to plugin-relative paths.
# Normalize absolute plugin paths (Windows or WSL) back to plugin-relative paths.
perl -0pi -e 's{(?m)^#:\s+.*?(?:wp-content[\/\\]plugins[\/\\]language-learner-tools[\/\\])}{#: }g' "$POT_FILE"

if [[ "${LL_TOOLS_I18N_POT_ONLY:-0}" == "1" ]]; then
  exit 0
fi

# WP-CLI locale update commands can corrupt non-ASCII (Turkish) PO content when
# invoked through some Windows PHP binaries from WSL. Default to POT-only mode
# in that environment unless explicitly overridden.
if [[ "$WP_CLI_BIN" == *.exe && "${LL_TOOLS_I18N_ALLOW_WINDOWS_PHP_LOCALE_UPDATE:-0}" != "1" ]]; then
  echo "Skipping update-po/make-mo/make-php (Windows PHP under WSL detected). Set LL_TOOLS_I18N_ALLOW_WINDOWS_PHP_LOCALE_UPDATE=1 to force." >&2
  exit 0
fi

"$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" i18n update-po "$POT_FILE" "$TR_PO_FILE"
"$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" i18n make-mo "$TR_PO_FILE" languages
"$WP_CLI_BIN" "${WP_CLI_ARGS[@]}" i18n make-php "$TR_PO_FILE" languages

# WP-CLI may emit CRLF here.
perl -0pi -e 's/\r\n?/\n/g' "languages/ll-tools-text-domain-tr_TR.l10n.php"
