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

"$WP_CLI" i18n update-po "$POT_FILE" "$TR_PO_FILE"
"$WP_CLI" i18n make-mo "$TR_PO_FILE" languages
"$WP_CLI" i18n make-php "$TR_PO_FILE" languages

# WP-CLI may run through Windows PHP in Local/WSL setups and emit CRLF here.
perl -0pi -e 's/\r\n?/\n/g' "languages/ll-tools-text-domain-tr_TR.l10n.php"
