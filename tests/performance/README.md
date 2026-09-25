# LL Tools Performance Benchmarks

The benchmark suite is opt-in because performance timings are noisier and slower
than normal regression tests.

Run from the plugin root:

```bash
tests/bin/run-performance-benchmark.sh
```

The runner:

1. Resolves the selected profile. Caller environment overrides `.env.local`,
   which overrides `.env` for caller-tunable settings; named profiles fix one
   manifest/history/report tuple.
2. Computes the selected manifest's `fixtureVersion` and `canonical-json-v1`
   checksum.
3. Unless seeding is skipped, reuses only an exactly current fixture or reseeds
   fixture-tagged content and users.
4. Always verifies the stored fixture option after seeding. With
   `LL_PERF_SKIP_SEED=1`, this is a read-only check that fails on missing,
   legacy, or mismatched version/checksum state. The runner passes this small
   JSON value as an explicit verifier argument because piped stdin is not a
   reliable UTF-8 transport when WSL launches Windows PHP.
5. Completes and verifies the selected target wordset's durable category-search
   index through bounded batches outside the timed browser scenario. Terminal,
   retry-backoff, stalled, signature-drift, and indexed-count states fail before
   Playwright; stale unpublished generations are drained in bounded cleanup
   batches before the target's scheduled hook is cleared. This preparation also
   runs with `LL_PERF_SKIP_SEED=1`.
6. Locks every currently exported `LL_E2E_PERF_*` value before `run-e2e.sh`
   reloads environment files, requiring the manifest, history, report, and
   verified checksum values.
7. Runs `tests/e2e/specs/performance-benchmark.spec.js` and writes history and
   reports for the selected profile, subject to `LL_E2E_PERF_WRITE_HISTORY`.

Manifest checksums use recursively key-sorted canonical JSON, so Git/Windows
CRLF conversion, indentation, and object-key order do not split otherwise
comparable benchmark history. Run the lightweight PHP contract directly with
`php tests/performance/verify-performance-manifest.php`. In
`--verify-stored` mode the stored JSON may be supplied as the third argument
(the runner's cross-runtime-safe path) or through stdin for compatibility.
Numeric manifest fields must be integers within JavaScript's safe integer range
so PHP and Node produce the same canonical representation.

The default fixture is intentionally modest for routine release-to-release
checks. For thousands-of-words coverage, use the opt-in XL profile:

```bash
LL_PERF_PROFILE=xl tests/bin/run-performance-benchmark.sh
```

The XL profile uses `tests/performance/fixtures/performance-wordsets-xl.json`,
targets `benchmarkTargetSize: "xl"`, defaults to one run per scenario, and
writes history to `tests/performance/history/performance-history-xl.jsonl` plus
latest reports to `tests/performance/reports/performance-latest-xl.*`.

For the closest routine reproduction of the Genç production growth dimensions,
use the opt-in Genç profile:

```bash
LL_PERF_PROFILE=genc LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1 tests/bin/run-performance-benchmark.sh
LL_PERF_PROFILE=genc LL_PERF_SKIP_SEED=1 tests/bin/run-performance-benchmark.sh
```

From Windows PowerShell when `bash` resolves to WSL, put the variables inside
the WSL command instead of relying on preceding `$env:` assignments (which are
not imported unless `WSLENV` is configured):

```powershell
bash -lc 'LL_PERF_PROFILE=genc LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 tests/bin/run-performance-benchmark.sh'
```

Confirm the runner prints `Using LL Tools performance profile: genc` before
accepting the result; otherwise it measured and may reseed the default fixture.

The Genç profile uses
`tests/performance/fixtures/performance-wordsets-genc.json`, targets 209
categories with 13 words each (2717 words), per-word images, and three audio
rows per word (8151 audio rows). It also creates one fixture-tagged
`audio_recorder`, assigns that recorder to the benchmark wordset, and requests
both isolation and question audio while seeding only isolation audio. This
produces bounded but meaningful queue work in all 209 categories.

The `2026-09-25-genc.2` fixture opts into truthful attachment dimensions and a
real WordPress `medium` derivative. Source discovery is capped at 24 images,
and the seeder creates at most one verified derivative per materialized source
file; the 2717 attachment rows reuse that bounded physical pool instead of
performing thousands of redundant resizes. The default, XL, and stress
fixtures keep their existing media contract. If the active WordPress image
editor cannot create and read back the selected derivative, Genç seeding fails
before deleting the current fixture instead of silently benchmarking full-size
files as medium previews. GD or Imagick must therefore be enabled for the PHP
process that runs the Genç seeder. A process-local override is sufficient (for
example, invoke the WP-CLI PHP binary with `-d extension=gd`); no global PHP
configuration change is required.

Authenticated scenarios measure the settings hub, recorder-queue initial
usability, and the separate navigation-to-completion time for all lazy summary
batches. The lazy-completion samples retain the category counts observed when
the driver takes over, the exact final count, and the bounded AJAX request
count and maximum concurrency since navigation began. The driver repeatedly
scrolls the dedicated sentinel into view and waits for each viewport-triggered
serial batch; it does not click a continuation control. The run fails on a
summary load-error state, if it does not expose at least three initial
categories, finish with all 209, or exceeds thirty-six summary
requests (one three-category request followed by six-category batches), or if
more than one summary request is ever in flight.
The stream generation depends on ordered category identities and
recorder/wordset/filter scope, so ordinary per-category content invalidation
refreshes affected cards without restarting that bounded stream. Genç history is written to
`tests/performance/history/performance-history-genc.jsonl`; latest reports are
written to `tests/performance/reports/performance-latest-genc.*`. Set
`LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` in `tests/.env.local`; these are the
manager credentials, not the generated recorder account.

Recorder completion samples also retain navigation-to-completion and strict
interaction-phase summary request durations/response-start timings, media
resource counts and transfer/encoded bytes, and a bounded list of slow media.
Response waiting includes network contention and is not a PHP-only measurement.
For a paired media-isolation diagnostic on the same verified fixture, run:

```bash
LL_PERF_PROFILE=genc LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_WRITE_HISTORY=0 LL_E2E_PERF_COMPARE_HISTORY=0 tests/bin/run-performance-benchmark.sh
LL_PERF_PROFILE=genc LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_RECORDER_BLOCK_MEDIA=1 tests/bin/run-performance-benchmark.sh
```

The second command blocks image/media requests only for recorder completion,
uses the separate `recorder-media-blocked-v1` diagnostic/report suffix, and
disables history comparison/writes. It keeps all normal budgets unchanged and
must never be presented as normal user-visible page performance. Separate
cold/warm cache state when interpreting the pair.

Add `LL_E2E_PERF_RECORDER_ONLY=1` to measure only the recorder completion
scenario. It uses `recorder-only-v1` and a `-recorder-only.json` report suffix;
combining it with media blocking uses the media-blocked identity instead.
Both diagnostics disable historical comparison and writes. A failed recorder
scenario attaches its partial request timings (including in-flight elapsed
time), media bytes, and remaining placeholders without turning failure into a
successful sample; prior completed scenarios remain in the partial diagnostic.

### Recorder summary server profile

Use the tracked WP-CLI profiler when the browser completion result needs to be
separated into PHP/SQL cache-rebuild cost. Run cold first and warm second as
separate WP-CLI processes from the plugin root:

```bash
wp eval-file tests/performance/profile-recorder-summary.php cold 80 180
wp eval-file tests/performance/profile-recorder-summary.php warm 80 180
wp eval-file tests/performance/profile-recorder-summary.php reset
```

The profiler refuses any fixture except the checksum-verified, fixture-tagged
Genç wordset and recorder. It requires exactly 209 categories, 13 words per
category, and the normal six-category server batch. The cold phase deletes only
the 211 exact derived recorder-summary keys (catalog, manifest, and one key per
category); it never flushes a broad cache or changes content. Work is capped by
the supplied batch and wall-time limits (hard maxima: 120 batches and 300
seconds) and eight attempts per category. Catalog and fixture verification are
deliberately outside the timed interval. Incomplete work exits nonzero and the
JSON output retains remaining slugs and per-batch timing/query evidence.

Use `reset` immediately before each arm of a paired recorder browser diagnostic.
It reproduces the unfocused recorder-page selection and fails before deletion
unless that page would select the checksum-verified, tagged fixture recorder.
On success it deletes the same 211 exact keys as `cold`, reports the selected
recorder ID, assigned-recorder count, locale, normalized recording-type scope,
and cache schema, then exits without rebuilding. Run each browser arm in a
fresh process after its own successful reset. Confirm the reset scope matches
the browser context and do not compare arms whose initial loaded and placeholder
counts differ.

The 2026-09-25 Local server baseline on the older `2026-07-13-genc.1` fixture
completed all 209 categories in 35 batches. Cold
rebuild took 26,191.566 ms and 6,295 queries after 211 exact-key deletions; the
separate warm process took 296.970 ms and 3 queries. These are server-only
diagnostics, not browser latency or a user-visible performance budget.
The old normal/media-blocked browser arms started with different summary cache
states and cannot establish a media-isolation improvement. The corrected
`2026-09-25-genc.2` preview fixture starts a new baseline; never compare its
browser times to the old full-size-image fixture as a production speedup.
On September 25, the corrected fixture's single cold normal and media-blocked
arms both started at zero loaded / 209 placeholders after independent guarded
211-key resets. Both missed 120 seconds: normal reached 171/209 with 2,280,301
media bytes and a 3,845 ms summary-request median; blocked reached 99/209 with
zero media bytes and a 5,879 ms median. Concurrency stayed at one. This pair
does not establish a causal media speedup or regression: fresh-process AJAX
phase profiling and repeated matched runs are still needed. See
`MAINTENANCE_BACKLOG.md` issue `AUDIT-2026-09-18-08` for the next measurement.

For full local stress coverage, use the opt-in stress profile:

```bash
LL_PERF_PROFILE=stress-2x LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1 tests/bin/run-performance-benchmark.sh
LL_PERF_PROFILE=stress-2x LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_COMPARE_HISTORY=0 tests/bin/run-performance-benchmark.sh
```

The stress profile uses
`tests/performance/fixtures/performance-wordsets-stress-2x.json`, targets
`benchmarkTargetSize: "stress2x"`, creates `100 x 50 = 5000` words, and gives
each word a `word_images` post, attachment metadata, and three `word_audio`
posts for a 15000-recording stress shape. It uses existing Word Boat media when
available, materialized into a small fixture-local pool. Override the source
locations or pool size with:

```bash
LL_PERF_WORDBOAT_ROOT=/mnt/c/Users/messy/OneDrive/Websites/wordboat
LL_PERF_SOURCE_IMAGE_DIRS=/mnt/c/path/to/images
LL_PERF_SOURCE_AUDIO_DIRS=/mnt/c/path/to/audio
LL_PERF_SOURCE_IMAGE_LIMIT=24
LL_PERF_SOURCE_AUDIO_LIMIT=24
```

Stress history is written to
`tests/performance/history/performance-history-stress-2x.jsonl`; latest stress
reports are written to `tests/performance/reports/performance-latest-stress-2x.*`.
See `tests/performance/STRESS_2X_FINDINGS.md` for the latest local baseline and
historical cold-search findings. The current runner completes that bounded
materialization before browser timing instead of enlarging the interaction
budget.

Summarize existing history without reseeding or opening a browser:

```bash
node scripts/summarize-performance-history.js
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-xl.jsonl --scenario wordset-xl
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-stress-2x.jsonl --scenario stress2x
node scripts/summarize-performance-history.js --limit 10 --format json
```

Change `fixtureVersion` whenever fixture shape changes. Historical regression
comparisons require at least three runs and select only clean Git records with
the same run count, fixture version, throttle profile, and matching manifest
checksum when both records have one. Each scenario also carries a fingerprint
of its route, readiness contract, primary metric, and action-specific workload.
A changed scenario contract is reported as `NEW BASELINE` instead of being
compared with semantically different timing data. Increment `comparisonVersion`
and update `comparisonSemantics` whenever a scenario's measured readiness point
or workload changes. Dirty runs still produce reports but are not appended to
history.
When canonical checksum history is introduced for an existing fixture, the
new run may compare once with the newest same-version/same-throttle legacy row
whose raw file hash varied by line endings. After any canonical row exists,
only matching `canonical-json-v1` checksums are comparable.
The one-time legacy history-row comparison exception does not authorize fixture
reuse. The stored WordPress fixture option must always contain the exact
selected version and `canonical-json-v1` checksum.

Useful overrides:

```bash
LL_PERF_FORCE_SEED=1
LL_PERF_PROFILE=xl
LL_PERF_PROFILE=genc
LL_PERF_PROFILE=stress-2x
LL_PERF_SEED_ONLY=1
LL_PERF_SKIP_SEED=1
LL_E2E_PERF_RUNS=5
LL_E2E_PERF_WRITE_HISTORY=0
LL_E2E_PERF_COMPARE_HISTORY=0
LL_E2E_PERF_REPORT_FILE=tests/performance/reports/performance-latest.json # default profile only
LL_E2E_PERF_MAX_REGRESSION_RATIO=0.2
LL_E2E_PERF_MAX_REGRESSION_MS=500
LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS=120000
LL_E2E_PERF_HISTORY_FILE=tests/performance/history/performance-history.jsonl # default profile only
```

Progress, settings-hub, and recorder-queue scenarios use the shared E2E admin credentials, so set
`LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` in `tests/.env.local`.

The regression rule is intentionally conservative: a scenario fails only when
the current median is slower than the previous matching history record by more
than both `LL_E2E_PERF_MAX_REGRESSION_RATIO` and `LL_E2E_PERF_MAX_REGRESSION_MS`.
