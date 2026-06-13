# Stress 2x Wordset Findings

Date: 2026-06-13

The `stress-2x` performance profile creates one 5000-word wordset, sized around
2x the live Genc-Palu word count. Each word has a `word_images` post,
attachment metadata, and three `word_audio` posts, for 15000 audio recordings.
The fixture uses a small materialized pool of existing Word Boat source
images/audio so the database shape is large without copying thousands of unique
media files.

## Latest Local Baseline

Command:

```bash
LL_PERF_PROFILE=stress-2x LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_COMPARE_HISTORY=0 LL_E2E_PERF_MAX_INTERACTION_MS=60000 tests/bin/run-performance-benchmark.sh
```

Result: passed in 1.4 minutes against fixture `2026-06-13-stress-2x.2`.

| Scenario | Primary Metric |
| --- | ---: |
| learn-grid-stress2x-load | 3185 ms |
| wordset-stress2x-main-load | 3948 ms |
| wordset-stress2x-search-filter | 1043 ms |
| wordset-stress2x-games-load | 3547 ms |
| learn-grid-stress2x-quiz-popup | 100 ms |
| wordset-stress2x-progress-load | 6390 ms |
| wordset-stress2x-progress-words-tab | 196 ms |

## Findings

- Vocab lesson deepest-count queries previously built large repeated
  `UNION ALL` depth tables and hit a MySQL thread-stack overrun during stress
  seeding. The count path now reduces deepest categories in PHP, and the depth
  table helper now uses a taxonomy-backed `CASE` expression instead of one
  `UNION ALL` arm per category.
- Vocab lesson "with images" deepest counts also exposed a large
  effective-image `EXISTS` query over `wp_postmeta`. The count path now fetches
  candidate word/category rows first and applies the batched effective-image
  presence map in PHP. In the local run this reduced
  `wordset-stress2x-progress-load` from 15775 ms to 6390 ms.
- Naive stress fixture seeding was not viable at this size. Copying a unique
  source media file per word, deleting each old fixture post through normal
  WordPress delete hooks, and sending every synthetic post through normal
  post-insert hooks caused multi-hour runs. The seeder now materializes a small
  Word Boat media pool, bulk-deletes fixture-tagged post rows on reset, cleans
  the fixture upload directory on reset, defers WordPress recount/cache work
  during reseed, and uses raw fixture inserts for synthetic posts.
- The no-force fixture validation path also needed large-fixture hardening. It
  now uses direct SQL counts instead of `WP_Query` found-row counts and expects
  `ll_quiz_page` quiz posts separately from the single learn-grid `page`, so a
  valid stress fixture is reused instead of being unnecessarily reset.
- The slowest completed browser scenario is currently
  `wordset-stress2x-progress-load` at about 6.4 seconds under the throttled
  profile. That is the first user-facing area to inspect for large-wordset
  optimization after fixture and vocab-count fixes.
- A previous cold full-benchmark run with the default 20 second interaction cap
  timed out in `wordset-stress2x-search-filter`, while direct backend profiling
  showed category-search index build at 14 ms and matching at 72 ms. The current
  larger fixture completed the measured search in 1043 ms with the 60 second
  interaction cap, so treat cold search/AJAX warmup behavior as a harness or
  first-hit follow-up before raising production concerns from that timeout
  alone.

## Follow-Up: Progress Page Latency

`wordset-stress2x-progress-load` is still a known follow-up. The 6390 ms
`firstActionableMs` result means the authenticated Progress page needed about
6.4 seconds under the benchmark throttle before
`[data-ll-wordset-progress-root]` was visible. That is materially better than
the 15775 ms pre-fix run, but it is still slow enough that it should not be
treated as resolved simply because the benchmark passes.

This likely needs a focused review and a coordinated set of changes rather than
another isolated query tweak. Review at least:

- the server-side Progress route and
  `ll_tools_build_user_study_analytics_payload()`;
- remaining category/content/vocab summary queries, including whether they can
  be cached, materialized, or batched more deliberately;
- authenticated page-shell behavior, with a bias toward rendering the shell
  first and loading slower panels asynchronously;
- browser-side hydration and rendering cost for the Progress page;
- network payload size and whether the initial payload still stays bounded for
  5000-word and 15000-audio wordsets;
- regression coverage around `wordset-stress2x-progress-load` and
  `wordset-page-progress-loading.spec.js`.

Candidate success criteria: get the stress-profile Progress page to first
actionable in 3000 ms or less, or explicitly choose and document a different
budget after profiling the remaining server, network, and browser costs.
