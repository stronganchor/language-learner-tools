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
```

Run one Playwright spec:

```bash
tests/bin/run-e2e.sh tests/e2e/specs/quiz-mode-transitions.spec.js
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

Playwright shows Local router `404 Site Not Found`:
- The hostname route is not active in Local Router.
- Use a reachable `LL_E2E_BASE_URL` in `tests/.env` (for example active Local domain or resolved localhost URL).

Playwright cannot find `.ll-quiz-page-trigger`:
- Confirm target page has `[quiz_pages_grid popup="yes"...]`.
- Check `LL_E2E_LEARN_PATH`.

## 8) Minimum Validation Before Finishing

For behavior changes touching quiz/recording flows:

1. `tests/bin/run-tests.sh`
2. `tests/bin/run-e2e.sh`
3. Update `tests/README.md` if test scope or runner behavior changed.
