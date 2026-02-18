# Language Learner Tools Test Framework

For AI-oriented operational guidance (how to run, add, and modify tests safely), see `tests/AI_TESTING_PLAYBOOK.md`.

This directory contains the plugin test framework:

- `composer.json`: isolated PHPUnit dependency config (separate from plugin `vendor/`).
- `phpunit.xml.dist`: PHPUnit config.
- `bootstrap.php`: boots the WordPress test suite and loads the plugin.
- `Integration/*Test.php`: first integration tests.
- `bin/setup-local-env.sh`: detects Local site DB settings and prints export commands.
- `bin/install-wp-tests.sh`: installs WordPress core + wordpress-tests-lib and writes `wp-tests-config.php`.
- `bin/php-local.sh`: PHP wrapper that supports Linux PHP or Local Windows `php.exe` with required extensions.
- `bin/run-tests.sh`: installs test deps (if needed) and runs PHPUnit.
- `bin/bootstrap-and-test.sh`: end-to-end helper (`setup -> install -> test`).
- `bin/setup-local-http-env.sh`: detects the current Local HTTP port for this site path and exports Playwright URL vars.
- `bin/run-e2e.sh`: installs Playwright deps/browsers (if needed) and runs browser E2E tests.
- `e2e/*`: Playwright configuration + browser test specs.

## 1) Prerequisites

- PHP CLI (same major version as your site, ideally).
- Composer.
- WordPress PHPUnit test library (`wordpress-tests-lib`) and a test database.

## 2) Install PHP test dependencies

Run from plugin root:

```bash
cd tests
composer install
```

This installs PHPUnit to `tests/vendor/`.

## 3) Point tests to WordPress test library

Set `WP_TESTS_DIR` to your wordpress-tests-lib path:

```bash
export WP_TESTS_DIR=/tmp/wordpress-tests-lib
```

On Windows PowerShell:

```powershell
$env:WP_TESTS_DIR = "C:\tmp\wordpress-tests-lib"
```

`$WP_TESTS_DIR` must contain `includes/functions.php`.

### If you don't have wordpress-tests-lib yet

One quick way:

```bash
mkdir -p /tmp/wordpress-tests-lib
svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ /tmp/wordpress-tests-lib/includes
svn co --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ /tmp/wordpress-tests-lib/data
```

Alternative via WP-CLI (if available):

```bash
wp scaffold plugin-tests language-learner-tools --dir=. --force
```

Then keep this repo's `tests/` files (do not overwrite them).

## 4) Run tests

From plugin root:

```bash
cd tests
vendor/bin/phpunit -c phpunit.xml.dist
```

Or:

```bash
cd tests
composer test
```

## 4.1) One-command local flow (Local by Flywheel)

From plugin root:

```bash
eval "$(tests/bin/setup-local-env.sh)"
tests/bin/install-wp-tests.sh "$WP_TEST_DB_NAME" "$WP_TEST_DB_USER" "$WP_TEST_DB_PASS" "$WP_TEST_DB_HOST"
tests/bin/run-tests.sh
```

Or run all steps together:

```bash
tests/bin/bootstrap-and-test.sh
```

If your shell cannot reach Local's database port, start the Local site first, then rerun the commands.

## 4.2) Using a `.env` file

Copy defaults:

```bash
cp tests/.env.example tests/.env
```

Then edit values (DB/paths/PHP binary) and run:

```bash
tests/bin/run-tests.sh
```

When you run `eval "$(tests/bin/setup-local-env.sh)"` first, exported vars take precedence over `.env`.

## 4.3) Run a specific test file

PHPUnit accepts either path style:

```bash
tests/bin/run-tests.sh tests/Integration/UserProgressSelfCheckSignalTest.php
tests/bin/run-tests.sh Integration/UserProgressSelfCheckSignalTest.php
```

## 5) What these initial tests cover

- Audio recorder role creation and required capabilities.
- `ll_tools_user_can_record()` permission behavior.
- `ll_enqueue_asset_by_timestamp()` registration/enqueue + filemtime versioning.
- API settings capability default + filter override.
- `[flashcard_widget]` primary render path with localized initial words/categories.
- Recorder "new word" flow (`ll_prepare_new_word_recording_handler`) creating draft words and categories with recording types.
- Word publish guard that blocks publish without `word_audio` when category config requires audio, and allows publish otherwise.

## 6) Browser E2E tests (Playwright)

From plugin root:

```bash
tests/bin/run-e2e.sh
```

Current primary-flow E2E specs:

- `tests/e2e/specs/quiz-mode-transitions.spec.js`
  - Opens `/learn/`, starts the first quiz card, and verifies mode transitions.
- `tests/e2e/specs/quiz-popup-open-close.spec.js`
  - Verifies quiz popup open/close behavior and page-state cleanup.
- `tests/e2e/specs/quiz-launch-config.spec.js`
  - Verifies selected card category/mode/wordset are forwarded into widget state.
- `tests/e2e/specs/flashcard-widget-start-flow.spec.js`
  - Verifies standalone `[flashcard_widget]` start flow reaches the quiz popup.
- `tests/e2e/specs/user-study-dashboard-mode-options.spec.js`
  - Verifies study panel mode buttons include base quiz modes and conditionally reveal Gender mode when selected categories support it.
- `tests/e2e/specs/flashcard-loader-wordset-isolation.spec.js`
  - Verifies stale category AJAX responses cannot overwrite current wordset data in the flashcard loader.
- `tests/e2e/specs/gender-mode-adaptive.spec.js`
  - Verifies adaptive Gender mode rules: "I don't know" behaves as wrong with 2-correct recovery, Level 1 requires 3 correct answers and learn-like intro pacing, and dashboard results always expose next-activity + next-set actions with chunk-scoped categories.

Optional env vars (set directly or in `tests/.env`):

```bash
LL_E2E_BASE_URL=http://127.0.0.1:10036
LL_E2E_LEARN_PATH=/learn/
```

Tip: if Local changes ports, `run-e2e.sh` auto-detects the active port from Local's nginx config for this site.

Run one E2E spec with either path style:

```bash
tests/bin/run-e2e.sh tests/e2e/specs/user-study-dashboard-mode-options.spec.js
tests/bin/run-e2e.sh specs/user-study-dashboard-mode-options.spec.js
```

## Notes

- Tests run against a WordPress test database, not your production site DB.
- Avoid running multiple PHPUnit commands in parallel against the same `wptests` database; InnoDB deadlocks can produce intermittent false failures.
- Keep all new tests under `tests/Integration/` and use translation-ready messages in assertions where relevant.
- `run-tests.sh` supports either Linux PHP or Local Windows `php.exe` through `bin/php-local.sh`.
- `install-wp-tests.sh` writes `WP_PHP_BINARY` and `$table_prefix` into `wp-tests-config.php` for Local Windows PHP compatibility.
- If needed, set `COMPOSER_PHAR` to a custom Composer PHAR path.
- If `run-tests.sh` fails with `Could not open input file .../tests/vendor/phpunit/phpunit/phpunit`, set an explicit Local PHP binary:
  - `PHP_BIN=/mnt/c/php/8.4/php.exe tests/bin/run-tests.sh`
