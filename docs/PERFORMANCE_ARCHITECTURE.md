# Performance Architecture

This is the working map for performance changes in LL Tools. Read it after
`CODEBASE_ARCHITECTURE.md` when a task touches loading time, query volume,
large wordsets, bulk media, or any page that can grow with `words`,
`word_audio`, `word_images`, prompt cards, or generated media.

## Core invariant

Large wordsets are a normal production case. Interactive requests should be
bounded by a page size, a visible shell count, an explicit user query, or a
cached/materialized aggregate. Full scans and hydration of every word in a
wordset belong only in explicit maintenance jobs, bounded imports/exports, or
admin flows that show progress and can be resumed.

When a fix needs broader context, use `docs/ai-context/task-router.md` and
generate a local context pack with `scripts/build-ai-context-pack.php` instead
of sending the whole plugin to an external model.

## Hot paths

| Surface | Primary files | Watch points |
| --- | --- | --- |
| Flashcard and quiz payloads | `includes/shortcodes/flashcard-widget.php`, `includes/taxonomies/word-category-taxonomy.php` | Treat `ll_get_words_by_category()` as an expensive hydration path. Use `ll_get_words_by_category_count()` or bounded candidate IDs when only counts, eligibility, or a launch subset are needed. |
| Wordset landing pages, search, lazy cards | `includes/pages/wordset-pages.php`, `js/wordset-pages.js` | Keep the first paint bounded. Use category summaries, shell cards, and AJAX hydration instead of loading every word/media item. Avoid per-word postmeta loops in request setup. |
| Wordset game launch pools | `includes/pages/wordset-games.php`, `js/wordset-games.js` | Keep game setup capped by the selected round size or candidate pool. Do not hydrate all words just to render the catalog or choose a launch subset. |
| Wordset editor/settings | `includes/pages/wordset-editor.php` | Keep editor rows paged. Respect `ll_tools_wordset_editor_can_use_paged_query()` and avoid falling back to all-row builds unless the operation is intentionally small or filtered. |
| Word grid and vocabulary lessons | `includes/shortcodes/word-grid-shortcode.php`, related JS/CSS | Use renderable ID queries and capped shell ordering. Avoid fetching all media when the page can hydrate visible cards incrementally. |
| Transcription/IPA manager | `includes/admin/ipa-keyboard-admin.php`, `js/ipa-keyboard-admin.js` | Keep initial admin load to the first visible rows and lazy-load the rest. Validation hooks are allowed to be deeper, but the admin UI should not hydrate every target by default. |
| User progress and study metrics | `includes/user-progress.php`, `includes/pages/wordset-pages.php` | Prefer aggregate rows and bounded category lookups. Be careful with user-specific joins over every word in a large wordset. |
| Dictionary search | `includes/lib/dictionary-search-index.php`, `includes/lib/dictionary-browser.php`, `includes/shortcodes/dictionary-shortcode.php` | Route public search through indexed/searchable fields. Avoid broad `postmeta LIKE` scans on public requests. |
| Public/static cache | `includes/lib/public-static-cache.php`, `includes/lib/dictionary-static-cache.php` | Keep anonymous cache keys deterministic and safe. Respect max-byte guards, nonce placeholder refresh behavior, locale rules, and targeted purge hooks. |
| Imports, site sync, automation | `includes/api/automation-rest.php`, `includes/lib/site-sync.php`, `includes/admin/export-import.php` | Treat heavy work as server-owned jobs. REST endpoints should control, enqueue, and report status instead of doing unbounded work inline. |
| Performance benchmark | `tests/performance/`, `tests/e2e/specs/performance-benchmark.spec.js`, `tests/e2e/helpers/performance-benchmark.js` | Keep the default benchmark affordable. Use the XL profile before arguing that a change helps large production wordsets. |

## Evidence workflow

1. Identify the surface and the growth dimension: categories, words, media,
   user progress rows, prompt cards, or generated assets.
2. Reproduce with the nearest page, PHPUnit test, Playwright spec, or
   performance benchmark scenario. If the current fixture is too small, use the
   XL benchmark profile rather than extrapolating from a tiny case.
3. Capture the evidence that explains the slowdown: scenario duration, query
   count, response payload size, DOM node count, network waterfall, or a clear
   code-level unbounded loop.
4. Make the smallest change that changes the asymptotic behavior or removes the
   measured bottleneck. Cosmetic refactors do not count as performance work.
5. Re-run the same evidence path and a focused regression test.

## Benchmark commands

The normal benchmark stays modest enough for routine local use:

```bash
tests/bin/run-performance-benchmark.sh
```

For a quick smoke run:

```bash
LL_E2E_PERF_RUNS=1 tests/bin/run-performance-benchmark.sh
```

For XL coverage, use the opt-in XL profile:

```bash
LL_PERF_PROFILE=xl tests/bin/run-performance-benchmark.sh
```

The XL profile uses `tests/performance/fixtures/performance-wordsets-xl.json`,
targets `benchmarkTargetSize: "xl"`, defaults to one run per scenario, and
writes to `tests/performance/history/performance-history-xl.jsonl` plus
`tests/performance/reports/performance-latest-xl.*`.

The benchmark writes history when run through `tests/bin/run-performance-benchmark.sh`.
It also writes latest JSON and Markdown summaries under
`tests/performance/reports/`.
Use a longer command timeout for full runs; a timeout means the runner stopped,
not necessarily that the page under test failed.

To inspect existing history without reseeding or running Playwright:

```bash
node scripts/summarize-performance-history.js
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-xl.jsonl --scenario wordset-xl
```

## Local context packs

Context packs are generated, local-only summaries of related source files. They
are intended for architecture review, bug investigation, and performance
planning when the whole plugin is too large to read at once.

```bash
php scripts/build-ai-context-pack.php --list
php scripts/build-ai-context-pack.php --pack wordset-vocab-manager
php scripts/build-ai-context-pack.php --pack performance-benchmark --changed-only --manifest-only
php scripts/build-ai-context-pack.php --pack performance-benchmark --output -
```

Packs include git change-frequency hints for their source files. For
performance work, treat hot files as likely entry points, but keep following
the measured growth dimension and owner path when quiet files are the real
source of truth.

Generated packs default to `test-results/ai-context/`, which is ignored. Do not
commit generated packs unless there is a specific reason.
