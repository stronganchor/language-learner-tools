# AI Testing Playbook

Purpose: quick operational guidance for future agents that need to run, add, or modify tests in this plugin.

## 1) Test Layers

- `PHPUnit integration` in `tests/Integration/*.php`
  - Validates plugin PHP behavior inside WordPress test bootstrap.
- `Playwright E2E` in `tests/e2e/specs/*.spec.js`
  - Validates primary user flows in a real browser against the Local site.

Use both when changing core behavior that affects UI + backend.

## 2) Fast Command Reference

From plugin root:

```bash
tests/bin/run-tests.sh
tests/bin/run-e2e.sh
tests/bin/run-live-smoke.sh
tests/bin/run-performance-benchmark.sh
```

Run one PHPUnit test:

```bash
tests/bin/run-tests.sh tests/Integration/FlashcardWidgetFlowTest.php
tests/bin/run-tests.sh Integration/FlashcardWidgetFlowTest.php
```

Run one Playwright spec:

```bash
tests/bin/run-e2e.sh tests/e2e/specs/quiz-mode-transitions.spec.js
tests/bin/run-e2e.sh specs/quiz-mode-transitions.spec.js
```

Run full local Playwright coverage in shards when an automation timeout budget
is too tight for the serial suite:

```bash
tests/bin/run-e2e.sh --shard=1/8
tests/bin/run-e2e.sh --shard=2/8
tests/bin/run-e2e.sh --shard=3/8
tests/bin/run-e2e.sh --shard=4/8
tests/bin/run-e2e.sh --shard=5/8
tests/bin/run-e2e.sh --shard=6/8
tests/bin/run-e2e.sh --shard=7/8
tests/bin/run-e2e.sh --shard=8/8
```

Headed Playwright debug:

```bash
cd tests/e2e
npx playwright test --headed --project=chromium specs/quiz-mode-transitions.spec.js
```

Read-only live smoke checks against public URLs in a local config file:

```bash
tests/bin/run-live-smoke.sh
```

Seeded Local-site performance benchmark:

```bash
tests/bin/run-performance-benchmark.sh
```

## 3) Environment Rules

- Primary runtime values come from `tests/.env` (ignored by git).
- `tests/bin/run-tests.sh` and `tests/bin/run-e2e.sh` load `.env` automatically.
  - `tests/bin/run-tests.sh` also auto-applies `tests/bin/setup-local-env.sh` when it can detect this Local site, so stale `.env` DB ports should not win by default.
  - `tests/bin/run-tests.sh` also patches the local `wordpress-tests-lib` bootstrap when PHPUnit 12 needs WordPress' removed annotation-parser calls shimmed.
- For Local/WSL setups:
  - `tests/bin/setup-local-env.sh` resolves DB + PHP helpers.
    - It prefers the active Local runtime MySQL port (from `AppData/Roaming/Local/run/*/conf/mysql/my.cnf`) when it can match this site root. If sandbox policy hides that runtime directory, it next accepts a literal loopback `DB_HOST` from the matching site's `wp-config.php`, before falling back to the potentially stale `local-site.json` port.
    - It keeps the live Local DB host credentials but emits an isolated `WP_TEST_DB_NAME` by default so PHPUnit does not target the main site schema.
    - It uses `tests/bin/php-local.sh` and `tests/bin/resolve-local-runtime.php`; no separate Python binary or fixed `/mnt/c` mount is required.
  - `tests/bin/setup-local-http-env.sh` resolves the active Local HTTP port from nginx config.
- `tests/bin/run-e2e.sh` refreshes an env-file base URL from that matching runtime. A caller-exported `LL_E2E_BASE_URL` wins; use `LL_TOOLS_SKIP_AUTO_LOCAL_HTTP_ENV=1` only when an env-file URL must remain authoritative.
- The E2E wrapper probes `chromium.executablePath()` first and runs Playwright's browser installer only when that executable is absent. A network-restricted sandbox (or explicit `LL_TOOLS_E2E_SKIP_BROWSER_INSTALL=1`) skips installation when policy also hides the global browser cache, avoiding a blocked network probe before every focused test.
- Git Bash runs npm's JavaScript entry point and Playwright's installed CLI directly through Node; do not restore an extensionless npm/npx shim at the final `exec` boundary because PATHEXT can hand its shebang to WSL.
- If you override values in-shell (e.g. `WP_TEST_DB_HOST=...`), those should take precedence.
- If Local changed ports recently, `tests/bin/run-tests.sh` should refresh them automatically; use `eval "$(tests/bin/setup-local-env.sh)"` when you want to inspect the resolved values directly.
- Set `LL_TOOLS_SKIP_AUTO_LOCAL_ENV=1` if you intentionally need `tests/.env` to stay authoritative.

Recommended `.env` keys to verify before debugging code:

- `WP_TEST_DB_HOST`
- `WP_TESTS_DIR`
- `WP_CORE_DIR`
- `PHP_BIN` when the suite needs a PHP 8.3+ runtime for PHPUnit 12
- `LL_E2E_BASE_URL`
- `LL_E2E_LEARN_PATH`
- `LL_E2E_PAGE_SPEED_PATH` and `LL_E2E_PAGE_SPEED_MAX_ACTIONABLE_MS` when debugging the throttled page-speed regression
- `LL_E2E_PERF_*` when running the seeded performance benchmark
- `LL_LIVE_SITES_FILE` when running the read-only live smoke suite against public URLs

## 4) Adding New PHPUnit Integration Tests

1. Add file under `tests/Integration/*Test.php`.
2. Extend `LL_Tools_TestCase` from `tests/TestCase.php`.
3. Use `self::factory()` to create posts/users/terms.
4. Keep tests isolated:
   - Set up all needed data in the test.
   - Do not depend on ordering.
   - Clean transient hooks/filters in `finally` when needed.
   - When one test intentionally models more than one HTTP request, call
     `$this->completeLlToolsSimulatedRequest()` at each request boundary. It
     runs pending plugin-owned mutation shutdown finalizers before clearing
     their request-local state, matching production; do not replace it by
     unsetting pending invalidation globals.
5. Run targeted test first, then full suite.

Pattern:

```php
final class MyFlowTest extends LL_Tools_TestCase
{
    public function test_primary_behavior(): void
    {
        // arrange data
        // call plugin function/shortcode/hook
        // assert output/state
    }
}
```

## 5) Adding New Playwright Tests

1. Add file under `tests/e2e/specs/*.spec.js`.
2. Prefer resilient selectors:
   - Use IDs/classes (`#ll-tools-...`, `.ll-quiz-page-trigger`).
   - Avoid assertions on translated UI text unless text itself is the subject.
3. Use env-backed paths:
   - `process.env.LL_E2E_LEARN_PATH || '/learn/'`
   - For page-speed coverage, prefer configurable selectors/budgets over hardcoded timing values.
4. Keep tests data-agnostic:
   - Use first available quiz card rather than hardcoded category names.
5. Validate cleanup:
   - If opening popups/modals, assert they can close and body classes reset.

For network-sensitive regressions on Local sites:

- Prefer Chromium DevTools throttling via CDP over fake `setTimeout()` delays.
- Calibrate with env vars such as `LL_E2E_PAGE_SPEED_LATENCY_MS`, `LL_E2E_PAGE_SPEED_DOWNLOAD_KBPS`, and the `LL_E2E_PAGE_SPEED_MAX_*` budgets.
- Measure an actionable selector becoming visible, not just the `load` event.
- For release-to-release performance comparison, use `tests/bin/run-performance-benchmark.sh` so the fixture is reused or refreshed against the selected profile manifest; the default profile uses `tests/performance/fixtures/performance-wordsets.json`.

### Core full-catalog localization guards

Configured core locales are not limited to the public tier-2 manifest. Before
accepting a POT/PO refresh, run both database-free full-catalog checks:

```bash
php scripts/check-public-i18n.php --full-catalog=tr_TR --fail-on-missing --details --json
php scripts/check-public-i18n.php --full-catalog=de_DE --fail-on-missing --details --json
```

A matching HEAD is not sufficient reason for scheduled upkeep to skip when
this command reports missing, blank, partial, fuzzy, stale, duplicate,
structurally invalid, or uncompiled entries. Every locale configured under
`core_full_locales` in `languages/tier2-public-ui-sources.php` must pass. The
checker compares compiled MO and PHP messages with each PO, so stale runtime
copy also fails the guard. Fill new catalog entries, rebuild both compiled
artifacts, and rerun `Integration/PublicUiTranslationManifestTest.php`.

## 6) Modifying Existing Tests Safely

When behavior changes intentionally:

1. Update assertions to match new expected behavior.
2. Keep the original business intent visible in test name.
3. Avoid weakening tests (do not remove critical assertions without replacement).
4. Re-run:
   - changed test only
   - full suite (`run-tests.sh` and/or `run-e2e.sh`)

## 7) Common Failures and Fixes

`WordPress test library not found`:
- `tests/bin/run-tests.sh` now tries to repair the local WordPress test framework automatically when `WP_TESTS_DIR` or `WP_CORE_DIR` are missing or incomplete.
- On this Local/WSL setup, Windows `php.exe` should use the Windows temp bootstrap paths instead of `/tmp` because WordPress' bootstrap uses `is_readable()` checks that fail on WSL UNC paths.
- If that still fails, fix `WP_TESTS_DIR` so it contains `includes/functions.php`.

`Duplicate entry '1' for key 'PRIMARY'` during `wp_install_defaults`:
- This usually means the test database is stale or another test runner is using the same DB.
- Retry with `LL_TOOLS_RESET_WP_TEST_DB=1 tests/bin/run-tests.sh ...` to force a fresh local test database.

Local site returns `500`:
- Check Local DB service and `DB_HOST` in site `wp-config.php`.
- Confirm MySQL port matches active Local run config.
- If `tests/bin/setup-local-env.sh` reports a DB port that refuses connections but `setup-local-http-env.sh` finds the active site, compare `LOCAL_DB_PORT_SOURCE`, the site's literal loopback `DB_HOST`, and the active runtime config; a dynamic/non-loopback `DB_HOST` cannot be used as the safe sandbox fallback.
- Verify with:
```bash
tests/bin/setup-local-env.sh
tests/bin/setup-local-http-env.sh
```
- If needed, inspect the active Local runtime's MySQL config (`.../Local/run/<id>/conf/mysql/my.cnf`) and override:
```bash
WP_TEST_DB_HOST=127.0.0.1:<port> tests/bin/run-tests.sh
```
- `setup-local-env.sh` also exports `LOCAL_DB_PORT_SOURCE` and, when runtime detection succeeds, `LOCAL_ACTIVE_MYSQL_CONF` / `LOCAL_ACTIVE_NGINX_CONF` for quick debugging.
- `tests/bin/install-wp-tests.sh` refuses to target the detected live Local site DB unless `ALLOW_LIVE_SITE_TEST_DB=1` is set deliberately.

`Deadlock found when trying to get lock` during PHPUnit:
- Usually caused by running multiple `tests/bin/run-tests.sh` commands in parallel against the same `wptests` DB.
- Run PHPUnit serially (one process at a time) for reliable results.

`tests/.run-tests.lock` exists but no PHPUnit runner is active:
- Confirm there is no active `tests/bin/run-tests.sh` or PHPUnit process.
- Remove the stale lock file, then rerun the same test command.
- Do not remove the lock while another test command is still running against the same test DB.

Playwright shows Local router `404 Site Not Found`:
- The hostname route is not active in Local Router.
- Use a reachable `LL_E2E_BASE_URL` in `tests/.env` (for example active Local domain or resolved localhost URL).

Playwright navigates to `C:/Program Files/Git/learn/` or another filesystem path:
- Git Bash path conversion rewrote a browser URL path before launching Windows Node. Run through the current `tests/bin/run-e2e.sh`; its `MSYS2_ENV_CONV_EXCL` guard preserves `LL_E2E_LEARN_PATH`, `LL_E2E_STANDALONE_PATH`, and `LL_E2E_PAGE_SPEED_PATH` literally.

Playwright cannot find `.ll-quiz-page-trigger`:
- Confirm target page has `[quiz_pages_grid popup="yes"...]`.
- Check `LL_E2E_LEARN_PATH`.

When diagnosing quiz popup prompt/option behavior for a target category outside the initial localized registry:
- Treat the launch card's wordset, quiz mode, display mode, prompt type, and option type as authoritative; the category registry is allowed to be paged.
- Run `tests/bin/run-e2e.sh specs/quiz-popup-text-translation-options.spec.js specs/text-to-text-learning-intro.spec.js`. Both specs deliberately remove the target from the initial registry before launch.

Full Playwright run times out under an automation cap:
- Run `tests/bin/run-e2e.sh --list` first to confirm the inventory and catch discovery errors.
- Then run `tests/bin/run-e2e.sh --shard=1/8` through `--shard=8/8` to isolate whether a spec actually hangs and keep request-heavy groups below Local's PHP-CGI recycle boundary.
- On June 10, 2026, the local suite listed 314 tests at the time of the runner-health shard check, and all four then-current shards completed with 313 passed and 1 skipped. Later E2E follow-ups expanded the suite; the July 10, 2026 weekly audit listed 368 tests in 81 files, the July 17 discovery listed 436 tests in 95 spec files, the July 24 discovery listed 453 tests in 95 spec files, the July 31 no-install discovery listed 479 tests in 97 spec files, and the August 6 release audit exercised 597 tests. The final July 24 serial run completed with 440 passed and 13 intentionally skipped. These are dated discovery snapshots. The 20-minute full-run cap was too low for this Local serial suite, not evidence of a single hung spec.
- The current Windows Local stack runs one `php-cgi` worker and was empirically observed recycling it after roughly 500 dynamic requests. A large shard can therefore receive one Nginx `502` while Local replaces the worker. Confirm this boundary with simultaneous `WSARecv()` failures in the site's Nginx error log plus a changed `php-cgi` PID/start time, then rerun the exact failed spec or request-heavy file on the fresh worker. Do not add a generic 5xx retry or weaken the assertion; a route that fails again before the recycle boundary remains an application failure.
- If all shards pass but the unsharded command still stalls beyond 35 minutes, investigate suite-level state leakage, leftover browser/process state, or Local-site slowness before weakening assertions.

`page-speed-throttled-load.spec.js` fails:
- Open the Playwright HTML report and inspect the attached `page-speed-metrics` JSON.
- If the wrong page or ready signal is being tested, set `LL_E2E_PAGE_SPEED_PATH` and `LL_E2E_PAGE_SPEED_SELECTOR`.
- If `responseStartMs` dominates while response-end-to-DOM readiness stays stable, check due WP-Cron maintenance and Local's single PHP-CGI worker before changing frontend code. The spec records warmup durations and uses `LL_E2E_PAGE_SPEED_WARMUP_SETTLE_MS` to separate a returned warmup from background work it spawned.
- If the environment is slower but behavior is acceptable, tune the `LL_E2E_PAGE_SPEED_MAX_*` budgets in `tests/.env.local`.

`wordset-page-speed-large-wordset.spec.js` fails:
- Confirm the configured large wordset path exists locally; by default it targets `/genc-palu/`.
- Inspect the attached `wordset-page-speed-metrics` JSON before changing budgets.
- If you need a different large wordset, set `LL_E2E_WORDSET_PAGE_SPEED_PATH` and keep the selector pointed at a visible wordset-page card.

`performance-benchmark.spec.js` fails:
- Run it through `tests/bin/run-performance-benchmark.sh` unless you deliberately seeded the fixture yourself.
- Inspect the attached `performance-benchmark-summary` JSON.
- Inspect the latest local reports under `tests/performance/reports/` when the Playwright HTML report is not open.
- Use `node scripts/summarize-performance-history.js` for a quick read of existing JSONL history without rerunning the benchmark.
- Set `LL_PERF_FORCE_SEED=1` for a full fixture reset, or `LL_PERF_SEED_ONLY=1` to seed/verify without launching Playwright.
- Set `LL_PERF_PROFILE=xl` when the default fixture is too small for a performance claim; the XL profile uses a separate manifest and history file.
- Set `LL_PERF_PROFILE=genc` when investigating Genç-scale wordset, settings-hub,
  or recorder-queue behavior. It models 209 categories, 2717 words, per-word
  images, 8151 audio rows, and a meaningful assigned-recorder queue; initial
  single-batch usability and viewport-driven serial lazy completion are reported
  separately, including the one-request-at-a-time concurrency guard.
- In Windows PowerShell with WSL `bash`, pass `LL_PERF_*` values inside one
  `bash -lc 'LL_PERF_PROFILE=genc ... tests/bin/run-performance-benchmark.sh'`
  invocation. Preceding `$env:` assignments may not cross into WSL; require the
  runner's `Using LL Tools performance profile: genc` confirmation before
  accepting or allowing a seed.
- Named profiles override conflicting fixture/history/report path values, while caller-supplied run counts, comparison/write flags, and budgets remain configurable. `LL_PERF_SKIP_SEED=1` never promotes a missing or legacy checksum; it fails until a normal seed writes the exact selected version and canonical checksum. The benchmark runner locks all exported `LL_E2E_PERF_*` values before invoking `run-e2e.sh`.
- After fixture verification, the runner completes the selected target wordset's durable category-search index through bounded untimed batches, including with `LL_PERF_SKIP_SEED=1` and `LL_PERF_SEED_ONLY=1`. Treat terminal/backoff/no-progress/signature/count preflight failures as materializer failures; do not extend the measured interaction budget to compensate for cold setup. A Retry state during the measured search is an immediate diagnostic because preparation should already be complete.
- The runner passes the small stored-fixture JSON to `verify-performance-manifest.php`
  as an explicit third argument. Preserve this argv transport: redirected or
  piped stdin can arrive empty or transcoded when WSL launches Windows PHP.
- For benchmark-runner or manifest-contract changes, run:

  ```bash
  tests/bin/php-local.sh tests/performance/verify-performance-manifest.php
  tests/bin/run-e2e.sh specs/performance-benchmark-contracts.spec.js
  ```
- Set `LL_PERF_PROFILE=stress-2x` when you need full local stress coverage for
  a 5000-word wordset with per-word image posts and 15000 `word_audio` posts.
  Seed it separately first with `LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1`, then benchmark with
  `LL_PERF_SKIP_SEED=1`. The latest local findings live in
  `tests/performance/STRESS_2X_FINDINGS.md`.
- If `wordset-stress2x-search-filter` times out at the default 20 second
  interaction cap, run the stress profile with
  `LL_E2E_PERF_MAX_INTERACTION_MS=60000` and inspect whether the measured
  interaction is still subsecond. A cold AJAX/search warmup timeout is different
  from a steady-state search regression.
- Confirm `LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` are set because progress, settings-hub, and recorder-queue scenarios are authenticated. Recorder-enabled manifests fail rather than silently dropping those measurements.
- Keep `LL_E2E_PERF_MAX_INTERACTION_MS` scoped to ordinary search/progress/quiz work. Use `LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS` for the longer full recorder-summary stream.
- If the fixture manifest changed intentionally, bump `fixtureVersion`. Historical regression comparison requires a clean three-or-more-run baseline with the same run count, fixture version, manifest checksum, throttle profile, and scenario fingerprint. Increment a scenario's `comparisonVersion` and update `comparisonSemantics` when its readiness point or workload changes.
- If a slower machine produced acceptable timings, tune `LL_E2E_PERF_MAX_REGRESSION_RATIO` and `LL_E2E_PERF_MAX_REGRESSION_MS` rather than weakening scenario selectors.

`Could not open input file .../tests/vendor/phpunit/phpunit/phpunit`:
- This is usually a Windows PHP path-conversion issue in WSL or Git Bash.
- `tests/bin/php-local.sh` auto-converts args with `wslpath` (WSL) or `cygpath` (Git Bash).
- In WSL, run:
```bash
PHP_BIN=/mnt/c/php/8.4/php.exe tests/bin/run-tests.sh
```
- In Git Bash, run:
```bash
PHP_BIN=/c/php/8.4/php.exe tests/bin/run-tests.sh
```

## 8) Minimum Validation Before Finishing

For behavior changes touching quiz/recording flows:

1. For quiz dialog, iframe recovery, or accessibility changes, run `Integration/FlashcardShellRendererTest.php`, `Integration/QuizPagePostTypeTest.php`, `specs/quiz-iframe-recovery.spec.js`, `specs/quiz-popup-fallback-modal.spec.js`, and `specs/quiz-popup-open-close.spec.js`. For popup launch or presentation-config changes, also run `specs/quiz-popup-text-translation-options.spec.js` and `specs/text-to-text-learning-intro.spec.js`; these protect trigger-authoritative prompt/option configuration when the launched category is absent from the initial paged registry.
2. For Audio/Image Matcher pagination or interaction changes, run `Integration/AudioImageMatcherLazyLoadTest.php`, `Integration/AssetEnqueueTest.php`, and `specs/audio-image-matcher-pagination.spec.js`.
3. For flashcard payload materializer, page-cursor, cold-warmup, or deferred
   bootstrap changes, run:

   ```bash
   tests/bin/run-tests.sh Integration/FlashcardPayloadMaterializerTest.php
   tests/bin/run-tests.sh Integration/FlashcardWidgetFlowTest.php
   tests/bin/run-tests.sh Integration/SecurityHardeningRegressionTest.php
   tests/bin/run-tests.sh Integration/UserStudyAnalyticsTest.php
   tests/bin/run-e2e.sh specs/flashcard-loader-wordset-isolation.spec.js specs/wordset-pages-listening-launch.spec.js specs/self-check-shared-image-grouping.spec.js
   ```

   These protect bounded generation publication, cursor tamper/drift behavior,
   locale propagation, no-candidate page envelopes, speaker redaction,
   response-only learner progress, private support-word filtering, exact
   generation cleanup/retirement fencing, read-side scope leases, and image-hash
   option parity. Query-shape assertions should keep primary/prompt scans on
   keysets and page reads on a metadata-first row/byte budget; do not weaken
   them to accept a full-category fallback.
4. For recorder overview-summary shell, timeout, retry, or catalog changes, first run `tests/bin/run-tests.sh --filter WordsetRecorderQueueOverviewResourceTest` and `tests/bin/run-e2e.sh specs/wordset-manager-settings-ui.spec.js`.
5. For recorder-queue cursor/continuation changes, first run `tests/bin/run-tests.sh --filter AudioRecordingShortcodeHelpersTest` and `tests/bin/run-e2e.sh specs/audio-recorder-category-switch.spec.js`. These protect signed-cursor rebasing, cumulative same-page legacy/prompt state, and empty-but-continuable client behavior.
6. For raw-password REST admission, run `Integration/RestPasswordAuthAdmissionTest.php`. For public dictionary input, run `Integration/DictionaryPublicFilterBoundsTest.php`. For inactive previews and lesson maps, run `Integration/WordsetPageInactiveCategoryCardsTest.php` and `Integration/WordsetPageWarmLessonMapTest.php`. For masked-media fallback cache changes, run `Integration/MediaProxyFallbackCacheTest.php`.
7. `tests/bin/run-tests.sh`
8. `tests/bin/run-e2e.sh`
9. Update `tests/README.md` if test scope or runner behavior changed.

For public-page shell, asset, or template changes that could affect perceived load time:

1. `tests/bin/run-e2e.sh specs/page-speed-throttled-load.spec.js`
2. `tests/bin/run-e2e.sh specs/wordset-page-speed-large-wordset.spec.js` when the wordset page, large category lists, or wordset page caches are involved
3. `tests/bin/run-performance-benchmark.sh` when the change could affect release-to-release performance trends
4. `tests/bin/run-live-smoke.sh` when you also need a low-impact post-deploy production sanity check

Wordset-boundary changes should also include:

1. `tests/bin/run-e2e.sh specs/flashcard-loader-wordset-isolation.spec.js`

Dictionary import/search changes should also include:

1. Run `Integration/DictionaryPublicFilterBoundsTest.php` for public raw-input/admission changes and `specs/dictionary-shortcode-deferred-toolbar.spec.js` when the public interaction model changes.
2. Retain `Integration/DictionaryFeatureTest.php` for search/import semantics.
3. Run `specs/admin-import-preview-undo.spec.js` when the admin importer UI changes.

## 9) Known Environment-Dependent Skips

- `ExternalCsvBundleImportTest::test_import_decodes_windows_1255_csv_values_and_generates_quiz_page`
  - May be skipped when runtime `iconv` / `mbstring` libraries cannot reliably round-trip the non-UTF Hebrew sample.
  - Treat this as environment capability variance unless related CSV import assertions are otherwise failing.
