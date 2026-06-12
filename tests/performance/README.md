# LL Tools Performance Benchmarks

The benchmark suite is opt-in because performance timings are noisier and slower
than normal regression tests.

Run from the plugin root:

```bash
tests/bin/run-performance-benchmark.sh
```

The runner:

1. Checks whether the static fixture already matches
   `tests/performance/fixtures/performance-wordsets.json`.
2. Reuses the existing fixture when the version, checksum, counts, tags, and
   key pages still match.
3. Resets only fixture-owned content tagged with `_ll_tools_performance_fixture`
   when the fixture is missing or stale.
4. Recreates the static `ll-perf-small`, `ll-perf-medium`, and `ll-perf-large`
   wordsets, `/ll-perf-learn/`, quiz pages, and vocab lesson pages when needed.
5. Runs `tests/e2e/specs/performance-benchmark.spec.js` under Playwright.
6. Appends one JSONL record to `tests/performance/history/performance-history.jsonl`
   unless `LL_E2E_PERF_WRITE_HISTORY=0` is set.
7. Writes the latest machine-readable and Markdown summaries under
   `tests/performance/reports/`.

The default fixture is intentionally modest for routine release-to-release
checks. For thousands-of-words coverage, use the opt-in XL profile:

```bash
LL_PERF_PROFILE=xl tests/bin/run-performance-benchmark.sh
```

The XL profile uses `tests/performance/fixtures/performance-wordsets-xl.json`,
targets `benchmarkTargetSize: "xl"`, defaults to one run per scenario, and
writes history to `tests/performance/history/performance-history-xl.jsonl` plus
latest reports to `tests/performance/reports/performance-latest-xl.*`.

Summarize existing history without reseeding or opening a browser:

```bash
node scripts/summarize-performance-history.js
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-xl.jsonl --scenario wordset-xl
node scripts/summarize-performance-history.js --limit 10 --format json
```

Change `fixtureVersion` whenever fixture shape changes. History comparisons only
use records with the same fixture version, matching manifest checksum when both
records have one, and the same throttle profile so older results are not mixed
with a different test dataset.

Useful overrides:

```bash
LL_PERF_FORCE_SEED=1
LL_PERF_PROFILE=xl
LL_PERF_SEED_ONLY=1
LL_PERF_SKIP_SEED=1
LL_E2E_PERF_RUNS=5
LL_E2E_PERF_WRITE_HISTORY=0
LL_E2E_PERF_COMPARE_HISTORY=0
LL_E2E_PERF_REPORT_FILE=tests/performance/reports/performance-latest.json
LL_E2E_PERF_MAX_REGRESSION_RATIO=0.2
LL_E2E_PERF_MAX_REGRESSION_MS=500
LL_E2E_PERF_HISTORY_FILE=tests/performance/history/performance-history.jsonl
```

Progress-page scenarios use the shared E2E admin credentials, so set
`LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` in `tests/.env.local`.

The regression rule is intentionally conservative: a scenario fails only when
the current median is slower than the previous matching history record by more
than both `LL_E2E_PERF_MAX_REGRESSION_RATIO` and `LL_E2E_PERF_MAX_REGRESSION_MS`.
