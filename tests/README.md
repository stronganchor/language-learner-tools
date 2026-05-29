# Language Learner Tools Test Framework

For AI-oriented operational guidance (how to run, add, and modify tests safely), see `tests/AI_TESTING_PLAYBOOK.md`.

This directory contains the plugin test framework:

- `composer.json`: isolated PHPUnit dependency config (separate from plugin `vendor/`).
- `phpunit.xml.dist`: PHPUnit config.
- `bootstrap.php`: boots the WordPress test suite and loads the plugin.
- `Integration/*Test.php`: PHPUnit integration tests.
- `bin/setup-local-env.sh`: detects Local site DB settings and prints export commands.
  - Prefers the active Local runtime MySQL port (when detectable from `AppData/Roaming/Local/run/*`) to avoid stale `local-site.json` ports.
  - Keeps the detected Local DB host/user/password, but defaults `WP_TEST_DB_NAME` to an isolated test schema instead of the live site schema.
- `bin/install-wp-tests.sh`: installs WordPress core + wordpress-tests-lib and writes `wp-tests-config.php`.
  - Refuses to target the live Local site database unless `ALLOW_LIVE_SITE_TEST_DB=1` is set explicitly.
- `bin/php-local.sh`: PHP wrapper that supports Linux PHP or Local Windows `php.exe` with required extensions.
- `bin/run-tests.sh`: installs test deps (if needed), repairs missing WordPress test libraries when possible, and runs PHPUnit.
  - When this repo is inside a Local site and `local-site.json` is available, it now auto-applies `bin/setup-local-env.sh` before bootstrap so stale `.env` DB ports do not keep pointing at an old Local runtime.
  - On PHPUnit 12+, it also patches the local `wordpress-tests-lib` bootstrap to replace WordPress' removed legacy annotation-parser calls.
  - It also runs PHPUnit with a temporary cache directory outside the repo and cleans stale `tests/.phpunit.cache` leftovers so test runs do not dirty the plugin worktree.
- `bin/bootstrap-and-test.sh`: end-to-end helper (`setup -> install -> test`).
- `bin/setup-local-http-env.sh`: detects the current Local HTTP port for this site path and exports Playwright URL vars.
- `bin/run-e2e.sh`: installs Playwright deps/browsers (if needed) and runs browser E2E tests.
- `bin/run-performance-benchmark.sh`: reuses or refreshes the static `ll-perf-*` Local-site fixture and runs the opt-in performance benchmark.
- `e2e/*`: Playwright configuration + browser test specs.

## 1) Prerequisites

- PHP CLI 8.3+.
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
tests/bin/run-tests.sh
```

Or:

```bash
cd tests
composer test
```

`composer test` now delegates to `bin/run-tests.sh` so the WordPress test-library compatibility patch and Local env autodetection are applied consistently. Prefer the wrapper over calling `vendor/bin/phpunit` directly.

If `WP_TESTS_DIR` or `WP_CORE_DIR` are missing or stale, `tests/bin/run-tests.sh` will try to repair the local WordPress test framework automatically by invoking `tests/bin/install-wp-tests.sh` before PHPUnit starts.
If the test database itself is dirty or bootstrap shows `Duplicate entry '1' for key 'PRIMARY'` in `wptests_terms`, rerun with `LL_TOOLS_RESET_WP_TEST_DB=1` to force `install-wp-tests.sh` to drop and recreate the local test database before PHPUnit starts.

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
If the site is running but the detected DB port still refuses connections, compare:

```bash
tests/bin/setup-local-env.sh
tests/bin/setup-local-http-env.sh
```

Some Local installs keep stale ports in `local-site.json`; in that case inspect the active runtime MySQL config (`.../Local/run/<id>/conf/mysql/my.cnf`) and temporarily override `WP_TEST_DB_HOST`.
`setup-local-env.sh` also exports `LOCAL_DB_PORT_SOURCE` and (when runtime detection succeeds) `LOCAL_ACTIVE_MYSQL_CONF` / `LOCAL_ACTIVE_NGINX_CONF` to show which Local runtime files were used.
It also exports `LOCAL_LIVE_DB_NAME` / `LOCAL_LIVE_DB_HOST` so `install-wp-tests.sh` can block accidental writes to the live site database.
If the WordPress test library itself is missing or incomplete, `run-tests.sh` now prints the broken paths and attempts a self-repair before it hands off to PHPUnit.
When the test runtime is Windows `php.exe`, the bootstrap now defaults to the Windows temp directory (`%TEMP%/wordpress` and `%TEMP%/wordpress-tests-lib`) because WordPress' own bootstrap checks are not reliable against WSL UNC paths.
Repeated repair attempts reuse cached WordPress archives in `/tmp` so the installer does not redownload core and `wordpress-develop` on every run.

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
`tests/bin/run-tests.sh` now also auto-refreshes Local DB/PHP helpers when it can detect this Local site, so stale `.env` DB ports should no longer block PHPUnit bootstrap by default.
If you need to keep a custom non-Local setup, set `LL_TOOLS_SKIP_AUTO_LOCAL_ENV=1` before `tests/bin/run-tests.sh`.

## 4.3) Run a specific test file

PHPUnit accepts either path style:

```bash
tests/bin/run-tests.sh tests/Integration/UserProgressSelfCheckSignalTest.php
tests/bin/run-tests.sh Integration/UserProgressSelfCheckSignalTest.php
```

## 5) What the PHPUnit suite covers (high level)

For the current inventory, run:

```bash
find tests/Integration -maxdepth 1 -name '*Test.php' | sort
```

- Audio recorder role creation and required capabilities.
- `ll_tools_user_can_record()` permission behavior.
- `ll_enqueue_asset_by_timestamp()` registration/enqueue + filemtime versioning.
- API settings capability default + filter override.
- `[flashcard_widget]` primary render path with localized initial words/categories.
- Recorder "new word" flow (`ll_prepare_new_word_recording_handler`) creating draft words and categories with recording types.
- Word publish guard that blocks publish without `word_audio` when category config requires audio, and allows publish otherwise.
- Bulk translations security guards for fetch/save/migrate handlers (per-post edit checks, non-editable skips, mixed selections).
- Dictionary import/search regressions including grouped senses, multilingual gloss columns, source/dialect attribution filters, snapshot override/undo flows, and shared-entry wordset scope refreshes.
- Additional integration tests cover prompt cards, internal review notes, content lessons, teacher classes, wordset games availability and pool filtering, import/export flows, media proxy behavior, login-window registration, user progress recommendations, wordset progress reset actions, and more.

## 6) Browser E2E tests (Playwright)

From plugin root:

```bash
tests/bin/run-e2e.sh
```

Read-only live-site smoke checks use a separate Playwright config and a local-only site list:

```bash
tests/bin/run-live-smoke.sh
```

For the current inventory, run:

```bash
find tests/e2e/specs -maxdepth 1 -name '*.spec.js' | sort
```

Representative E2E coverage areas:

- `tests/e2e/helpers/admin.js`
  - Provides the shared admin login, temporary page creation, and cleanup helpers used by admin-authenticated browser specs.
- `tests/e2e/specs/admin-maintenance-pages.spec.js`
  - Verifies the WebP optimizer and orphaned-media admin pages load their review controls without unrelated maintenance scans breaking the page.
- `tests/e2e/specs/admin-import-preview-undo.spec.js`
  - Verifies the admin import UI can preview a server-side zip bundle, confirm import, and undo the resulting import record.
- `tests/e2e/specs/flashcard-gender-support-normalization.spec.js`
  - Verifies category gender-support flags normalize correctly before Gender mode enablement checks.
- `tests/e2e/specs/flashcard-loader-wordset-isolation.spec.js`
  - Verifies stale category AJAX responses cannot overwrite current wordset data in the flashcard loader.
- `tests/e2e/specs/flashcard-image-translation-option-render.spec.js`
  - Verifies image answer options with translation captions keep full image tile sizing, adapt caption rows, hide empty captions cleanly, and stay inside small embedded iframe viewports without shrinking large iframe/desktop cards.
- `tests/e2e/specs/flashcard-study-prefs-save.spec.js`
  - Verifies rapid practice-mode preference saves keep the latest queued study state.
- `tests/e2e/specs/flashcard-widget-start-flow.spec.js`
  - Verifies standalone `[flashcard_widget]` start flow reaches the quiz popup.
- `tests/e2e/specs/page-speed-throttled-load.spec.js`
  - Verifies the learn page still becomes usable within a configurable budget while Chromium throttles localhost traffic to a slower network profile.
- `tests/e2e/specs/wordset-page-speed-large-wordset.spec.js`
  - Verifies a large wordset page such as `/genc-palu/` reaches visible category cards within a configurable throttled-load budget.
- `tests/e2e/specs/performance-benchmark.spec.js`
  - Opt-in benchmark for static `ll-perf-small`, `ll-perf-medium`, and `ll-perf-large` fixtures. It records medians for seeded learn-grid, wordset, progress, games, search, and quiz-popup scenarios, then compares them with the previous matching JSONL history record.
- `tests/e2e/specs/wordset-manager-settings-ui.spec.js`
  - Verifies frontend wordset-manager tools stay usable under narrow/mobile layouts, including the Wordset Editor table and full-width recording details.
- `tests/e2e/specs/gender-mode-adaptive.spec.js`
  - Verifies adaptive Gender mode rules: "I don't know" behaves as wrong with 2-correct recovery, Level 1 requires 3 correct answers and learn-like intro pacing, and dashboard results always expose next-activity + next-set actions with chunk-scoped categories.
- `tests/e2e/specs/listening-sequence-weighting.spec.js`
  - Verifies Listening mode sequence weighting and replay behavior stay within expected constraints.
- `tests/e2e/specs/listening-visualizer-regression.spec.js`
  - Verifies Listening visualizer warmup/resume behavior and countdown-hide recovery.
- `tests/e2e/specs/offline-app-shell-launcher.spec.js`
  - Verifies the offline app launcher filters/sorts/selects categories, launches the real shell wiring, and exercises the sync panel sign-in, manual sync, and disconnect flow against a fake progress tracker.
- `tests/e2e/specs/practice-option-constraints.spec.js`
  - Verifies Practice mode answer option counts/constraints across category setups.
- `tests/e2e/specs/quiz-launch-config.spec.js`
  - Verifies selected card category/mode/wordset are forwarded into widget state.
- `tests/e2e/specs/quiz-mode-transitions.spec.js`
  - Opens `/learn/`, starts the first quiz card, and verifies mode transitions.
- `tests/e2e/specs/quiz-popup-fallback-modal.spec.js`
  - Verifies quiz launch falls back to the iframe modal shell when the inline flashcard launcher is absent.
- `tests/e2e/specs/quiz-popup-open-close.spec.js`
  - Verifies quiz popup open/close behavior and page-state cleanup.
- `tests/e2e/specs/quiz-results-repeat-restart.spec.js`
  - Verifies the results-page Repeat action starts a fresh practice round instead of leaving the loader stuck.
- `tests/e2e/specs/self-check-shared-image-grouping.spec.js`
  - Verifies Self-check groups words that share one image into a single review card while preserving per-word answer audio.
- `tests/e2e/specs/wordset-page-lazy-loading.spec.js`
  - Verifies lazy wordset-page card hydration, deferred preview shells, unloaded category/content search hydration, inactive-category card actions, and mixed content lesson order with category-only selection behavior.
- `tests/e2e/specs/site-tools-frontend.spec.js`
  - Verifies the frontend `[ll_site_tools]` workspace exposes admin setting forms, recording-type controls, managed-page controls, and maintenance actions, including a safe cache-flush submit path and mobile overflow check.
- `tests/e2e/specs/audio-recorder-prompt-card-fixture.spec.js`
  - Verifies a local WordPress-backed prompt-card fixture is exposed through `[audio_recording_interface]` as a prompt-audio queue item with the expected wordset, category, and prompt-card payload.
- `tests/e2e/specs/teacher-classes-frontend.spec.js`
  - Verifies a teacher-role user can create a class from the frontend wordset classes page, return to the newly selected class, see the success notice, and delete the class again.
- `tests/e2e/specs/transcription-manager-review-filter-regression.spec.js`
  - Verifies marking a transcription as reviewed updates the row in place and does not auto-refresh the filtered result list out from under the current admin session.
- `tests/e2e/specs/vocab-lesson-bulk-editor-mobile.spec.js`
  - Verifies vocab lesson bulk editor controls stay within viewport on mobile layouts.
- `tests/e2e/specs/vocab-lesson-word-editor-mobile.spec.js`
  - Verifies the vocab lesson word editor keeps its save/cancel footer visible while the form body scrolls on mobile layouts.
- `tests/e2e/specs/vocab-lesson-deferred-grid.spec.js`
  - Verifies deferred lesson shells hydrate the word-grid markup and keep hidden feedback hidden under theme overrides.
- `tests/e2e/specs/vocab-lesson-prereq-editor.spec.js`
  - Verifies lesson-page prerequisite editing supports search, multi-select, deselect, and stable saved-state feedback on desktop and mobile layouts.
- `tests/e2e/specs/maintenance-doc-contracts.spec.js`
  - Verifies source/docs contracts that are cheap to check in the Playwright runner, including registered public shortcodes being documented in `README.md` and high-confidence hardcoded UI-string contexts using WordPress i18n wrappers.
- Known E2E coverage gaps still worth adding:
  - WordPress-backed content lesson fixtures for real content lesson routes and media payloads beyond the covered mixed-grid order/search/selection browser behavior.
  - Prompt-card recorder permissions plus real media upload beyond the local WordPress-backed queue fixture, self-contained prompt-card upload/advance regression, existing prompt-card quiz payload coverage, and lesson-grid browser coverage.
  - Teacher class assignment/invite/progress-table flows beyond the covered frontend create/delete path.
  - Offline app service-worker/install behavior and real remote snapshot sync edge cases.
  - Deeper Unscramble interaction and completion flows plus Speaking Practice recording/API behavior.
- `tests/e2e/specs/wordset-pages-listening-launch.spec.js`
  - Verifies wordset page launch actions can open Listening mode with the expected category/wordset context.
- `tests/e2e/specs/wordset-games-space-shooter.spec.js`
  - Verifies the wordset games page bootstraps availability correctly, covers Line Up startup/retry/reorder/completion and Unscramble startup into their dedicated sequence stage, checks Word Stack layout/fall-speed regressions, and verifies Space Shooter/Bubble Pop runtime behavior and progress events.
- Additional specs in the same folder cover audio-recorder new-word flows, quiz audio gating, mobile/layout regressions, text fitting, wordset progress/loading shells, and more. Treat this section as a representative summary rather than a full inventory.

Optional env vars (set directly or in `tests/.env`):

```bash
LL_E2E_BASE_URL=http://127.0.0.1:10036
LL_E2E_LEARN_PATH=/learn/
LL_E2E_STANDALONE_PATH=/english/
LL_E2E_ADMIN_USER=codex
LL_E2E_ADMIN_PASS=your-temp-local-password
LL_E2E_PAGE_SPEED_PATH=/learn/
LL_E2E_PAGE_SPEED_SELECTOR=.ll-quiz-page-trigger
LL_E2E_PAGE_SPEED_LATENCY_MS=150
LL_E2E_PAGE_SPEED_DOWNLOAD_KBPS=1600
LL_E2E_PAGE_SPEED_UPLOAD_KBPS=750
LL_E2E_PAGE_SPEED_CPU_SLOWDOWN_RATE=1
LL_E2E_PAGE_SPEED_MAX_DOMCONTENTLOADED_MS=7000
LL_E2E_PAGE_SPEED_MAX_ACTIONABLE_MS=10000
LL_E2E_PAGE_SPEED_MAX_LOAD_MS=15000
LL_E2E_PERF_RUNS=3
LL_E2E_PERF_HISTORY_FILE=tests/performance/history/performance-history.jsonl
```

Live smoke runner config:

- Create a local JSON file at `tests/e2e/live-smoke/sites.local.json` by copying `tests/e2e/live-smoke/sites.example.json`.
- Or point `LL_LIVE_SITES_FILE` at another local JSON file.
- `tests/bin/run-live-smoke.sh` is serial and intended for anonymous, low-impact public-page checks only.
- Keep live-site entries read-only. If opening the quiz UI triggers same-origin `POST` traffic or throws client errors on a public site, omit that entry's `interaction` block and limit coverage to shell assertions plus optional search exercises.
- If a homepage is only a wordset-button hub, add `"navigation": { "type": "wordsetButtonMostLessons" }` so the smoke run clicks the visible button with the highest lesson count before applying the normal wordset-page assertions.
- The runner treats `POST /wp-admin/admin-ajax.php?action=ll_get_words_by_category` and `POST /wp-admin/admin-ajax.php?action=ll_tools_wordset_page_lazy_cards` as allowed read-style public-page requests; other same-origin non-GET requests still fail unless you explicitly allow them in the site config.

You can keep machine-local overrides (especially admin creds) in `tests/.env.local` (gitignored).

Tip: if Local changes ports, `run-e2e.sh` auto-detects the active port from Local's nginx config for this site.

Run one E2E spec with either path style:

```bash
tests/bin/run-e2e.sh tests/e2e/specs/wordset-pages-listening-launch.spec.js
tests/bin/run-e2e.sh specs/wordset-pages-listening-launch.spec.js
```

Network-throttled page-speed regression:

- The throttled spec uses Chromium DevTools network emulation, so it can slow down `localhost`/Local-site requests even when the site is running on your machine.
- Default target:
  - `LL_E2E_PAGE_SPEED_PATH` or `LL_E2E_LEARN_PATH`
  - waits for `LL_E2E_PAGE_SPEED_SELECTOR` (defaults to `.ll-quiz-page-trigger`)
- Default throttle profile:
  - 150 ms latency
  - 1600 kbps download
  - 750 kbps upload
  - optional CPU slowdown via `LL_E2E_PAGE_SPEED_CPU_SLOWDOWN_RATE`
- Default budgets:
  - DOMContentLoaded: 7000 ms
  - first actionable control visible: 10000 ms
  - full load event: 15000 ms
- The spec warms the target page once through Playwright's request client before the measured browser navigation so Local cold-start noise does not dominate the result.
- Run it directly:

```bash
tests/bin/run-e2e.sh specs/page-speed-throttled-load.spec.js
```

- If it fails on a slower machine, inspect the attached `page-speed-metrics` artifact in the Playwright report, then adjust the `LL_E2E_PAGE_SPEED_*` env vars rather than hardcoding machine-specific values into the spec.
- The large-wordset companion spec defaults to `/genc-palu/`, waits for real category cards via `.ll-wordset-card[data-cat-id]:not(.ll-wordset-card--lazy-placeholder):not([data-ll-wordset-inline-placeholder])`, and uses `LL_E2E_WORDSET_PAGE_SPEED_*` env vars:

```bash
tests/bin/run-e2e.sh specs/wordset-page-speed-large-wordset.spec.js
```

Seeded performance benchmark:

- Use this when you want release-to-release performance history rather than a single fixed-budget page-speed check.
- The fixture is defined in `tests/performance/fixtures/performance-wordsets.json`; keep the wordset/category/word counts static and bump `fixtureVersion` when that file changes.
- The seeder reuses the existing fixture when the manifest version, checksum, expected counts, fixture tags, and key pages still match.
- The runner writes one JSONL record with plugin version, git commit, fixture version, throttle profile, medians, p95s, and comparison results.
- Progress-page scenarios are authenticated, so keep `LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` set in `tests/.env.local`.

```bash
tests/bin/run-performance-benchmark.sh
```

- By default, history is appended to `tests/performance/history/performance-history.jsonl`.
- Set `LL_E2E_PERF_WRITE_HISTORY=0` for a dry verification run that does not modify the history log.
- Set `LL_E2E_PERF_COMPARE_HISTORY=0` to record metrics without failing on a historical comparison.
- Set `LL_PERF_FORCE_SEED=1` for a full fixture reset, or `LL_PERF_SEED_ONLY=1` when you only want to verify or refresh the fixture.

## Notes

- Tests run against a WordPress test database, not your production site DB.
- Avoid running multiple PHPUnit commands in parallel against the same `wptests` database; InnoDB deadlocks can produce intermittent false failures.
- Keep all new tests under `tests/Integration/` and use translation-ready messages in assertions where relevant.
- `run-tests.sh` supports either Linux PHP or Local Windows `php.exe` through `bin/php-local.sh`.
- `install-wp-tests.sh` writes `WP_PHP_BINARY` and `$table_prefix` into `wp-tests-config.php` for Local Windows PHP compatibility.
- Playwright failure artifacts default to `tests/e2e/test-results/` (relative to `tests/e2e/`), and the HTML report is in `tests/e2e/playwright-report/`.
- If `tests/.run-tests.lock` is left behind after an interrupted run, first confirm no PHPUnit wrapper is active, then remove that stale lock before rerunning.
- If needed, set `COMPOSER_PHAR` to a custom Composer PHAR path.
- If `run-tests.sh` fails with `Could not open input file .../tests/vendor/phpunit/phpunit/phpunit`, set an explicit Local PHP binary:
  - `PHP_BIN=/mnt/c/php/8.4/php.exe tests/bin/run-tests.sh`
- Dictionary browser/import changes should always include `tests/bin/run-tests.sh Integration/DictionaryFeatureTest.php` before the full suite. Dictionary admin-import UI changes should also include `tests/bin/run-e2e.sh specs/admin-import-preview-undo.spec.js`.
- One PHPUnit import regression may be skipped on some machines:
  - `ExternalCsvBundleImportTest::test_import_decodes_windows_1255_csv_values_and_generates_quiz_page`
  - This depends on runtime `iconv` / `mbstring` support for reliably round-tripping the non-UTF Hebrew fixture encoding.
