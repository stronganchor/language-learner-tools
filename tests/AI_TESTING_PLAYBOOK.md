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

Headed Playwright debug:

```bash
cd tests/e2e
npx playwright test --headed --project=chromium specs/quiz-mode-transitions.spec.js
```

## 3) Environment Rules

- Primary runtime values come from `tests/.env` (ignored by git).
- `tests/bin/run-tests.sh` and `tests/bin/run-e2e.sh` load `.env` automatically.
- For Local/WSL setups:
  - `tests/bin/setup-local-env.sh` resolves DB + PHP helpers.
    - It prefers the active Local runtime MySQL port (from `AppData/Roaming/Local/run/*/conf/mysql/my.cnf`) when it can match this site root, which helps when `local-site.json` has stale ports.
  - `tests/bin/setup-local-http-env.sh` resolves the active Local HTTP port from nginx config.
- If you override values in-shell (e.g. `WP_TEST_DB_HOST=...`), those should take precedence.

Recommended `.env` keys to verify before debugging code:

- `WP_TEST_DB_HOST`
- `WP_TESTS_DIR`
- `WP_CORE_DIR`
- `LL_E2E_BASE_URL`
- `LL_E2E_LEARN_PATH`

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
4. Keep tests data-agnostic:
   - Use first available quiz card rather than hardcoded category names.
5. Validate cleanup:
   - If opening popups/modals, assert they can close and body classes reset.

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
- Fix `WP_TESTS_DIR` so it contains `includes/functions.php`.

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

`Deadlock found when trying to get lock` during PHPUnit:
- Usually caused by running multiple `tests/bin/run-tests.sh` commands in parallel against the same `wptests` DB.
- Run PHPUnit serially (one process at a time) for reliable results.

Playwright shows Local router `404 Site Not Found`:
- The hostname route is not active in Local Router.
- Use a reachable `LL_E2E_BASE_URL` in `tests/.env` (for example active Local domain or resolved localhost URL).

Playwright cannot find `.ll-quiz-page-trigger`:
- Confirm target page has `[quiz_pages_grid popup="yes"...]`.
- Check `LL_E2E_LEARN_PATH`.

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

Wordset-boundary changes should also include:

1. `tests/bin/run-e2e.sh specs/flashcard-loader-wordset-isolation.spec.js`

## 9) Known Environment-Dependent Skips

- `ExternalCsvBundleImportTest::test_import_decodes_windows_1255_csv_values_and_generates_quiz_page`
  - May be skipped when runtime `iconv` / `mbstring` libraries cannot reliably round-trip the non-UTF Hebrew sample.
  - Treat this as environment capability variance unless related CSV import assertions are otherwise failing.
