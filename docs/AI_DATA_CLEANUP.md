# AI Data Cleanup Runbook

Use this runbook when an agent needs to clean up LL Tools wordset data: category
membership, titles, helper translations, word text, part of speech, grammar
metadata, dictionary links, or similar per-word fields.

This is plugin-wide guidance. Site-specific linguistic rules, category names,
live credentials, and artifact paths belong in the relevant site/project folder.

## Operating Model

Treat REST as the control plane and LL Tools jobs as the execution plane.

For more than a few rows, do not replay wp-admin tables, submit broad forms, or
send one live write per field. Instead:

1. Pull a current snapshot of the target wordset.
2. Analyze the snapshot locally.
3. Produce an explicit per-word plan with final values.
4. Include expected old values wherever possible.
5. Submit the plan to a bounded server-side job.
6. Process the job serially in chunks.
7. Fetch the result and a fresh snapshot.
8. Compare the fresh snapshot against the intended payload.

The preferred route for mixed word metadata cleanup is:

```text
POST /wp-json/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs
POST /wp-json/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/process
GET  /wp-json/ll-tools/v1/wordsets/{wordset}/word-metadata-plan-jobs/{job_id}/result
```

The preferred route for category-only changes to explicit word IDs is:

```text
POST /wp-json/ll-tools/v1/wordsets/{wordset}/word-category-updates
```

Use `word-category-updates` for small add/remove/move operations. Use
`word-metadata-plan-jobs` when a cleanup batch touches final category state plus
other metadata, or when hundreds of rows need explicit final values.

## Snapshot First

For local planning, use the site-sync metadata snapshot:

```text
GET /wp-json/ll-tools/v1/wordsets/{wordset}/site-sync/snapshot?surface=metadata&ensure_sync_ids=0&include_media=0&per_page=0
```

The metadata snapshot is the best general planning surface because it includes:

- one row per word;
- post ID, sync ID, slug, title, and status;
- word text, translation, English meaning, notes, dictionary link, part of
  speech, grammatical gender/plurality, verb metadata, and missing flags;
- category IDs, slugs, names, and parent slugs;
- audio summaries and recording metadata;
- linked word image/media summary when requested;
- wordset-level settings, category ordering, manual order, and prerequisites.

For transcription-heavy cleanup, use `surface=transcriptions` as well. For media
or category coverage checks, also save `report-summary` and use the full report
only when per-category detail is needed.

Keep snapshots and derived CSV/JSON plans as local artifacts. Future agents
should be able to answer "what did you intend to change?" and "what live state
did you verify after changing it?" from files, not just chat history.

## Plan Shape

A metadata plan row should describe one final state for one word:

```json
{
  "word_id": 12345,
  "expected": {
    "part_of_speech": "noun",
    "grammatical_gender": ""
  },
  "set": {
    "grammatical_gender": "Feminine"
  },
  "note": "Short operator note for this cleanup batch."
}
```

Use one row per word. Do not submit multiple rows that fight over the same word.
For option-backed fields such as grammatical gender, plurality, verb tense, and
verb mood, use the target wordset's actual allowed values or labels; these can
be localized or site-specific.

Use `expected` guards when stale live data would make the change unsafe. Good
guards include:

- `word_category_ids` when moving categories from a known current state;
- `word_text`, `word_translation`, or `word_title` when normalizing labels;
- `part_of_speech` before setting noun-only or verb-only metadata;
- blank grammar fields before filling inferred values.

If an `expected` value does not match, the row should skip rather than overwrite
newer human or agent work.

## Live Write Rules

- Acquire the site's live automation lease or project lock before writes.
- Run dry runs where the route supports them.
- Keep automation calls serial. Do not process two write jobs at the same time.
- Honor `429 ll_tools_rest_resource_guard_wait` by waiting the reported delay
  and retrying the same request.
- Prefer `purge_public_static_cache=false` during intermediate chunks. Purge
  only when the cleanup affects public cached output and verification shows the
  stale cache matters.
- Release the live lease after writes.
- Verify both route-level result JSON and a fresh site-sync snapshot.
- Check the public site or `/wp-json/` after live writes on resource-constrained
  servers.

## Category Cleanup

Category cleanup is not just taxonomy tidying. LL Tools quizability depends on
category size, prompt/option settings, images, audio, and wordset assignment.

General rules:

- Keep learner-facing quizzable categories at 5 or more usable items unless the
  user explicitly wants a non-quizzable holding category.
- Avoid huge mixed categories. Prefer coherent distractor sets and consistent
  parts of speech.
- Do not create near-duplicate live categories. Search current category names
  and slugs first.
- When moving words, decide whether linked `word_images` should move too. Use
  `sync_linked_images=true` when image posts should follow the word category.
- Verify quizzability after category cleanup. A word can be published, imaged,
  and assigned to a wordset but still not quizzable if its only learner-facing
  category is under the minimum size or has incompatible category settings.

## Metadata Cleanup

Use metadata snapshots for planning, not live table screens. Admin screens can
hide important fields or show stale/cache-derived values.

Common conventions:

- Use word IDs as the stable write target.
- Preserve live sync IDs; do not regenerate them during ordinary cleanup.
- For title/text/translation normalization, identify which field actually drives
  the learner-facing page before changing titles. Some sites show `word_text`
  and use the WordPress title mainly as an admin label.
- Keep local artifacts for before/after values, row counts, skipped rows, and
  conflicts.
- Record conflicts separately instead of forcing a guess into live data.

## Review Before Writing

Before applying a generated plan, inspect:

- total row count;
- counts by operation and target field;
- first 20-50 rows;
- category distribution;
- skipped/domain-excluded rows;
- conflicts and ambiguous evidence;
- whether any row would become non-quizzable;
- whether a row belongs to the intended wordset.

If a candidate list looks too broad, tighten the filter and rerun planning
locally. It is better to leave uncertain rows in a review CSV than to fill live
metadata with plausible but unverified guesses.

## Verification

After processing a job:

1. Fetch the job result.
2. Save the result JSON.
3. Fetch a fresh metadata snapshot.
4. Compare the fresh snapshot against the exact payload.
5. Report mismatches, skipped rows, and error rows.
6. Save conflict/review CSVs next to the payload and result.

Successful result JSON is not enough by itself. The fresh snapshot is the final
confirmation that the intended values landed in live LL Tools metadata.

## What To Avoid

- Do not submit a broad wp-admin wordset form for a small metadata task.
- Do not let a browser or interrupted Codex session keep a long import/process
  runner open without checking the live job state.
- Do not assume a timed-out request failed. Poll the existing job/result first.
- Do not increase PHP-FPM pool size as the first fix for a data-cleanup job that
  is causing worker saturation. Prefer bounded jobs, smaller chunks, and less
  unnecessary recomputation.
- Do not use live REST writes as a search interface. Search the local snapshot.
- Do not overwrite rows whose live values changed since the snapshot.
