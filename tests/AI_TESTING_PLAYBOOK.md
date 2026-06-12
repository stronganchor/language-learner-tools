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
tests/bin/run-e2e.sh --shard=1/4
tests/bin/run-e2e.sh --shard=2/4
tests/bin/run-e2e.sh --shard=3/4
tests/bin/run-e2e.sh --shard=4/4
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
    - It prefers the active Local runtime MySQL port (from `AppData/Roaming/Local/run/*/conf/mysql/my.cnf`) when it can match this site root, which helps when `local-site.json` has stale ports.
    - It keeps the live Local DB host credentials but emits an isolated `WP_TEST_DB_NAME` by default so PHPUnit does not target the main site schema.
  - `tests/bin/setup-local-http-env.sh` resolves the active Local HTTP port from nginx config.
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
- For release-to-release performance comparison, use `tests/bin/run-performance-benchmark.sh` so the static `ll-perf-*` wordsets are reused when current or refreshed from `tests/performance/fixtures/performance-wordsets.json` when stale before timing starts.

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
- If `tests/bin/setup-local-env.sh` reports a DB port that refuses connections but `setup-local-http-env.sh` finds the active site, the site `local-site.json` is likely stale.
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

Playwright cannot find `.ll-quiz-page-trigger`:
- Confirm target page has `[quiz_pages_grid popup="yes"...]`.
- Check `LL_E2E_LEARN_PATH`.

Full Playwright run times out under an automation cap:
- Run `tests/bin/run-e2e.sh --list` first to confirm the inventory and catch discovery errors.
- Then run `tests/bin/run-e2e.sh --shard=1/4` through `--shard=4/4` to isolate whether a spec actually hangs.
- On June 10, 2026, the local suite listed 314 tests at the time of the runner-health shard check, and all four shards completed with 313 passed and 1 skipped. Later E2E follow-ups expanded the local inventory to 326 tests. The 20-minute full-run cap was too low for this Local serial suite, not evidence of a single hung spec.
- If all shards pass but the unsharded command still stalls beyond 35 minutes, investigate suite-level state leakage, leftover browser/process state, or Local-site slowness before weakening assertions.

`page-speed-throttled-load.spec.js` fails:
- Open the Playwright HTML report and inspect the attached `page-speed-metrics` JSON.
- If the wrong page or ready signal is being tested, set `LL_E2E_PAGE_SPEED_PATH` and `LL_E2E_PAGE_SPEED_SELECTOR`.
- If the environment is slower but behavior is acceptable, tune the `LL_E2E_PAGE_SPEED_MAX_*` budgets in `tests/.env.local`.

`wordset-page-speed-large-wordset.spec.js` fails:
- Confirm the configured large wordset path exists locally; by default it targets `/genc-palu/`.
- Inspect the attached `wordset-page-speed-metrics` JSON before changing budgets.
- If you need a different large wordset, set `LL_E2E_WORDSET_PAGE_SPEED_PATH` and keep the selector pointed at a visible wordset-page card.

`performance-benchmark.spec.js` fails:
- Run it through `tests/bin/run-performance-benchmark.sh` unless you deliberately seeded the fixture yourself.
- Inspect the attached `performance-benchmark-summary` JSON.
- Set `LL_PERF_FORCE_SEED=1` when you need a full fixture reset, or `LL_PERF_SEED_ONLY=1` when you only want to verify the fixture state.
- Set `LL_PERF_PROFILE=xl` when the default fixture is too small for a performance claim; the XL profile uses a separate manifest and history file.
- Confirm `LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` are set because progress-page scenarios are authenticated.
- If the fixture manifest changed intentionally, bump `fixtureVersion`; historical comparison only makes sense for the same fixture version, manifest checksum, and throttle profile.
- If a slower machine produced acceptable timings, tune `LL_E2E_PERF_MAX_REGRESSION_RATIO` and `LL_E2E_PERF_MAX_REGRESSION_MS` rather than weakening scenario selectors.

`Could not open input file .../tests/vendor/phpunit/phpunit/phpunit`:
- This is usually a PHP shim path-conversion issue in WSL.
- `tests/bin/php-local.sh` auto-converts args for Windows-runtime PHP.
- If your environment still fails, run:
```bash
PHP_BIN=/mnt/c/php/8.4/php.exe tests/bin/run-tests.sh
```

## 8) Minimum Validation Before Finishing

For behavior changes touching quiz/recording flows:

1. `tests/bin/run-tests.sh`
2. `tests/bin/run-e2e.sh`
3. Update `tests/README.md` if test scope or runner behavior changed.

For public-page shell, asset, or template changes that could affect perceived load time:

1. `tests/bin/run-e2e.sh specs/page-speed-throttled-load.spec.js`
2. `tests/bin/run-e2e.sh specs/wordset-page-speed-large-wordset.spec.js` when the wordset page, large category lists, or wordset page caches are involved
3. `tests/bin/run-performance-benchmark.sh` when the change could affect release-to-release performance trends
4. `tests/bin/run-live-smoke.sh` when you also need a low-impact post-deploy production sanity check

Wordset-boundary changes should also include:

1. `tests/bin/run-e2e.sh specs/flashcard-loader-wordset-isolation.spec.js`

Dictionary import/search changes should also include:

1. `tests/bin/run-tests.sh Integration/DictionaryFeatureTest.php`
2. `tests/bin/run-e2e.sh specs/admin-import-preview-undo.spec.js` when the admin importer flow changes
3. Add or update a dedicated Playwright spec if the public `[ll_dictionary]` interaction model changes, because current browser coverage is still weighted toward admin import plus PHPUnit integration

## 9) Known Environment-Dependent Skips

- `ExternalCsvBundleImportTest::test_import_decodes_windows_1255_csv_values_and_generates_quiz_page`
  - May be skipped when runtime `iconv` / `mbstring` libraries cannot reliably round-trip the non-UTF Hebrew sample.
  - Treat this as environment capability variance unless related CSV import assertions are otherwise failing.
