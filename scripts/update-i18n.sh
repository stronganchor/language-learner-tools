#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

WP_CLI="${WP_CLI:-wp}"
POT_FILE="languages/ll-tools-text-domain.pot"
TR_PO_FILE="languages/ll-tools-text-domain-tr_TR.po"

"$WP_CLI" i18n make-pot . "$POT_FILE" \
  --slug=language-learner-tools \
  --domain=ll-tools-text-domain \
  --exclude=offline-app-builder,tests \
  --skip-audit

# When WP-CLI runs through Windows PHP, it may emit absolute drive-letter paths in
# `#:` location lines inside the POT. Those paths are machine-specific noise and
# make diffs harder to review, so normalize back to plugin-relative paths.
perl -0pi -e 's{(?m)^#:\s+[A-Za-z]:\\\\[^\\n]*\\\\wp-content\\\\plugins\\\\language-learner-tools\\\\}{#: }g' "$POT_FILE"

"$WP_CLI" i18n update-po "$POT_FILE" "$TR_PO_FILE"
"$WP_CLI" i18n make-mo "$TR_PO_FILE" languages
"$WP_CLI" i18n make-php "$TR_PO_FILE" languages

# WP-CLI may run through Windows PHP in Local/WSL setups and emit CRLF here.
perl -0pi -e 's/\r\n?/\n/g' "languages/ll-tools-text-domain-tr_TR.l10n.php"
