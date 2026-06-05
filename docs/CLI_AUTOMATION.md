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

It does not yet replace every importer, audio-processing, or attribution-backfill workflow. Those can be added on top of the same `ll-tools` WP-CLI namespace later.
