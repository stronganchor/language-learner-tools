# Overnight Automated Code Review TODO

Started: 2026-07-06 23:38 Europe/Istanbul
Target branch: `dev`
Review base HEAD: `1ecfbeb3688dde36fac9917990189fed6b1f3406`
Cadence: once every 20 minutes until 2026-07-07 09:00 Europe/Istanbul

The chunk manifest and machine-local run state live at:

```text
C:\Users\messy\.codex\automation-state\ll-tools-overnight-code-review
```

## Operating Rules

- Review the next pending chunk from the manifest on each heartbeat.
- Treat this as a code-review pass: prioritize correctness, security, performance, large-wordset behavior, i18n readiness, capability/nonce checks, missing tests, and stale docs.
- Append follow-up items under `Findings TODO` with chunk id, severity, evidence, suggested fix, and suggested verification.
- Safe minor fixes may be made immediately, especially documentation, source-contract, typo, or narrow test-maintenance updates.
- Do not do broad refactors, live-site writes, release/version bumps, UI redesigns, or product behavior changes as part of the heartbeat unless a chunk reveals a small clear bug fix with focused verification.
- Commit completed repo changes after each heartbeat pass, following `AGENTS.md`.
- Stop instead of mutating if the branch or current allowed HEAD in the machine-local state no longer matches the checkout.

## Coverage Plan

The run is sized for 28 review chunks, which fits the remaining 20-minute slots before the 2026-07-07 09:00 Europe/Istanbul cutoff. Chunks cover tracked first-party source, docs, scripts, tests, styles, templates, locale sources, and first-party offline-app builder code. Vendor, generated artifacts, binary media, test reports, built release output, and the embedded third-party Whisper/GGML source tree are excluded from normal overnight coverage.

## Findings TODO

- `chunk-02-data-model-roles` [P2] Content lesson edit screens preload option rows for every wordset.
  Evidence: `includes/post-types/content-lesson-post-type.php:1073-1098` builds `rowsByWordset`, `prereqRowsByWordset`, and `prereqLessonRowsByWordset` for every wordset whenever a content lesson edit screen enqueues its admin JS. Those helpers include unbounded per-wordset lesson/category reads, for example `ll_tools_get_content_lesson_prereq_lesson_option_rows()` at `includes/post-types/content-lesson-post-type.php:715-729` and the selected-row fill-in query at `includes/post-types/content-lesson-post-type.php:959-967`.
  Likely impact: a site with many wordsets, categories, or content lessons pays the cost on every content lesson edit page, even when the editor is only working in one wordset. The localized JS payload can also become very large.
  Suggested fix: localize only the current lesson wordset plus an empty/default state, then lazy-load category/prereq lesson options for a newly selected wordset through a nonce- and `view_ll_tools`-guarded AJAX/REST endpoint with bounded result shape.
  Suggested verification: add/extend a content-lesson admin test that seeds multiple wordsets with many categories/lessons and asserts the initial localized payload only contains the active wordset; add a focused browser/admin check that switching wordsets fetches options once and keeps the edit page responsive.
- `chunk-02-data-model-roles` [P2] Dictionary admin filters scan or render all dictionary entries.
  Evidence: the Words list-table filter builds a `<select>` by fetching every `ll_dictionary_entry` ID at `includes/post-types/dictionary-entry-post-type.php:1662-1688`. The dictionary entry list-table filtering path calls `ll_tools_dictionary_entry_get_admin_list_ids()` at `includes/post-types/dictionary-entry-post-type.php:1878-1888`, then loops every entry in `includes/post-types/dictionary-entry-post-type.php:2258-2303`; the linked/unlinked branch calls `ll_tools_count_dictionary_entry_words()` for each entry, which runs a `WP_Query` at `includes/post-types/dictionary-entry-post-type.php:842-863`.
  Likely impact: large dictionary imports can turn normal admin list loads into full-table scans plus many per-entry queries, and the Words filter dropdown can produce thousands of `<option>` elements.
  Suggested fix: replace the Words dropdown with the existing bounded dictionary search/autocomplete pattern, and make dictionary-entry filters SQL/meta-query driven or backed by a compact materialized index instead of iterating all entries in PHP.
  Suggested verification: seed several thousand dictionary entries with mixed source/wordset/linked states, then assert filtered admin queries do not call `get_posts()` for every entry and do not run per-entry linked-count queries.
- `chunk-02-data-model-roles` [P2] Public vocab lesson prompt-card detection loads every matching prompt card.
  Evidence: `templates/vocab-lesson-template.php:179-188` calls `ll_tools_get_vocab_lesson_prompt_card_posts()` during page render, which delegates at `includes/pages/vocab-lesson-pages.php:826-872` to `ll_tools_get_prompt_card_posts_for_category_context()`. That helper uses `posts_per_page => -1` and hydrates full `WP_Post` rows with meta and term caches in `includes/post-types/prompt-card-post-type.php:212-252`; deepest-category filtering happens after the full result set is loaded.
  Likely impact: a public vocab lesson in a prompt-card-heavy category can hydrate all prompt cards before rendering, even if the page only needs to know whether custom prompt-card mode is active or needs a small shell count.
  Suggested fix: split existence/count checks from full payload retrieval, use ID-only capped queries for render decisions, and defer full prompt-card data to the existing bounded grid/lazy payload path.
  Suggested verification: add a vocab lesson regression with many prompt cards in one category that asserts initial render uses capped/ID-only queries and still renders the correct prompt-card shell state.

## Completed Chunks

- `chunk-01-core-runtime` completed 2026-07-07 00:28 Europe/Istanbul. Reviewed entry point/update hooks, bootstrap load order, asset enqueue/cache guards, template loader, flashcard shell, PHP compatibility helper, sort/text helpers, and plugin templates. No findings recorded for this chunk.
- `chunk-02-data-model-roles` completed 2026-07-07 00:28 Europe/Istanbul. Reviewed post types, smaller taxonomies, role helpers, privacy/login/class runtime, and related admin/public hook surfaces. Recorded three boundedness findings above; no safe source-code fix was made in this heartbeat because the fixes need lazy-loading or query-shape changes with focused tests.
