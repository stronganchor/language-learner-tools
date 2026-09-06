# LL Tools CLI Automation

Language Learner Tools now exposes a small WP-CLI surface for the workflows that were previously forcing Codex to log into `wp-admin`, scrape nonces, or replay AJAX forms.

Use these commands when you want stable, scriptable access to wordset setup and word metadata maintenance.

Use hyphenated subcommands in automation, for example `wordset-report` and
`word-bulk-update`. The older underscore forms remain available as compatibility
aliases.

## Why this is better than browser automation

- No admin login flow required.
- No nonce scraping.
- Uses wordset slugs/names/IDs and word slugs/titles/IDs instead of brittle DOM selectors.
- Supports dry runs, machine-readable summaries, and resumable bulk work.

## Bulk and long-running work

WP-CLI is the preferred execution surface when Codex has trusted server shell
access and the operation is too heavy for one synchronous HTTP request. Use it
for maintenance that touches hundreds of rows and performs expensive validation,
media work, taxonomy repair, cache rebuilding, filesystem access, or direct
command chaining.

REST automation can still be the control plane for live Codex sessions that only
have WordPress credentials, but heavy work should be implemented as bounded
chunks or a server-owned job with durable progress and result files. Do not add a
new REST endpoint that scans and mutates a large live dataset in one request when
a WP-CLI command or job-style REST wrapper would make the operation resumable.

## Running commands

From the plugin root, use either the helper script:

```bash
bash bin/ll-wp.sh wordset-report spanish
```

Or call WP-CLI directly from anywhere:

```bash
wp --path=/path/to/site/public ll-tools wordset-report spanish
```

The helper script only resolves the WordPress root and forwards to `wp`. It does not install WP-CLI for you.

## Commands

### Resume wordset isolation maintenance

```bash
wp ll-tools wordset-isolation-migrate --format=json
```

This command resumes the durable, site-wide wordset-isolation migration and
reports its status. It performs writes immediately; it has no `--dry-run` or
wordset argument. Use it only for an authorized migration or repair, with a
current backup. `--allow-large-option-rules` explicitly lifts the background
option-rule size guard for this CLI run. The command exits with an error unless
the returned migration status is `completed`.

The implementation and persisted migration/reconciliation state live in
`includes/wordset-isolation.php`; the command wrapper lives in
`includes/cli/class-ll-tools-cli-command.php`. See [CODEBASE_ARCHITECTURE.md](../CODEBASE_ARCHITECTURE.md)
for the migration and generated-page reconciliation invariants.

### Migrate legacy post-based lessons

`wp ll-tools legacy-lessons-migrate` is a separate guarded migration for
post-based lessons, prerequisite links, and user completions. It is dry-run by
default and advances in bounded ID-cursor pages. Follow
[LEGACY_LESSON_MIGRATION_RUNBOOK.md](LEGACY_LESSON_MIGRATION_RUNBOOK.md) for
scope freezing, the required lessons-to-relations-to-completions order,
completion run IDs, retries, readback gates, and old-plugin cutover.

### Create a wordset

Create a blank wordset:

```bash
bash bin/ll-wp.sh wordset-create "Spanish Nouns"
```

Create one from a template and assign a manager:

```bash
bash bin/ll-wp.sh wordset-create "Spanish Travel" --template=travel-template --manager=codex
```

### Show missing word metadata

All missing metadata in a wordset:

```bash
bash bin/ll-wp.sh wordset-missing-meta spanish
```

Only noun/gender gaps in one category, with JSON output:

```bash
bash bin/ll-wp.sh wordset-missing-meta spanish \
  --category=household-items \
  --fields=part_of_speech,grammatical_gender \
  --format=json \
  --summary-file=tmp/spanish-missing.json
```

### Safe partial word updates

Dry-run a bulk gender fill before changing anything:

```bash
bash bin/ll-wp.sh word-bulk-update spanish \
  --category=household-items \
  --where-missing=grammatical_gender \
  --set=grammatical_gender=Feminine \
  --dry-run \
  --summary-file=tmp/spanish-gender-dry-run.json
```

Run the same change for real, with resume support:

```bash
bash bin/ll-wp.sh word-bulk-update spanish \
  --category=household-items \
  --where-missing=grammatical_gender \
  --set=grammatical_gender=Feminine \
  --resume-file=tmp/spanish-gender-resume.json \
  --summary-file=tmp/spanish-gender-apply.json
```

Set part of speech for a small batch:

```bash
bash bin/ll-wp.sh word-bulk-update spanish \
  --where-missing=part_of_speech \
  --set=part_of_speech=noun \
  --limit=25
```

Update one word by stable identifier:

```bash
bash bin/ll-wp.sh word-bulk-update spanish \
  --word=casa \
  --set=word_note="Common everyday form"
```

Supported update fields:

- `word_translation`
- `word_note`
- `dictionary_entry_title`
- `part_of_speech`
- `grammatical_gender`
- `grammatical_plurality`
- `verb_tense`
- `verb_mood`

Use a separate resume file for each wordset, field/value, and filter plan. The
current resume reader skips recorded word IDs without validating the stored
operation metadata against the new command. `--limit` limits the rows selected
for updating after scope rows are loaded; it is not a query or memory bound.

### Dump a live wordset report

```bash
bash bin/ll-wp.sh wordset-report spanish --summary-file=tmp/spanish-report.json
```

The report includes:

- wordset identity
- key wordset settings
- category counts
- missing metadata counts by field
- image coverage
- audio coverage
- attribution coverage counts

### Audit progress-event payload keys without exposing learner data

The maintenance-only scanner inventories the stored event types, modes, payload
key names, nested key names, row counts, and payload sizes. It never prints
payload values or learner identifiers, and it hashes unknown key names by
default:

```bash
wp --path=/path/to/site/public eval-file \
  wp-content/plugins/language-learner-tools/scripts/audit-progress-event-payloads.php
```

Each run freezes a `high_water_id` and scans at most 50,000 rows. If
`complete` is false, resume from `last_scanned_id` while preserving that exact
high-water mark:

```bash
LL_TOOLS_AUDIT_START_ID=50000 \
LL_TOOLS_AUDIT_HIGH_WATER_ID=106306 \
wp --path=/path/to/site/public eval-file \
  wp-content/plugins/language-learner-tools/scripts/audit-progress-event-payloads.php
```

Set `LL_TOOLS_AUDIT_SHOW_SAFE_UNKNOWN_KEYS=1` only during an authorized schema
review. It reveals unknown names only when they match the conservative
lowercase key pattern; it still omits values. Treat the report as sensitive
operational evidence even though it is designed not to contain personal data.

## Recommended Codex workflow

1. Run `wordset-report` to confirm you are on the expected live wordset.
2. Run `wordset-missing-meta` to find the exact backlog.
3. Run `word-bulk-update ... --dry-run` first.
4. Inspect the summary JSON.
5. Re-run without `--dry-run`, keeping `--resume-file` and `--summary-file`.

## Current scope

This CLI surface currently targets:

- wordset creation, including template cloning
- word-level metadata inspection
- safe partial word metadata updates
- machine-readable reporting
- durable wordset-isolation maintenance
- bounded legacy lesson, prerequisite, and completion migration

It does not yet replace every importer, audio-processing, or attribution-backfill workflow. Those can be added on top of the same `ll-tools` WP-CLI namespace later.

## Source and test map

| Surface | Implementation | Focused tests |
| --- | --- | --- |
| Registration, aliases, options and resume files | `includes/bootstrap.php`, `includes/cli/class-ll-tools-cli-command.php` | No dedicated WP-CLI command integration test; inspect command docblocks and call sites |
| Shared word resolution and metadata helpers | `includes/cli/cli-support.php` | `tests/Integration/AutomationRestApiTest.php`, `tests/Integration/WordTextCanonicalFieldsTest.php`, `tests/Integration/WordsetScopedCategoryLookupTest.php` |
| Legacy lesson migration | `includes/migrations/legacy-content-lessons.php` | `tests/Integration/LegacyContentLessonMigrationTest.php` |

Use `wp help ll-tools <subcommand>` for the current argument contract; the CLI
class docblocks are the source for WP-CLI help.
