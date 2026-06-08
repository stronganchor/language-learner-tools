# LL Tools REST Automation

Language Learner Tools now exposes a small REST surface for the same automation
workflows that were recently added to WP-CLI.

This is the better fit when you want to keep the current workflow of giving
Codex a temporary WordPress admin or manager account instead of SSH access to
the server.

For AI-planned wordset data cleanup, read `AI_DATA_CLEANUP.md` first. That
runbook explains the snapshot -> local plan -> bounded job -> result -> fresh
snapshot workflow for category, title, helper, part-of-speech, grammar, and
other per-word metadata cleanup.

## Operating model

Treat REST automation as the control plane for Codex-driven WordPress work. REST
is the right interface for authentication checks, readback, reports, dry runs,
job creation, progress polling, result retrieval, cache purges, and bounded
mutation chunks. It should not be expected to complete every expensive
multi-hundred-record operation inside one long synchronous HTTP request.

For large or heavy changes, make the server own the execution:

- Use an existing job-style REST workflow when one exists, such as
  `imports/preview -> imports/start -> imports/{job_id}/process ->
  imports/{job_id}/result`.
- Use WP-CLI when Codex has trusted shell access and the work can run entirely
  server-side.
- Add a purpose-built REST job wrapper when Codex only has WordPress
  credentials but the work is too expensive for one request. The REST request
  should start or advance a server-side job; the job should checkpoint progress
  and expose machine-readable status and results.

This is a throughput and reliability boundary, not a limitation on Codex making
bulk changes. Codex can still plan, dry-run, launch, monitor, and verify changes
to hundreds of records. The important distinction is that PHP/WordPress should
do heavyweight row processing in resumable chunks with durable state instead of
holding one HTTP request open until the whole operation finishes.

When adding a new bulk workflow, prefer this shape:

- `dry_run` or preview first, with the exact matched count and representative
  per-row decisions.
- A start request that records operation name, scope, idempotency key or input
  manifest hash, and the caller/lease context.
- A process or background runner that handles bounded chunks, saves a cursor,
  and can resume after timeout or disconnect.
- A status/readback route with counts, current cursor, recent errors, and
  whether the job is paused, failed, or complete.
- A final result route with changed IDs, skipped IDs, warnings, errors,
  before/after summaries, and follow-up verification hints.
- Old-value guards or version checks where overwriting stale live data would be
  risky.

## Recommended workflow

For a normal Codex session against a live LL Tools site:

1. Create a temporary WordPress user.
2. Prefer `administrator` when Codex needs to create wordsets, change sitewide
   settings, or work across multiple wordsets.
3. Use `wordset_manager` when Codex only needs to work inside one assigned
   wordset.
4. Assign the manager to the correct wordset before starting the session.
5. Give Codex the site URL plus the temporary username and password.
6. First call `GET /automation/status` to confirm authentication and capability
   scope.
7. If public anonymous HTML is stale after a site edit, call
   `POST /cache/static/purge` to clear the LL dictionary/public static caches.
8. Then call `GET /wordsets/{wordset}/report-summary` to confirm the exact
   target wordset and current coverage without running the heavier full report.
9. Use `GET /wordsets/{wordset}/missing-meta` to discover the current backlog.
10. For AI-planned batches that touch multiple word metadata fields or final
    category assignments, prefer `word-metadata-plan-jobs`: take a metadata
    snapshot, submit explicit per-word final states with expected current values,
    then process the job in serial chunks.
11. Use the narrowest synchronous write route for small single-surface jobs, with
    `dry_run=true` first:
    `word-title-updates` for title-only maintenance, `word-helper-updates` for
    helper/known-language translation repair, and `bulk-update` for bounded
    mixed metadata edits.
12. Re-run the same request without `dry_run` to apply changes. The server
    applies write batches in small chunks by default, so keep the returned
    `resume_state` and repeat until `batch.has_more` is false.
13. Keep automation calls serial. If the server returns `429`, wait at least
    the `Retry-After` header value before retrying. On slow live sites, add a
    larger client-side delay even after successful write requests.
14. For word-option groups, call
    `POST /wordsets/{wordset}/word-option-rules` with `dry_run=true` before
    applying the same payload without `dry_run`.
15. For bundle imports, preview with `POST /imports/preview`, start with
    `POST /imports/start`, then poll `POST /imports/{job_id}/process` until the
    job is completed.
16. Fetch `GET /imports/{job_id}/result` for final stats, warnings, errors,
    undo availability, and the import history entry ID.
17. For LL Tools dev-channel plugin updates, call
    `POST /automation/plugin-update` with `dry_run=true` first. Apply only with
    `dry_run=false`, `confirm=true`, and `expected_current_version` set to the
    version you just observed from status/readback.
18. For a new workflow that needs to touch hundreds of rows and each row does
    expensive validation, media work, taxonomy repair, cache rebuilding, or
    cross-post recomputation, prefer a WP-CLI command or a job-style REST route
    before adding a synchronous REST endpoint.
19. Delete or downgrade the temporary user when the session is complete.

This sequence keeps the workflow close to how Codex already operates in
wp-admin, but removes nonce scraping and form replay.

## Server-side safety limits

LL Tools intentionally limits automation writes so a Codex session cannot flood
the server with image, media, or metadata updates:

- `bulk-update` defaults to 10 write rows per request, with a hard default max
  of 10. Dry runs default to 50 rows, with a hard default max of 100.
- `word-title-updates` defaults to 5 write rows per request, with a hard
  default max of 10. Dry runs default to 50 rows, with a hard default max of
  100.
- `word-helper-updates` defaults to 10 write rows per request, with a hard
  default max of 25. Dry runs default to 50 rows, with a hard default max of
  100.
- `word-metadata-plan-jobs` accepts a server-side plan of explicit word IDs and
  final metadata values. Processing defaults to 10 rows per request, with a hard
  default max of 25 rows per request. The plan itself defaults to a hard max of
  500 items.
- `missing-meta` returns a paged response by default: 100 rows per request, with
  a hard default max of 250.
- Basic-auth REST writes to `/wp/v2/media`, `/wp/v2/word_images`, and
  `/wp/v2/words` are serialized by a lightweight resource guard.
- Basic-auth probes to `/wp/v2/users/me` and `GET /automation/status` are also
  paced by the same guard so automation scripts do not fill the PHP-FPM pool
  with repeated auth/status checks while a write is already running.
- Basic-auth automation writes that can mutate or rebuild larger LL Tools
  surfaces are also serialized, including static-cache purge, wordset writes,
  import preview/start/process/discard, and corpus-text asset/import routes.
- Plugin update automation is also serialized. It supports the fixed Strong
  Anchor GitHub dev branch package only, defaults to dry-run, and requires
  explicit confirmation for writes.
- The guarded routes return HTTP `429` with `Retry-After` and
  `data.retry_after_seconds` when another automation write just ran. Wait that
  long before retrying the exact same request.
- REST-driven bundle imports process word images in smaller chunks than the
  wp-admin path.

These caps are intentional live-site protection. They do not mean that REST
automation is only useful for very small projects. For hundreds of records,
drive repeated bounded chunks or a server-owned job and keep durable result
state between calls.

Check `GET /automation/status` for the live site's current `resource_guard`
values before starting large work. Site owners can adjust the defaults with the
documented WordPress filters in the plugin code, but automation callers should
always honor the response payload instead of assuming a fixed batch size.

## Authentication

The LL Tools automation routes support three auth paths:

- Logged-in WordPress browser session plus REST nonce.
- WordPress Application Passwords.
- LL Tools password-based Basic auth for the `ll-tools/v1` namespace.

The password-based Basic auth path is designed for temporary WordPress users.
It uses the site's normal WordPress username and password directly, so Codex
can authenticate without creating an application password first.

Security notes:

- Use HTTPS on live sites.
- The plugin blocks password-based Basic auth on non-HTTPS requests unless the
  request is clearly local development.
- Prefer temporary users and remove them when the automation session is done.

## Local WSL helper

On this machine, the Local site listener can be reachable from Windows but not
from WSL's Linux networking stack. When Codex is running in WSL, use the helper
wrapper instead of Linux `curl`:

```bash
bash bin/ll-rest-local.sh /wp-json/ll-tools/v1/automation/status -u codex-temp:YOUR_PASSWORD
```

The wrapper resolves the Local site URL with `wp option get home` and sends the
request through Windows `curl.exe`, which can reach the Local listener reliably.

Example:

```bash
bash bin/ll-rest-local.sh /wp-json/ll-tools/v1/wordsets/english/report \
  -u codex-temp:YOUR_PASSWORD
```

## Endpoints

Base namespace:

```text
/wp-json/ll-tools/v1
```

Routes:

- `GET /automation/status`
- `POST /automation/plugin-update`
- `POST /cache/static/purge`
- `POST /wordsets`
- `GET /wordsets/{wordset}/missing-meta`
- `POST /wordsets/{wordset}/bulk-update`
- `POST /wordsets/{wordset}/word-title-updates`
- `POST /wordsets/{wordset}/word-helper-updates`
- `POST /wordsets/{wordset}/word-category-updates`
- `POST /wordsets/{wordset}/word-category-terms`
- `POST /wordsets/{wordset}/word-metadata-plan-jobs`
- `GET /wordsets/{wordset}/word-metadata-plan-jobs/{job_id}`
- `POST /wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process`
- `POST /wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/discard`
- `GET /wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result`
- `POST /wordsets/{wordset}/transcriptions`
- `POST /wordsets/{wordset}/transcription-validations`
- `POST /wordsets/{wordset}/transcription-validation-jobs`
- `GET /wordsets/{wordset}/transcription-validation-jobs/{job_id}`
- `POST /wordsets/{wordset}/transcription-validation-jobs/{job_id}/process`
- `GET /wordsets/{wordset}/site-sync/snapshot`
- `POST /wordsets/{wordset}/word-option-rules`
- `GET /wordsets/{wordset}/orthography-conversion`
- `POST /wordsets/{wordset}/orthography-conversion`
- `PUT /wordsets/{wordset}/orthography-conversion`
- `PATCH /wordsets/{wordset}/orthography-conversion`
- `GET /wordsets/{wordset}/profile`
- `POST /wordsets/{wordset}/profile`
- `PUT /wordsets/{wordset}/profile`
- `PATCH /wordsets/{wordset}/profile`
- `GET /wordsets/{wordset}/translations`
- `POST /wordsets/{wordset}/translations`
- `PUT /wordsets/{wordset}/translations`
- `PATCH /wordsets/{wordset}/translations`
- `POST /wordsets/{wordset}/prompt-cards`
- `GET /wordsets/{wordset}/report`
- `GET /wordsets/{wordset}/report-summary`
- `GET /wordsets/{wordset}/review-notes`
- `POST /wordsets/{wordset}/review-notes`
- `GET /wordsets/{wordset}/interlinear`
- `POST /wordsets/{wordset}/interlinear`
- `POST /imports/preview`
- `POST /imports/start`
- `GET /imports/{job_id}`
- `POST /imports/{job_id}/process`
- `POST /imports/{job_id}/discard`
- `GET /imports/{job_id}/result`
- `POST /corpus-texts/asset`
- `POST /corpus-texts/import`
- `GET /corpus-texts/{slug}`

Word-option-rule updates should use `POST
/wordsets/{wordset}/word-option-rules` instead of wp-admin form replay around
`ll_tools_save_word_option_rules_async`.

Orthography conversion automation should use `GET
/wordsets/{wordset}/orthography-conversion` to inspect manual IPA conversion
rules, orthography settings, approved exception word IDs, and
`exception_dictionary_entry_ids`. Use `POST`, `PUT`, or `PATCH` with
`dry_run=true` first when changing them. Lexical `word_overrides` may remain a
simple `source: replacement` map for legacy unscoped overrides, or may be bound
to a dictionary entry by sending each override as an object with
`replacement`/`to` and `dictionary_entry_id`. Callers may also send a companion
`word_override_entry_ids` map keyed by source text, or include `word_id` on an
override so the server can infer the linked dictionary entry. Bound lexical
overrides and approved mismatch exceptions only apply while the word is linked
to the same dictionary entry.

Interlinear automation should use `GET /wordsets/{wordset}/interlinear` to list
content/vocab lessons and current payload status. Add `lesson=<post ID, slug, or
interlinear lesson ID>`, `post_type=ll_content_lesson|ll_vocab_lesson`,
`include_empty=0`, or `include_payload=0` to narrow the response. Use `POST
/wordsets/{wordset}/interlinear` with either one payload object or an `items`
array. Each item can identify the target lesson by post ID, slug, lesson value,
interlinear lesson ID, or vocab category. Send `dry_run=true` first, and send
`delete=true` or `clear=true` to remove an existing payload.

For historical or corpus-text publishing, use `POST /corpus-texts/import` with a
text-document payload. Use `kind=corpus_text`, `reading_units` for public
side-by-side text/translation rows, `source_lines` for the public Interlinear
view, and optional document-level `witnesses` plus line-level source images for
scan evidence and source citations. Upload or attach referenced corpus assets
through `POST /corpus-texts/asset`, and read the saved corpus text through `GET
/corpus-texts/{slug}`. Regular interlinear `tokens` may be included on each
`source_lines` row. Rows listed in `hidden_rows` are skipped; empty POS rows and
lemma rows that only duplicate the word/morph row are hidden automatically.

`{wordset}` can be a stable wordset slug such as `spanish` or `genc-palu`.

## Basic auth examples

Check auth and route availability:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/automation/status
```

Dry-run a dev-channel LL Tools plugin update:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/automation/plugin-update \
  -d '{ "dry_run": true }'
```

Apply the same update after checking the dry-run payload:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/automation/plugin-update \
  -d '{
    "dry_run": false,
    "confirm": true,
    "expected_current_version": "6.5.27",
    "expected_version": "6.5.28"
  }'
```

Clear stale LL dictionary/public static HTML caches:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  https://example.com/wp-json/ll-tools/v1/cache/static/purge
```

Clear only one static cache:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  "https://example.com/wp-json/ll-tools/v1/cache/static/purge?cache=dictionary"
```

Dump a live report:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/report-summary
```

List words that are still missing metadata:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  "https://example.com/wp-json/ll-tools/v1/wordsets/spanish/missing-meta?fields=part_of_speech,grammatical_gender&limit=100"
```

Dry-run a bulk update:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "category": "household-items",
    "where_missing": ["grammatical_gender"],
    "set": { "field": "grammatical_gender", "value": "feminine" },
    "limit": 10,
    "dry_run": true
  }'
```

Apply the same update for real:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "category": "household-items",
    "where_missing": ["grammatical_gender"],
    "set": { "field": "grammatical_gender", "value": "feminine" },
    "limit": 10
  }'
```

Fast guarded word-title maintenance:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/word-title-updates \
  -d '{
    "dry_run": true,
    "updates": [
      { "word_id": 123, "old_title": "Old title", "title": "New title" },
      { "word_id": 456, "old_title": "Second old title", "title": "Second new title" }
    ]
  }'
```

This endpoint is for title-only maintenance where each row already has a known
word ID. It preserves slugs, skips rows whose `old_title` no longer matches,
updates only `post_title`, and performs cache cleanup once for the batch. On
live sites, send title updates in small chunks and honor `Retry-After` exactly;
do not parallelize this endpoint.

Fast guarded helper/known-language translation maintenance:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/word-helper-updates \
  -d '{
    "dry_run": true,
    "updates": [
      {
        "word_id": 123,
        "old_word_english_meaning": "Bad helper text",
        "word_english_meaning": "Fixed helper text"
      },
      {
        "word_id": 456,
        "old_word_translation": "Bad stored translation",
        "word_translation": "Fixed stored translation",
        "word_english_meaning": "Fixed helper text"
      }
    ]
  }'
```

Use this endpoint for broad repair of `word_translation` or
`word_english_meaning` when each row already has a known word ID. It validates
that each word belongs to the requested wordset, supports optional old-value
guards, writes only those two meta keys, returns a compact before/after payload,
and performs cache cleanup once for the batch. Do not use the generic
`/bulk-update` route for large live helper-translation repair: that route
rebuilds full editor rows and can be much heavier per word. Keep calls serial
and honor `Retry-After`; if the live site is slow, add a client-side delay after
successful writes too.

Create and process a guarded mixed word-metadata plan:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs \
  -d '{
    "updates": [
      {
        "word_id": 123,
        "set": {
          "word_category_ids": [8336],
          "word_english_meaning": "wring out a wet cloth"
        },
        "expected": {
          "word_category_ids": [8231, 8336],
          "word_english_meaning": ""
        }
      },
      {
        "word_id": 456,
        "set": {
          "word_title": "Corrected target-language form",
          "word_translation": "Corrected helper text",
          "part_of_speech": "verb"
        },
        "expected": {
          "word_title": "Old target-language form"
        }
      }
    ]
  }'
```

The create response returns `job.id`. Process it serially until
`job.status=completed`:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs/JOB_ID/process \
  -d '{ "limit": 10 }'
```

Fetch the durable result:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/word-metadata-plan-jobs/JOB_ID/result
```

Use this for AI-assisted category reorganization and mixed metadata cleanup. It
does not scan the full wordset for each row: the caller supplies explicit word
IDs and desired final values. Each row may include `expected` values from a
recent `site-sync/snapshot`; stale rows are skipped with `reason:
expected_mismatch` instead of overwritten. Supported set/expected fields include
`word_title`, `word_text`, `word_translation`, `word_english_meaning`,
`word_note`, `dictionary_entry_id`, `dictionary_entry_title`, `part_of_speech`,
`grammatical_gender`, `grammatical_plurality`, `verb_tense`, `verb_mood`, and
`word_category_ids`.

Dry-run staged word-option groups by category slug and word slugs:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/biblical-greek/word-option-rules \
  -d '{
    "category_slug": "awa-lesson-01-2-masculine-big-and-small",
    "dry_run": true,
    "groups": [
      {
        "label": "size small",
        "word_slugs": [
          "awa-phrase-kamelos-mikros",
          "awa-phrase-bous-mikros",
          "awa-phrase-hippos-mikros",
          "awa-phrase-onos-mikros"
        ]
      }
    ]
  }'
```

Apply the same word-option groups for real by removing `"dry_run": true`.

Create a wordset from a template:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets \
  -d '{
    "name": "Spanish Travel",
    "slug": "spanish-travel",
    "template": "travel-template",
    "manager": "codex-temp"
  }'
```

Preview a server-side bundle from the LL Tools import folder:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/imports/preview \
  -d '{ "existing": "ll-tools-export-spanish.zip" }'
```

Start the previewed import:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/imports/start \
  -d '{ "preview_token": "PREVIEW_TOKEN_FROM_PREVIOUS_RESPONSE" }'
```

Advance the import job until `job.status` is `completed`:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  https://example.com/wp-json/ll-tools/v1/imports/JOB_ID/process
```

Fetch the durable machine-readable result:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  https://example.com/wp-json/ll-tools/v1/imports/JOB_ID/result
```

Dry-run a part-of-speech backfill for only two words:

```bash
curl -u codex-temp:YOUR_PASSWORD \
  -X POST \
  -H "Content-Type: application/json" \
  https://example.com/wp-json/ll-tools/v1/wordsets/spanish/bulk-update \
  -d '{
    "set": { "field": "part_of_speech", "value": "noun" },
    "where_missing": ["part_of_speech"],
    "dry_run": true,
    "limit": 2
  }'
```

## Route behavior

### `GET /automation/status`

Returns the authenticated user, auth mode, LL Tools capability checks, and the
available automation routes. Use this as the first smoke test on a live site.

### `POST /automation/plugin-update`

Runs a guarded LL Tools dev-channel plugin update through WordPress'
`Plugin_Upgrader`.

Body fields:

- `dry_run` optional boolean, default `true`
- `confirm` optional boolean, required as `true` when `dry_run=false`
- `channel` optional, default `dev`; `configured` and `current` are accepted but
  currently must resolve to `dev`
- `expected_current_version` optional precondition checked before any download
- `expected_version` optional post-update expectation returned in the response

The route is intentionally narrow: it only uses the fixed Strong Anchor GitHub
dev branch package URL, never an arbitrary caller-provided URL. Dry-runs return
the currently loaded/file version, plugin key, package URL, and confirmation
requirements without downloading anything. Real writes require an administrator
with `update_plugins` and `view_ll_tools`, use WordPress' standard plugin
upgrader path with temporary updater backups, and return the before/after file
versions plus upgrader messages.

This route cannot bootstrap itself onto an older live site. Use wp-admin upload,
WordPress' normal updater, or a one-time server-side replacement to install the
first LL Tools version that contains it.

### `POST /wordsets`

Creates a blank wordset or clones one from a template.

Body fields:

- `name` required
- `slug` optional
- `template` optional wordset slug, name, or ID
- `manager` optional user login, email, or ID

Use this route when Codex needs to create a fresh working copy from a reusable
template without logging into wp-admin taxonomy screens first.

### `GET /wordsets/{wordset}/missing-meta`

Returns the machine-readable missing-metadata report.

Query params:

- `category` optional category slug or name
- `fields` optional comma-separated list or repeated array
- `limit` optional, default 100 and capped at 250 unless the site customizes it
- `offset` optional

Use this route before a metadata-editing session so Codex can see the exact
remaining rows instead of inferring gaps from the UI. Large wordsets are paged:
continue with `batch.next_offset` until `batch.has_more` is false.

### `POST /wordsets/{wordset}/bulk-update`

Applies a partial metadata update without resending whole word rows.

Body fields:

- `set` required
  - Either `"field=value"` or `{ "field": "...", "value": "..." }`
- `updates` optional array for distinct per-word updates; each item accepts `word` or `word_id` plus `field`/`value` or `set`
- `category` optional
- `word` optional
- `where_missing` optional list or comma-separated string
- `where_pos` optional part-of-speech slug
- `limit` optional, default 10 for writes and capped at 10 unless the site
  customizes it
- `offset` optional
- `dry_run` optional
- `resume_state` optional object returned by a prior run

Responses include `total_matched_count`, `matched_count`, `batch.has_more`, and
the next `resume_state`. For writes, prefer resume-state pagination over a large
`offset` so retries do not repeat rows that were already processed.

When `updates` is present, the route applies those distinct updates directly and
does not use `set`, `category`, `limit`, `offset`, or `resume_state`. This mode is
capped at 250 updates per request.

Supported update fields:

- `word_title` / `post_title` (direct WordPress post title)
- `word_text`
- `word_translation`
- `word_english_meaning` (direct helper/known-language translation meta)
- `word_note`
- `dictionary_entry_title`
- `part_of_speech`
- `grammatical_gender`
- `grammatical_plurality`
- `verb_tense`
- `verb_mood`

For title-as-translation wordsets, `word_translation` can be the target-language
storage slot rather than the helper-language translation. Use
`word_english_meaning` for direct Turkish/helper translation recovery or repair
passes when you do not want the endpoint to apply display-mode title swapping.
For broad live repair of `word_translation` or `word_english_meaning`, prefer
`POST /wordsets/{wordset}/word-helper-updates` because it avoids full row
rebuilds and coalesces cache cleanup.

`resume_state` is the REST equivalent of the CLI `--resume-file` feature. Send
back the response's `resume_state` object on the next request to skip words that
were already processed successfully.

Typical uses:

- backfilling `part_of_speech`
- backfilling `grammatical_gender`
- attaching `dictionary_entry_title`
- updating `word_note` without resending the entire word row

### `POST /wordsets/{wordset}/word-helper-updates`

Updates helper/known-language translation metadata for words that already have
known word IDs. This route is intended for efficient live repair of corrupted or
missing `word_translation` and `word_english_meaning` values.

Body fields:

- `updates` required array
- `word_id` or `id` required per update
- `word_translation` optional direct stored translation value
- `word_english_meaning` optional direct helper/known-language value
- `value` plus `field=word_translation` or `field=word_english_meaning`
  optional alternative syntax
- `old_word_translation` optional stale-value guard
- `old_word_english_meaning` optional stale-value guard
- `dry_run` optional boolean

Dry runs and writes return compact `before` and `after` values for only the two
supported fields. The write cap defaults to 10 rows and is capped at 25 rows
unless the site customizes the REST automation filters.

### `POST /wordsets/{wordset}/word-category-updates`

Moves, adds, or removes `word-category` terms for explicit word IDs without
loading the full Wordset Editor table or replaying wp-admin forms. Use this for
small live category repairs where the intended rows are already known.

Body fields:

- `operation` required; one of `add_category`, `remove_category`, or
  `move_category`
- `category_id` required source or target category ID, depending on operation
- `target_category_id` required for `move_category`
- `word_ids` required array of explicit `words` post IDs
- `dry_run` optional boolean; send true first
- `sync_linked_images` optional boolean; default true, keeps linked
  `word_images` category membership aligned with the words
- `max_updates` optional integer bounded by the route's current batch cap

The response includes `matched_count`, `changed_count`, `updated_count`,
per-word `before_category_ids` and `after_category_ids`, verification category
IDs for writes, and cache invalidation details. The write cap is intentionally
small; call `GET /automation/status` to see the current dry-run and write caps.
If the route returns `429 ll_tools_rest_resource_guard_wait`, wait the reported
`Retry-After` interval and retry the same request rather than launching another
parallel write.

For learner-facing categories, keep the resulting quiz pool at 5 or more
quizzable items. If a category would contain only 1-4 items, add the words to a
logical existing quizzable category, move them there, or hold them until enough
related words are ready.

### `POST /wordsets/{wordset}/word-category-terms`

Creates, renames, deletes empty, or updates prerequisites for `word-category`
terms that belong to one wordset. Use this for category taxonomy cleanup itself:
creating a destination category, renaming learner-facing labels, deleting an
empty retired category, or setting prerequisite IDs. Use
`word-category-updates` or `word-metadata-plan-jobs` for moving word posts
between categories.

Body fields:

- `actions` required array; `updates` is accepted as an alias
- `dry_run` optional boolean; defaults to true
- `purge_public_static_cache` optional boolean; default false

Supported action shapes:

```json
{"action":"create_category","name":"Doğa: Ağaç ve Bitki Parçaları","slug":"doga-agac-ve-bitki-parcalari-genc-palu","prereq_ids":[8093,8158]}
{"action":"rename_category","category_id":8238,"expected_name":"İhtiyaçlar","new_name":"İstekler ve İhtiyaçlar"}
{"action":"set_prerequisites","category_id":8238,"expected_prereq_ids":[8197],"prereq_ids":[8197,8252]}
{"action":"delete_empty_category","category_id":8366,"expected_name":"İstekler"}
```

The route resolves wordset-isolated effective category IDs, refuses categories
outside the requested wordset, validates prerequisite IDs, rejects prerequisite
cycles, and refuses to delete a category that still has word posts in the
wordset. Dry-run first, then send `dry_run=false` for the same action list.

### `POST /wordsets/{wordset}/word-metadata-plan-jobs`

Creates a durable, server-side job for AI-planned word metadata edits. Use this
when a session has a local metadata snapshot and wants to apply many explicit
per-word final states without reloading the full wordset or making one HTTP
write per field.

Create body fields:

- `updates` required array; `plans` is accepted as an alias
- `word_id` required per update
- `set` required per update; object keyed by supported field
- `expected` optional per update; object keyed by supported field, used as stale
  live-data guards
- `allow_empty_categories` optional boolean, default false
- `sync_linked_images` optional boolean, default true
- `purge_public_static_cache` optional boolean, default false

Supported `set` and `expected` fields:

- `word_title` / `post_title`
- `word_text`
- `word_translation`
- `word_english_meaning`
- `word_translations`
- `word_translation_{locale}`, such as `word_translation_en`,
  `word_translation_tr`, or `word_translation_de`
- `word_note`
- `dictionary_entry_id`
- `dictionary_entry_title`
- `part_of_speech`
- `grammatical_gender`
- `grammatical_plurality`
- `verb_tense`
- `verb_mood`
- `word_category_ids`

`word_text` is the target-language learner-facing word. `word_translation` is
the default/helper translation for the wordset's configured translation
language. `word_english_meaning` is a legacy helper-translation alias and may
contain a non-English language on older sites. Use `word_translations` or
`word_translation_{locale}` for additional future display languages rather than
putting parenthetical translations in titles or target text.

The create response returns `job.id`, normalized `plans`, counts, supported batch
limits, and no writes. Process with `POST
/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process`; send optional
`limit`, which defaults to 10 and is capped at 25 unless the site customizes the
filters. Each process call checkpoints `current_index`, coalesces cache
invalidation for the chunk, and returns processed rows. Repeat until
`job.status` is `completed`, then fetch `GET
/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result`.

If an `expected` value no longer matches live state, that row is skipped with
`reason: expected_mismatch`; other rows continue. This is the preferred route for
AI-assisted category reorganization and mixed metadata cleanup where the agent
has already decided the exact target word IDs and final values.

### `POST /wordsets/{wordset}/transcriptions`

Updates `word_audio` recording text, IPA, and review state for recordings that
belong to one wordset. Send `dry_run=true` first when applying corrections from
an external transcript review.

Body fields:

- `updates` optional array; omitted arrays treat the request body as one update
- `recording_id` or `word_audio_id` required per update
- `recording_text` or `text` optional
- `recording_ipa` or `ipa` optional
- `needs_review` optional boolean
- `review_fields` optional list, or `review_field` for one targeted field
- `review_note` optional; aliases accepted for field-specific review-note
  payloads
- `dry_run` optional boolean

The response includes `matched_count`, `updated_count`, per-record `before` and
`after` payloads, and structured `errors` for recordings outside the requested
wordset.

### `POST /wordsets/{wordset}/transcription-validations`

Runs bounded IPA/orthography validation for submitted transcription rows in one
wordset. Use `dry_run=true` first when checking a planned correction batch or
when you only need machine-readable validation feedback.

For large validation backlogs, prefer the job workflow instead of one synchronous
request:

- `POST /wordsets/{wordset}/transcription-validation-jobs`
- `GET /wordsets/{wordset}/transcription-validation-jobs/{job_id}`
- `POST /wordsets/{wordset}/transcription-validation-jobs/{job_id}/process`

The start request creates a durable validation job. The process route advances a
bounded chunk and checkpoints progress so callers can resume after timeout or a
resource-guard delay. Poll the job status until it reports completion, then use
the returned counts, warnings, and per-row validation decisions as the readback
artifact for the run.

For matcher or orthography-rule changes, create a job instead of running ad hoc
WP-CLI loops. Use `candidate_scope=issues` to refresh rows that are already
showing validation warnings. Use `candidate_scope=all` with `stale_only=false`
for a deliberate full recording rescan when a rule change could create warnings
from previously clean rows. Send `auto_process=true` to let the plugin schedule
bounded WP-Cron chunks; optional `auto_delay_seconds` and `auto_limit` control
the cadence. Automatic chunks default to one recording; raise `auto_limit` only
after observing the live pool under load. Send `settings_only=true` to the
process route when you need to enable, disable, or reschedule a job without
validating another row. The job status response includes
`auto_process.next_run_gmt`,
`remaining_count`, and recent row outcomes, so callers can monitor progress
without holding a terminal open.

The IPA-to-orthography rule engine is cached across validation chunks and is
invalidated when relevant wordset orthography settings, manual rules, blocklist
entries, or recording text/IPA metadata changes. When plugin code changes the
matcher semantics, bump the validation schema version so old validation rows are
recognized as stale before starting a refresh job.

### `GET /wordsets/{wordset}/site-sync/snapshot`

Returns a wordset-scoped sync snapshot for site-to-site workflows and local
search/staging artifacts.

Query params:

- `surface` optional; `transcriptions` or `metadata`, default `transcriptions`
- `ensure_sync_ids` optional boolean, default true
- `include_media` optional boolean, default true; set to false for lighter
  read-only comparison snapshots
- `per_page` optional integer for paged snapshots
- `offset` optional integer offset for paged snapshots

The response includes stable LL Tools sync IDs where available. When
`ensure_sync_ids` is true, missing sync IDs are generated and stored on the
remote records so staging and live can keep matching the same recordings after a
pull.

Use `surface=transcriptions` for recording text, recording transcription,
transcription review flags, review notes, and recording media references.

Use `surface=metadata` for a paged, one-row-per-word local snapshot. Metadata
records include:

- word ID, sync ID, slug, title, status, and modified time
- `word_text`, `word_translation`, `word_english_meaning`,
  `default_translation_locale`, `word_translations`, `word_note`, dictionary
  entry, part of speech, grammar fields, and missing-metadata flags
- all assigned `word-category` rows
- linked/effective `word_images` media when `include_media=true`
- word audio summaries and recording rows with recording types, transcription
  values, review state, and audio media when `include_media=true`
- `wordset_metadata` with wordset settings, category rows, category ordering,
  manual order, prerequisite map, and prerequisite level information

Use this route from the LL Site Sync admin page or external automation before a
three-way merge. It is not a database clone endpoint; it intentionally returns a
domain-specific content snapshot for safe diffing.

For a pure read-only local metadata copy, send `ensure_sync_ids=false`. If you
need durable cross-site matching for later sync work, send `ensure_sync_ids=true`
and treat the request as a live write because it may create missing UUID
metadata.

For most local search/staging workflows, prefer storing timestamped paged
`surface=metadata` JSON plus `report-summary` JSON rather than relying on a
local-site sync apply as the source of truth for a large live wordset.

Large wordsets should be read in pages. Paged responses include
`records_returned` and `pagination.next_offset`; continue until
`pagination.has_more` is false.

### `GET /wordsets/{wordset}/report`

Returns the full machine-readable wordset report with settings, coverage, and
per-category counts.

Use this route when you need the detailed missing-metadata and per-category
breakdown. On large live wordsets it can be slower than the summary route.

### `GET /wordsets/{wordset}/report-summary`

Returns fast live-verification counts without building every word row:

- wordset id, slug, and name
- key wordset language/settings values
- word count and category count
- words with audio and image coverage
- total audio record count

Use this route for live smoke tests after imports.

### `GET /wordsets/{wordset}/profile`

Returns learner-facing wordset profile metadata:

- wordset id, slug, and name
- language code / target language
- translation language
- intro blurb
- profile image attachment id, URL, and title

### `POST /wordsets/{wordset}/profile`, `PUT /wordsets/{wordset}/profile`, `PATCH /wordsets/{wordset}/profile`

Updates the learner-facing wordset profile. This is the automation-friendly way
to set the 16:9 thumbnail used by `[ll_wordset_buttons]` and the wordset page.
All three write methods use the same partial-update handler: omitted fields are
left unchanged, and the response includes `changed`, `changed_keys`, `before`,
and `after`.

Body fields:

- `profile_image_attachment_id` optional image attachment ID; send `0` to clear
- `profile_image`, `thumbnail`, `image`, or `file` optional multipart upload
- `profile_blurb` optional plain-text intro blurb
- `language_code` optional target language code/value
- `translation_language` optional helper/translation language code/value

Aliases `button_image_attachment_id`, `thumbnail_attachment_id`,
`intro_blurb`, `blurb`, and `target_language` are accepted for convenience.

### `GET /wordsets/{wordset}/review-notes`

Returns staff-only internal review notes for words and prompt cards in one
wordset.

Query params:

- `category` optional category slug or name
- `include_empty` optional boolean; include eligible rows with blank notes

Response fields:

- `generated_at_gmt`
- `wordset` object with `id`, `slug`, and `name`
- `filters` object with the applied category and `include_empty` values
- `count`
- `notes` rows with `object_type`, `object_id`, `note`, `title`,
  `categories`, `wordset_id`, and type-specific fields such as
  `word`/`translation` or prompt-card answer references

Use this route when Codex or another reviewer needs a durable review handoff
without scraping lesson-grid UI.

### `POST /wordsets/{wordset}/review-notes`

Creates, updates, or clears one staff-only internal review note.

Body fields:

- `object_id` required; a `words` or `ll_prompt_card` post ID
- `object_type` optional; `word` or `prompt_card`
- `note` required; send an empty string to clear the note

The object must belong to the requested wordset. If `object_type` is provided,
it must match the resolved object type.

The response includes the selected `wordset`, resolved `object_type`,
`object_id`, saved `note`, and normalized `row`.

### `POST /wordsets/{wordset}/prompt-cards`

Updates an existing `ll_prompt_card` inside the requested wordset without
loading wp-admin screens.

Body fields:

- `prompt_card_id`, `object_id`, or `id` required
- `prompt_text` or `prompt` optional
- `prompt_audio_url` or `prompt_audio` optional
- `prompt_audio_attachment_id` or `prompt_audio_id` optional
- `prompt_image_word_id` or `prompt_image_id` optional
- `correct_answer_word_id` or `answer_word_id` optional
- `wrong_answer_word_ids` or `wrong_answer_ids` optional
- `track_answer_word_progress` optional boolean
- `category_ids`, `categories`, `category`, or `category_slug` optional
- `wordset_ids` or `wordsets` optional; the requested wordset must remain in
  scope

The response returns the `before` and `after` prompt-card payloads,
`changed_keys`, and any bumped category cache IDs.

### `POST /imports/preview`

Prepares a bundle preview without wp-admin nonce scraping.

Body fields:

- `existing` optional server-side zip filename from the LL Tools import folder
- `filename` or `ll_import_existing` accepted as aliases for `existing`
- multipart upload field `file` or `ll_import_file` for direct zip uploads

Returns `preview_token`, bundle summary, warnings, source zip metadata, and the
default wordset import options.

### `POST /imports/start`

Starts an import job from a preview token.

Body fields:

- `preview_token` required
- `wordset_mode` optional, or legacy `ll_import_wordset_mode`
- `target_wordset_id` optional, or legacy `ll_import_target_wordset`
- `wordset_names` optional map, or legacy `ll_import_wordset_names`

Returns the created job snapshot.

### `GET /imports/{job_id}`

Returns the current job snapshot. Completed and paused snapshots include
`result` with final or partial stats, warnings, errors, undo availability, and
`historyEntryId` when the job has created an import history entry.

### `POST /imports/{job_id}/process`

Processes one import batch and returns the updated job snapshot. Keep calling it
until `job.status` is `completed`.

### `POST /imports/{job_id}/discard`

Discards a paused partial import and returns the cleanup result. This is only
valid for paused jobs.

### `GET /imports/{job_id}/result`

Returns the durable per-job result for completed or paused jobs. This is the
machine-readable replacement for parsing the short-lived wp-admin notice.

## Permissions

The routes still respect LL Tools permissions:

- Any automation caller must pass the `view_ll_tools` gate.
- Wordset-scoped routes also require access to manage that specific wordset.
- Review-note routes use the internal-review-note permission helper for the
  target wordset, so staff/managers must be allowed to manage notes there.
- Wordset creation requires `edit_wordsets`.
- Import routes require the same capability as the LL Tools import admin page
  (`manage_options` by default, filterable through
  `ll_tools_export_import_capability`).

That means a `wordset_manager` can use these routes for their assigned wordset
but not for unrelated wordsets.

## Quick decision guide

Use REST automation when:

- Codex only has WordPress credentials, not SSH
- you want to preserve the existing temp-user workflow
- the task is wordset creation, metadata cleanup, reporting, dry-run planning,
  job start/status/result polling, or bounded chunked writes
- the heavy work already has a job-style route that checkpoints progress

Use WP-CLI or a server-owned job instead when:

- Codex has shell access to the server
- the task needs local filesystem operations or direct command chaining
- the task touches hundreds of rows and each row performs expensive validation,
  media handling, taxonomy repair, cache rebuilding, or cross-post recomputing
- you are running large maintenance flows entirely inside a trusted server shell
- an operation would otherwise depend on one long synchronous HTTP request

## Common failure cases

- `401` on `/automation/status`
  - The username/password are wrong, or the request is not sending Basic auth.
- `403` with a wordset manager account
  - The user is authenticated but is not assigned to that specific wordset.
- `403` for password-based auth on a live site
  - The request is probably plain HTTP instead of HTTPS.
- Local WSL `curl` connection failures
  - Use `bash bin/ll-rest-local.sh ...` instead of Linux `curl`.

### `POST /wordsets/{wordset}/word-option-rules`

Saves word-option groups and optional blocked-pair rules for one category without
loading wp-admin HTML.

Body fields:

- `category`, `category_id`, or `category_slug` required
- `groups` or `word_option_groups` optional; omitted groups preserve existing groups
- `pairs` or `blocked_pairs` optional; omitted pairs preserve existing pairs
- `similar_image_overrides` optional; omitted overrides preserve existing overrides
- `dry_run` optional boolean

Group entries can use word IDs or slugs:

```json
{
  "category_slug": "awa-lesson-01-2-masculine-big-and-small",
  "dry_run": true,
  "groups": [
    {
      "label": "noun donkey",
      "word_slugs": [
        "awa-phrase-onos-megas",
        "awa-phrase-onos-mikros"
      ]
    }
  ]
}
```

The response includes the resolved wordset, category, normalized groups,
normalized pairs, `missing_words`, and validation `errors`. Dry-runs return the
same structure without writing. Non-dry-run validation failures return structured
JSON with the same `missing_words` and `errors` data instead of relying on a
nonce/form timeout.
