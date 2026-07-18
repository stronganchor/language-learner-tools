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
5. Locks every currently exported `LL_E2E_PERF_*` value before `run-e2e.sh`
   reloads environment files, requiring the manifest, history, report, and
   verified checksum values.
6. Runs `tests/e2e/specs/performance-benchmark.spec.js` and writes history and
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

Authenticated scenarios measure the settings hub, recorder-queue initial
usability, and the separate navigation-to-completion time for all lazy summary
batches. The lazy-completion samples retain the category counts observed when
the driver takes over, the exact final count, and the bounded AJAX request
count since navigation began. The run fails if it does not expose at least three
initial categories, finish with all 209, or exceeds thirty-six summary
requests (one three-category request followed by six-category batches).
The stream generation depends on ordered category identities and
recorder/wordset/filter scope, so ordinary per-category content invalidation
refreshes affected cards without restarting that bounded stream. Genç history is written to
`tests/performance/history/performance-history-genc.jsonl`; latest reports are
written to `tests/performance/reports/performance-latest-genc.*`. Set
`LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` in `tests/.env.local`; these are the
manager credentials, not the generated recorder account.

For full local stress coverage, use the opt-in stress profile:

```bash
LL_PERF_PROFILE=stress-2x LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1 tests/bin/run-performance-benchmark.sh
LL_PERF_PROFILE=stress-2x LL_PERF_SKIP_SEED=1 LL_E2E_PERF_RUNS=1 LL_E2E_PERF_COMPARE_HISTORY=0 LL_E2E_PERF_MAX_INTERACTION_MS=60000 tests/bin/run-performance-benchmark.sh
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
known cold-search caveat.

Summarize existing history without reseeding or opening a browser:

```bash
node scripts/summarize-performance-history.js
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-xl.jsonl --scenario wordset-xl
node scripts/summarize-performance-history.js --history tests/performance/history/performance-history-stress-2x.jsonl --scenario stress2x
node scripts/summarize-performance-history.js --limit 10 --format json
```

Change `fixtureVersion` whenever fixture shape changes. History comparisons only
use records with the same fixture version, matching manifest checksum when both
records have one, and the same throttle profile so older results are not mixed
with a different test dataset.
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
