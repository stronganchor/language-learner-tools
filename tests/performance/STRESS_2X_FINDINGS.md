# Stress 2x Wordset Findings

Date: 2026-06-13

The `stress-2x` performance profile creates one 4800-word wordset, based on
roughly 2x the local Genc-Palu artifact count of 2371 words. Each word has a
`word_images` post, attachment metadata, and a `word_audio` post. The fixture
uses a small materialized pool of existing Word Boat source images/audio so the
database shape is large without copying thousands of unique media files.

## Latest Local Baseline

Command:

```bash
LL_PERF_PROFILE=stress-2x LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_COMPARE_HISTORY=0 LL_E2E_PERF_MAX_INTERACTION_MS=60000 tests/bin/run-performance-benchmark.sh
```

Result: passed in 3.3 minutes.

| Scenario | Primary Metric |
| --- | ---: |
| learn-grid-stress2x-load | 2836 ms |
| wordset-stress2x-main-load | 3823 ms |
| wordset-stress2x-search-filter | 519 ms |
| wordset-stress2x-games-load | 3853 ms |
| learn-grid-stress2x-quiz-popup | 160 ms |
| wordset-stress2x-progress-load | 10680 ms |
| wordset-stress2x-progress-words-tab | 210 ms |

## Findings

- Vocab lesson deepest-count queries previously built large repeated
  `UNION ALL` depth tables and hit a MySQL thread-stack overrun during stress
  seeding. The count path now reduces deepest categories in PHP, and the depth
  table helper now uses a taxonomy-backed `CASE` expression instead of one
  `UNION ALL` arm per category.
- Naive stress fixture seeding was not viable at this size. Copying a unique
  source media file per word and sending every synthetic post through normal
  post-insert hooks caused multi-hour runs. The seeder now materializes a small
  Word Boat media pool, cleans the fixture upload directory on reset, defers
  WordPress recount/cache work during reseed, and uses raw fixture inserts for
  synthetic posts.
- The slowest completed browser scenario is currently
  `wordset-stress2x-progress-load` at about 10.7 seconds under the throttled
  profile. That is the first user-facing area to inspect for large-wordset
  optimization after fixture and vocab-count fixes.
- A cold full-benchmark run with the default 20 second interaction cap timed out
  in `wordset-stress2x-search-filter`, while direct backend profiling showed
  category-search index build at 14 ms and matching at 72 ms, and a throttled
  browser probe completed the same search in 424 ms once warmed. Treat cold
  search/AJAX warmup behavior as a harness or first-hit follow-up before
  raising production concerns from that timeout alone.
