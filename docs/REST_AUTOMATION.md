# LL Tools REST Automation

Language Learner Tools now exposes a small REST surface for the same automation
workflows that were recently added to WP-CLI.

This is the better fit when you want to keep the current workflow of giving
Codex a temporary WordPress admin or manager account instead of SSH access to
the server.

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
10. Use `POST /wordsets/{wordset}/bulk-update` with `dry_run=true` before any
   write operation.
11. Re-run the same request without `dry_run` to apply changes. The server
    applies write batches in small chunks by default, so keep the returned
    `resume_state` and repeat until `batch.has_more` is false.
12. Keep automation calls serial. If the server returns `429`, wait at least
    the `Retry-After` header value before retrying. On slow live sites, add a
    larger client-side delay even after successful write requests.
13. For word-option groups, call
    `POST /wordsets/{wordset}/word-option-rules` with `dry_run=true` before
    applying the same payload without `dry_run`.
14. For bundle imports, preview with `POST /imports/preview`, start with
    `POST /imports/start`, then poll `POST /imports/{job_id}/process` until the
    job is completed.
15. Fetch `GET /imports/{job_id}/result` for final stats, warnings, errors,
    undo availability, and the import history entry ID.
16. Delete or downgrade the temporary user when the session is complete.

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
- The guarded routes return HTTP `429` with `Retry-After` and
  `data.retry_after_seconds` when another automation write just ran. Wait that
  long before retrying the exact same request.
- REST-driven bundle imports process word images in smaller chunks than the
  wp-admin path.

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
- `POST /cache/static/purge`
- `POST /wordsets`
- `GET /wordsets/{wordset}/missing-meta`
- `POST /wordsets/{wordset}/bulk-update`
- `POST /wordsets/{wordset}/word-title-updates`
- `POST /wordsets/{wordset}/transcriptions`
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
updates only `post_title`, and performs cache cleanup once for the batch. Use
the generic `/bulk-update` route for metadata changes or word resolution by
slug/title. On live sites, send title updates in small chunks and honor
`Retry-After` exactly; do not parallelize this endpoint.

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

`resume_state` is the REST equivalent of the CLI `--resume-file` feature. Send
back the response's `resume_state` object on the next request to skip words that
were already processed successfully.

Typical uses:

- backfilling `part_of_speech`
- backfilling `grammatical_gender`
- attaching `dictionary_entry_title`
- updating `word_note` without resending the entire word row

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

### `GET /wordsets/{wordset}/site-sync/snapshot`

Returns a wordset-scoped sync snapshot for site-to-site workflows. The first
supported surface is `transcriptions`, which includes `word_audio` recording
text, recording transcription, transcription review flags, and review notes.

Query params:

- `surface` optional, currently `transcriptions`
- `ensure_sync_ids` optional boolean, default true
- `include_media` optional boolean, default true; set to false for transcription-only push comparisons
- `per_page` optional integer for paged snapshots
- `offset` optional integer offset for paged snapshots

The response includes stable LL Tools sync IDs where available. When
`ensure_sync_ids` is true, missing sync IDs are generated and stored on the
remote records so staging and live can keep matching the same recordings after a
pull.

Use this route from the LL Site Sync admin page or external automation before a
three-way merge. It is not a database clone endpoint; it intentionally returns a
domain-specific content snapshot for safe diffing.

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
- the task is wordset creation, metadata cleanup, or reporting

Use WP-CLI instead when:

- Codex has shell access to the server
- the task needs local filesystem operations or direct command chaining
- you are running large maintenance flows entirely inside a trusted server shell

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
