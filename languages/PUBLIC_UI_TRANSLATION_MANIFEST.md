# Tier-2 Public UI Translation Manifest

`tier2-public-ui-strings.json` is the canonical generated list of source
strings that should be translated for tier-2 languages. These are strings a
public visitor or learner can reasonably encounter without using wp-admin,
front-end manager tools, recorder queue tools, class-management tools, or
staff-only review/editing controls.

## Source Policy

`tier2-public-ui-sources.php` is the maintained source policy. It selects
public strings from the main POT by source file and, for mixed-purpose files,
by named PHP function or an anchor-bounded semantic region. Semantic anchors
must resolve exactly once inside their configured function or file; missing or
ambiguous anchors fail the checker instead of silently changing coverage when
source lines move. Numeric source ranges are not used.

The policy includes:

- Auth and learner account UI.
- Public wordset, vocab lesson, quiz, card, game, dictionary, and content lesson UI.
- Learner progress, category hiding/unhiding, study preferences, and game controls.
- Public text/interlinear display labels where those surfaces are visible.

The policy excludes:

- wp-admin, REST/CLI automation, and admin-only settings.
- Front-end manager-only settings, content editing, category-management tools,
  recorder queues, and teacher-class management.
- Staff-only review notes, internal editorial controls, and dictionary edit tools.

## Commands

Refresh the manifest after changing the POT or public source policy:

```bash
php scripts/check-public-i18n.php --update-manifest
```

Use `symbols` for a whole named PHP function and `regions` for a reviewed part
of a mixed function or template. Keep region anchors specific to stable code or
translation keys, and add a focused movement regression when introducing a new
selector pattern.

Check every configured tier-2 locale:

```bash
php scripts/check-public-i18n.php --all-tier2
```

Gate one locale after its PO file exists:

```bash
php scripts/check-public-i18n.php --locale=ru_RU --fail-on-missing
```

Emit concise JSON for automation:

```bash
php scripts/check-public-i18n.php --json --all-tier2
```

Add `--details` when a workflow needs the per-string missing/untranslated keys.

For translation wording and QA guidance, see
`languages/PUBLIC_UI_TRANSLATION_GUIDELINES.md`.

## Current Locale Tiers

Full core translations are tracked separately for Turkish (`tr_TR`) and German
(`de_DE`). Tier-2 public UI locales are configured in
`tier2-public-ui-sources.php`.

Current active tier-2 public UI locales include Russian (`ru_RU`), Spanish
(`es_ES`), French (`fr_FR`), Portuguese (Brazil) (`pt_BR`), Indonesian
(`id_ID`), Hindi (`hi_IN`), Korean (`ko_KR`), and Italian (`it_IT`). Planned but
not yet active tier-2 locales include Chinese Simplified (`zh_CN`), Arabic
(`ar`), and Bengali (`bn_BD`).

Tier-2 PO files may also include small supplemental source-backed batches added
by autonomous upkeep. The public UI manifest remains the coverage contract for
learner-facing strings; supplemental entries only reduce the full-source PO
backlog for admin/plugin metadata strings. The DeepL refresh script preserves
those supplemental entries while translating missing manifest entries. Core
full locales use `--scope=catalog` plus `--full-catalog=LOCALE` to translate and
verify every current POT entry without rewriting existing PO metadata.

The integration test `PublicUiTranslationManifestTest` keeps the generated
manifest synchronized with the current POT selection, verifies that Turkish
continues to translate every active POT entry, and requires every active tier-2
public locale to ship complete `.po`, `.mo`, and `.l10n.php` assets.
