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
- `chunk-03-wordset-category-taxonomies` [P2] Line-Up category settings synchronously load and render every word in the category.
  Evidence: `ll_tools_get_category_lineup_allowed_word_ids()` fetches every `words` post ID in a category with `posts_per_page => -1` at `includes/taxonomies/word-category-taxonomy.php:1667-1683`. `ll_tools_get_category_lineup_word_items()` then hydrates every matching word post for the edit UI at `includes/taxonomies/word-category-taxonomy.php:1832-1847`, and the taxonomy edit form renders one sortable row per item at `includes/taxonomies/word-category-taxonomy.php:2215-2254`. The vocab lesson category settings path also includes the same full item list in its settings payload at `includes/pages/vocab-lesson-pages.php:3498-3511`, and both save paths validate submitted order by reloading the full allowed-word list at `includes/taxonomies/word-category-taxonomy.php:2283` and `includes/pages/vocab-lesson-pages.php:4037-4051`.
  Likely impact: a category with thousands of words can make normal category edit or wordset-manager settings pages slow or unusable, and saving the settings repeats the full scan even when only a short order changed.
  Suggested fix: replace the inline all-words sorter with a paged/searchable Line-Up manager that loads candidate words on demand, validates submitted IDs in bounded chunks, and stores order changes through a nonce/capability-guarded endpoint with progress or clear batch limits.
  Suggested verification: seed a large category and assert the initial category/settings render does not include all word rows; add a focused save test proving submitted Line-Up IDs are validated without an unbounded category-wide query.
- `chunk-03-wordset-category-taxonomies` [P2] Wordset grammar option saves can run full-wordset metadata rewrites synchronously.
  Evidence: the normal wordset term save handler `ll_save_wordset_language()` handles grammar option POST data after nonce/capability checks at `includes/taxonomies/wordset-taxonomy.php:3476-3528`. Changing gender/plurality/verb-tense/verb-mood options calls sync helpers at `includes/taxonomies/wordset-taxonomy.php:3840-3843`, `includes/taxonomies/wordset-taxonomy.php:3938-3939`, `includes/taxonomies/wordset-taxonomy.php:3976-3977`, and `includes/taxonomies/wordset-taxonomy.php:4014-4015`. Those helpers start with `ll_tools_wordset_get_word_ids_with_meta_in_wordset()`, which queries all matching words in the wordset with `posts_per_page => -1` at `includes/taxonomies/wordset-taxonomy.php:4449-4465`, then loop each word to update/delete post meta and collect category cache bumps, for example `includes/taxonomies/wordset-taxonomy.php:4499-4543`.
  Likely impact: changing grammar option labels on a large wordset can turn an ordinary settings save into thousands of post-meta reads/writes and term lookups, risking timeouts or partial updates without durable progress.
  Suggested fix: make grammar-option remapping an explicit bounded maintenance job with a dry-run/count step, cursor-backed batches, status/readback, and a small synchronous path only below a safe threshold.
  Suggested verification: add a regression that seeds many words with grammar meta, changes an option list, and asserts the settings handler queues or processes bounded batches instead of scanning every matching word in one request.
- `chunk-03-wordset-category-taxonomies` [P2] Wordset isolation health checks rebuild full-site scans from admin notices.
  Evidence: the isolation health report transient TTL is only 15 minutes at `includes/wordset-isolation.php:16-17`, and `ll_tools_render_wordset_isolation_health_notice()` calls `ll_tools_get_wordset_isolation_health_report()` from `admin_notices` for maintenance-capable users at `includes/wordset-isolation.php:2399-2414`. A cache miss builds all collectors at `includes/wordset-isolation.php:2333-2350`; those collectors query all `words` (`includes/wordset-isolation.php:1971-1978`), all `word_images` (`includes/wordset-isolation.php:2080-2087`), all vocab lessons (`includes/wordset-isolation.php:2184-2191`), and all wordsets (`includes/wordset-isolation.php:2244-2248`). The related auto-repair hook can also run on `admin_init` every 5 minutes and, after detecting one anomaly, calls a full vocab-lesson repair scan at `includes/wordset-isolation.php:764-800` and `includes/wordset-isolation.php:745-752`.
  Likely impact: the first admin page load after a transient miss can unexpectedly scan and inspect the whole site's words, word images, vocab lessons, and wordsets. On production-sized sites this can slow unrelated admin workflows, especially after cache invalidation.
  Suggested fix: move isolation health refresh and auto-repair behind an explicit maintenance action or async job that records durable status; make admin notices read only the last cached status and offer a "check now" action rather than rebuilding the report inline.
  Suggested verification: add a focused admin-notice regression that expires the health transient on a seeded large fixture and asserts ordinary admin page rendering does not call the full collectors; cover the explicit refresh path separately.

## Completed Chunks

- `chunk-01-core-runtime` completed 2026-07-07 00:28 Europe/Istanbul. Reviewed entry point/update hooks, bootstrap load order, asset enqueue/cache guards, template loader, flashcard shell, PHP compatibility helper, sort/text helpers, and plugin templates. No findings recorded for this chunk.
- `chunk-02-data-model-roles` completed 2026-07-07 00:28 Europe/Istanbul. Reviewed post types, smaller taxonomies, role helpers, privacy/login/class runtime, and related admin/public hook surfaces. Recorded three boundedness findings above; no safe source-code fix was made in this heartbeat because the fixes need lazy-loading or query-shape changes with focused tests.
- `chunk-03-wordset-category-taxonomies` completed 2026-07-07 00:34 Europe/Istanbul. Reviewed word-category and wordset taxonomy helpers, wordset isolation/health code, wordset template cloning helpers, wordset language settings, entity translations, word translation helpers, and relevant integration-test coverage. Recorded three boundedness findings above; no safe source-code fix was made in this heartbeat because the fixes need paged UI, durable jobs, or async maintenance flows with focused tests.
