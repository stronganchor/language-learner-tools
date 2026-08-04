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

For the database-free core full-catalog locale gates, run:

```bash
php scripts/check-public-i18n.php --full-catalog=tr_TR --fail-on-missing --details --json
php scripts/check-public-i18n.php --full-catalog=de_DE --fail-on-missing --details --json
```

This treats missing, blank, partial, fuzzy, stale, duplicate, structurally
invalid, or uncompiled current POT entries as failures. It compares compiled
MO and PHP messages with each PO; `PublicUiTranslationManifestTest.php` also
verifies that every active entry for each locale configured under
`core_full_locales` in `languages/tier2-public-ui-sources.php` reaches the
compiled runtime catalog.

## 5) What the PHPUnit suite covers (high level)

For the current inventory, run:

```bash
find tests/Integration -maxdepth 1 -name '*Test.php' | sort
```

- Audio recorder role creation and required capabilities.
- `ll_tools_user_can_record()` permission behavior.
- `WordsetIsolationMigrationTest` verifies version-4 replay into the durable version-5 migration, bounded keyset batches, exact checkpoint persistence, no-wordset skips, all-or-nothing category/image copies, independent explicit category assignments, expansion only into actually added runtime wordset scopes, full source-by-wordset migration expansion, unrelated slug-collision rejection, complete discovery/preflight of every category-bearing user store, category-family-preserving global goal repair without preferred-wordset cross-products, exact preservation of normalized historical progress for deleted categories with fail-closed rejection when repair would change it, prompt-progress remapping, exact recommendation-deferral re-keying with explicit legacy-drop policy, CAS user repair, oversized option-rule rejection, failure retry behavior, nested deferred-queue preservation, suppression of eager unbounded category maintenance, a new migration-owned reconciliation generation before each publication, retry/lock/partial-scheduling durability, rejection of scoped vocab workers, tagged complete cursor-zero generated-page passes after pre-existing workers finish, stranded-child event repair, supervision through exact child completion, and completed-state persistence before lease-fenced target-version publication.
- The same migration coverage verifies that queue/last hints referencing a definitely deleted category are discarded whole while lookup and live owner/source failures remain fail-closed, valid siblings are remapped and re-signed, and runtime getters self-repair through exact-value CAS without overwriting raw-SQL-simulated concurrent queue/last refreshes hidden behind a stale object cache. Oversized queues remain stored but return an empty runtime view and fail bounded migration preflight; the exact write snapshot is re-preflighted, and a lookup failure that appears only during repair still stops the user phase without writing.
- `OfflineAppSyncTest` verifies the full InnoDB column/index contract, indexed per-session authentication, exact-hash touch/revocation fencing, transactional eight-session eviction, raw-snapshot CAS for bounded legacy user-meta import, CAS-safe legacy revocation, table-first authentication, and fail-closed conflict behavior.
- Recorder integration coverage verifies the recording interface renders bounded identity-free queue-summary shells and button cards with counts/previews while resolved empty categories disappear; the no-category shortcode query-shape regression forbids the legacy uncached relationship-wide discovery scans. The manager stream performs zero cold summary refreshes during PHP render, emits three named shimmer cards for the active batch plus lightweight hidden markers, exposes only an error-state Retry control, and prefers the queue image's requested thumbnail over a linked word thumbnail or raw oversized URL. Recorder stream generations change for ordered category identity/scope changes but remain stable across ordinary per-category content invalidation so later resource-safe batches do not restart the overview. Completed target summaries also invalidate and rebuild when a wordless image's sibling category changes privacy/scope or is deleted, because that sibling can change the image's effective recording-type union. A same-user admin-to-recorder demotion regression proves the user-scoped catalog, category map, and completed summary all stop reusing private category names, counts, and previews.
- `WordsetPageCategorySearchIndexTest` verifies the public category-search materializer installs only after schema/primary-key readback, migrates idempotently to generation-scoped rows, advances with bounded ID-keyset batches, chunks relationship and byte-limited writes, resumes durable state, rotates generations on expired-lease takeover, hides late stale rows, fences publication with an exact-owner lease and dependency-signature/generation CAS, backs off failures without a hot cron loop, uses locale-independent normalization, and returns direct bounded matches without hydrating the complete wordset. It also verifies staff can find cards through pending `recording_text` using bounded published word/audio candidates without exposing the transcription, scanning for queries shorter than three characters, or changing anonymous results.
- `VocabLessonDeferredGridTest` verifies the deferred shell exposes the exact cached expected lesson count while hydrating content for only the first six cards, keeps specific wrong-answer counting category-scoped, and bounds large-category placeholder DOM with one remainder card. Its staff regressions prove large lessons scan and render in bounded pages, keep per-user order state separate from public state, retain drafts, preserve missing-media warning cards in candidate-specific page renders, and replace repeated hidden editor/category catalogs with lightweight detached-editor triggers.
- `UserStudyAnalyticsTest` verifies logged-in multi-category launch plans apply exact `new`/`studied`/`learned`/`starred`/`hard` progress criteria over the explicit `selection_ids_only` analytics scope, accept a bounded exact-candidate snapshot while still intersecting it with current renderable wordset/category membership, and keep that launch-only path out of the full category-card catalog without narrowing the legacy ID-inclusive analytics contract. Membership or progress-query failures remain retryable AJAX errors instead of successful empty selections. Plans preserve every matching word exactly once across successful balanced category-aware transport chunks, widen sparse tails only to the hard category cap, return a typed fail-closed error when an even sparser layout cannot form valid rounds, enforce per-chunk word/category caps plus nonce and wordset access through the AJAX route, and leave media hydration to the current candidate-specific chunk. Learning coverage also enforces effective-presentation-compatible 8-15-word chunks, including audio-to-text fallback, exact target/filler separation, and global filler matching that reserves shared words for constrained compatibility groups. Zazaca-scale regressions cover 210 saved categories, a 207-category/2,714-candidate exact planner, bounded SQL, zero full-catalog rebuilds, complete unique chunk coverage, and a one-category opening chunk. They also enforce the narrow starred-ID read and a user-invariant, schema/epoch-keyed presentation cache. The deterministic presentation-map regression requires one bounded warm lookup with zero per-category resolver calls, keeps fallback metadata intact, preserves stale-row invalidation across partial primes, validates source and aggregate aspect-version changes including a concurrent bump, and proves cache-write failure cannot poison the current launch. `WordsetPageDeferredStudyCatalogResourceTest` crosses the real Progress-render-to-later-request transient boundary and enforces the same bounded launch lookup. Browser coverage requires the popup/loader in the original click turn, keeps the same modal through ID lookup, planning, bounded hydration, and widget commit, and proves Close/Escape aborts the active request and allows an immediate non-overlapping retry.
- `CanonicalWordImageReadPathsTest` and `VocabLessonPromptCardCountTest` verify compact image-qualified vocabulary counts materialize the target-wordset copy-source set instead of correlating it per word, preserve direct/linked/copy semantics (including prompt-card fallback), ignore foreign or isolation-disabled copies, and never create a missing copy during an aggregate read.
- `WordsetPageSavedSortInitialChunkTest` verifies that a 227-category saved metric sort preserves canonical initial/lazy offsets while analytics is deferred, retains the client sort preference, keeps the full localized runtime config under its sparse-payload budget, and performs no full metrics-collector or `word_audio` hydration.
- `WordsetPageLazyCardsAjaxTest` verifies lazy category shells are ID-only ordered references into a sparse complete registry, explicit negative capability/progress state survives compaction, Genç-scale registry JSON stays bounded, and lazy payload persistence failures retain a complete non-AJAX fallback.
- `WordsetSettingsCustomUiTest` verifies the settings hub uses a cheap Advanced summary without entering the flashcard category-ordering catalog or answer-option preview sampler, while the opened Advanced tool keeps its dedicated runtime.
- `WordsetCategoryOrderingAtomicSaveTest` verifies the frontend Advanced and legacy taxonomy forms validate category registries, prerequisite payloads, and cycles before changing ordering meta; preserve all three category-ordering keys on rejection; roll back all earlier writes after a later meta failure; keep unrelated settings; and show a partial-success warning.
- `WordsetButtonsShortcodeTest` verifies incomplete signed-in count generations render a nonce-protected bounded loader, while cold anonymous generations emit non-cacheable shells with expiring context-bound status tokens. The public status action is rate-limited, accepts no caller scope, performs no eligibility scan, schedules one deduplicated continuation, and publishes only complete public cards through epoch-fenced exact/LKG keys; authoritative complete-empty generations remain empty.
- `RankedWordListShortcodeTest` verifies numeric rank ordering with an ID tie-breaker, exact-category filtering, one hard-capped page per query, independent list-scoped pagination, page-scoped bulk audio collection, public asset detection, and the bounded idempotent ID/title rank-row importer.
- `ContentLessonIndexShortcodeTest` verifies exact wordset/category scoping, list-specific bounded pagination and hard caps, completion display, exact legacy source/category/default-wordset contract backfills, write-free idempotent reruns, safe cached-link/signup rendering, migrated prerequisite/dependent shims, and nonreplacement of shortcode tags still owned by the legacy plugin.
- `ContentLessonProgressTest` verifies completion normalization and compare-and-swap writes, guarded request/readback behavior, privacy export/erasure, bounded prerequisite/dependent rows, article settings and template rendering, cycle rejection, and fail-closed identity/relationship reads.
- `LegacyContentLessonMigrationTest` verifies bounded lesson/relation/completion pages, exact source and target snapshots, idempotent replays, collision-safe link rewriting, status/wordset preservation, completion audit fencing, and retryable fail-closed query paths.
- `SemanticMarkShortcodeTest` verifies the class-only semantic mark renderer, exact legacy color aliases, nested shortcode sanitization, valid inline output, asset detection, and the documented visual contract.
- `WordGridCategoryEditTest` verifies a scoped category edit and selected-state read replace/show only the current wordset's assignments even when another wordset owns the same isolation source, and that explicit category writes do not cross-expand valid owned families.
- `ll_enqueue_asset_by_timestamp()` registration/enqueue + filemtime versioning.
- API settings capability default + filter override.
- `[flashcard_widget]` primary render path with localized categories and deferred
  initial word rows.
  - `FlashcardWidgetFlowTest` also guards single-owner data/message localization and the dependency edges that make those globals available before startup consumers execute; the base test harness clears those plugin-owned localization slots between simulated requests because core `WP_Scripts` otherwise persists them across PHPUnit cases.
- `FlashcardPayloadMaterializerTest` verifies a cold category advances through
  bounded ID-keyset batches without `OFFSET`, publishes only after completion,
  pages the immutable generation through signed cursors, rejects tampering and
  signature-drift cursors, redacts speaker identifiers at the public AJAX
  boundary, keeps private wordset/category support out of public rows, holds a
  scope lease across each bounded page read, exact-generation-fences cleanup,
  sweeps old rows whose state disappeared after a lost lease, and prevents
  queued workers from reviving missing or retiring state. Its fixed option-row
  reader returns no cursor, waits for a completed generation, excludes target
  aliases, and caps useful same-scope distractors at 12.
- `QuizPagesShortcodeCatalogTest` verifies durable keyset catalog generations, stale-serving and usable-snapshot rules (including empty stale snapshots), epoch-drift recovery without resetting the only valid compatible partial generation, plugin-versioned builder fencing, worker-side suppression of every per-category derived transient namespace, early unrelated-cron suppression, and signed bounded no-JavaScript continuation.
- Recorder "new word" flow (`ll_prepare_new_word_recording_handler`) creating draft words and categories with recording types.
- Word publish guard that blocks publish without `word_audio` when category config requires audio, and allows publish otherwise.
- Bulk translations security guards for fetch/save/migrate handlers (per-post edit checks, non-editable skips, mixed selections).
- Legacy Word Images fixer batching, durable cursor readback, and scan-free page rendering.
- Dictionary import/search regressions including grouped senses, multilingual gloss columns, source/dialect attribution filters, snapshot override/undo flows, and shared-entry wordset scope refreshes.
- Teacher-class integration coverage observes the legacy admin query shapes, proving bounded plus-one class/account pages, deterministic ID tie-breakers, globally ordered bounded learner-progress hydration, empty/stale-page normalization, continuation links, and redirect-state preservation.
- `PublicAjaxResourceGuardTest`, `SecurityHardeningRegressionTest`,
  `DictionaryFeatureTest`, and the flashcard regressions cover atomic anonymous
  miss budgets, exact-owner per-client leases, same-query cache waits, bounded
  candidate input, the separate materialized-page request budget, serialized
  multi-category hydration, retryable warming responses, response-only progress
  overlay, speaker-ID redaction, quiz-eligible option-pool refill past earlier
  invalid rows (including canonical prompt-card answers), completed-generation
  fallback after a fast-window underfill, and cache-hit bypass behavior.
- `AutomationRestApiTest` covers aggregate report-summary counts plus bounded review-note and cross-post-type interlinear pagination; interlinear list payloads are omitted by default while exact-lesson reads retain the payload-on default.
- `RestPasswordAuthAdmissionTest` covers coarse direct-peer plus peer/login raw-password admission, rotating-login resistance, generic failures, successful reservation refunds, and cleanup namespace registration.
- `DictionaryPublicFilterBoundsTest` covers byte/cardinality/shape admission for all public dictionary query arguments, early AJAX rejection, safe static-cache defaults, and normal bounded cache hits.
- `MediaProxyFallbackCacheTest` covers bounded disk fallback storage, stale/contended service, failure backoff, exact-owner publishing, cache pruning, and attachment/scheduled cleanup.
- `WordsetPageInactiveCategoryCardsTest` covers persisted preview cursors, same-item retry, bounded continuation, contention, and exact-owner lease renewal/release; `WordsetPageWarmLessonMapTest` covers single-owner rebuilds and immediate last-known-good service.
- `FlashcardShellRendererTest` and `QuizPagePostTypeTest` cover shared/standalone dialog semantics and translated iframe recovery configuration.
- `SecurityHardeningRegressionTest` covers the hosted-STT pre-read and bounded-read audio-size ceiling, and `AudioProcessorQueuePaginationTest` covers signed user/tab keyset cursors with the legacy direct deep-page fallback.
- Additional integration tests cover prompt cards, internal review notes, content lessons, teacher classes, wordset games availability and pool filtering, shared flashcard shell rendering, audio credit grid cache batching/stale-lock fallback, image copyright grid privacy/resource guards, import/export/archive boundary checks, media proxy behavior, login-window registration, user progress recommendations, wordset progress reset actions, and more.

## 6) Browser E2E tests (Playwright)

From plugin root:

```bash
tests/bin/run-e2e.sh
```

For automation runs where a 20-minute cap is too tight for the whole serial
suite, use Playwright shards:

```bash
tests/bin/run-e2e.sh --shard=1/4
tests/bin/run-e2e.sh --shard=2/4
tests/bin/run-e2e.sh --shard=3/4
tests/bin/run-e2e.sh --shard=4/4
```

The June 10, 2026 local runner-health check listed 314 tests at that point, and
the four shards completed with 313 passed and 1 skipped. Later E2E follow-ups
expanded the suite; the July 10, 2026 full discovery listed 390 tests in 90
files, the July 17 discovery listed 436 tests in 95 files, the July 24
discovery listed 453 tests in 95 files, and a July 31, 2026 no-install
discovery lists 479 tests in 97 spec files.
These are dated local discovery snapshots, not fixed suite-size expectations.
Treat a short unsharded timeout as an automation budget problem unless a
shard isolates a hung spec; if the unsharded command still stalls beyond 35
minutes after shards pass, investigate suite-level state leakage or Local-site
slowness.

Read-only live-site smoke checks use a separate Playwright config and a local-only site list:

```bash
tests/bin/run-live-smoke.sh
```

For the current inventory, run:

```bash
tests/bin/run-e2e.sh --list
```

Representative E2E coverage areas:

- `tests/e2e/helpers/admin.js`
  - Provides the shared admin login, bounded REST fixture calls, temporary page creation, and cleanup helpers used by admin-authenticated browser specs.
- `tests/e2e/specs/admin-maintenance-pages.spec.js`
  - Verifies the WebP optimizer and orphaned-media admin pages load their review controls without unrelated maintenance scans breaking the page.
- `tests/e2e/specs/audio-image-matcher-pagination.spec.js`
  - Verifies matcher requests stay serialized, delayed Start/Skip/page actions cannot overlap, image and next-audio failures expose retry, timed-out reads abort cleanly, slow assignment writes stay authoritative, failed assignments restore the current choice, native image buttons work once via Enter or Space, and rematches refresh both old/new used states.
- `tests/e2e/specs/audio-recorder-category-switch.spec.js`
  - Verifies the category-neutral recorder overview, three neutral loading shells plus overflow cue, exact completed counts, dedicated category-page navigation/back state, and focused queue continuation without the removed dropdown/in-place switching path.
- `tests/e2e/specs/wordset-buttons-loading-refresh.spec.js`
  - Verifies the logged-in wordset-button shell serially retries bounded authenticated refreshes, preserves shortcode attributes, honors durable server backoff without exhausting its failure budget, refreshes expired nonces, discovers late page-builder shells, exposes a translated and theme-resistant manual retry state after terminal failures, replaces itself after exact completion, and never overlaps requests.
- `tests/e2e/specs/image-aspect-normalizer-worklist-pagination.spec.js`
  - Verifies Image Aspect Normalizer worklist status refresh advances only through explicit bounded pages.
- `tests/e2e/specs/admin-import-preview-undo.spec.js`
  - Verifies the admin import UI can preview a server-side zip bundle, confirm import, and undo the resulting import record.
- `tests/e2e/specs/flashcard-gender-support-normalization.spec.js`
  - Verifies category gender-support flags normalize correctly before Gender mode enablement checks.
- `tests/e2e/specs/flashcard-loader-wordset-isolation.spec.js`
  - Verifies stale category AJAX responses cannot overwrite current wordset
    data in the flashcard loader, category preloads are serialized, retryable
    `429` category responses are retried, immutable payload pages are drained in
    order with the rendered locale, one stale-cursor restart cannot mix
    generations, and an underfilled bounded category handoff rolls back
    atomically before quiz setup.
- `tests/e2e/specs/flashcard-category-catalog-pagination.spec.js`
  - Verifies the standalone category picker fetches later catalog pages only after Load more, sends the continuation offset and wordset scope, preserves checked categories, and hides the control at the end.
- `tests/e2e/specs/flashcard-image-translation-option-render.spec.js`
  - Verifies image answer options with translation captions keep full image tile sizing, adapt caption rows, hide empty captions cleanly, and stay inside small embedded iframe viewports without shrinking large iframe/desktop cards; white prompt images retain a visible shadow boundary on mobile and desktop.
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
  - Verifies frontend wordset-manager tools stay usable under narrow/mobile layouts, including the Wordset Editor table and full-width recording details. Recorder-queue coverage also checks selected-recorder switching, named active-batch shimmer cards plus hidden markers, a smaller first request followed by automatic resource-safe serial batches while the end sentinel remains visible, pause/resume behavior, untouched-before-pending retry fairness, resolved-empty category removal, error-only Retry recovery, incomplete/unclassified response failure states, and the absence of normal Load more/loaded-count UI or misleading numbered overview pages.
- `tests/e2e/specs/gender-mode-adaptive.spec.js`
  - Verifies adaptive Gender mode rules: "I don't know" behaves as wrong with 2-correct recovery, Level 1 requires 3 correct answers and learn-like intro pacing, and dashboard results always expose next-activity + next-set actions with chunk-scoped categories.
- `tests/e2e/specs/listening-sequence-weighting.spec.js`
  - Verifies Listening mode sequence weighting and replay behavior stay within expected constraints, while large category selections advance through a bounded prefetch window and invalidate old-session requests.
- `tests/e2e/specs/listening-visualizer-regression.spec.js`
  - Verifies Listening visualizer warmup/resume behavior and countdown-hide recovery.
- `tests/e2e/specs/offline-app-shell-launcher.spec.js`
  - Verifies the offline app launcher filters/sorts/selects categories, launches the real shell wiring, exercises the sync panel sign-in/login-failure/manual-sync/sync-failure/disconnect flow against a fake progress tracker, and applies remote sync snapshots to selected categories, progress sorting, next recommendations, and synced study preferences.
- `tests/e2e/specs/offline-app-sync-error-wp.spec.js`
  - Seeds a real WordPress offline-app bundle, signs in through `ll_tools_offline_app_login`, forces one WordPress `ll_tools_offline_app_sync` conflict response, and verifies local pending progress, sane connected state, and manual retry through the real sync handler. This closes the former WordPress-backed sync error-fixture gap; only genuinely new server conflict semantics need new cases.
- `tests/e2e/specs/practice-option-constraints.spec.js`
  - Verifies Practice mode answer option counts/constraints across category setups.
- `tests/e2e/specs/quiz-launch-config.spec.js`
  - Verifies selected card category/mode/wordset are forwarded into widget state.
- `tests/e2e/specs/quiz-popup-text-translation-options.spec.js`
  - Removes the target category from the initial localized registry to model a later catalog page, then verifies the launch trigger synthesizes the correct audio/text-translation configuration, AJAX request, and rendered answers.
- `tests/e2e/specs/text-to-text-learning-intro.spec.js`
  - Removes the target category from the initial localized registry, then verifies a learning launch preserves its text-translation prompt and text-title option through the AJAX request, synthesized category config, and introduction pair cards.
- `tests/e2e/specs/quiz-mode-transitions.spec.js`
  - Opens `/learn/`, starts the first quiz card, and verifies mode transitions.
- `tests/e2e/specs/quiz-popup-fallback-modal.spec.js`
  - Verifies quiz launch falls back to the iframe modal shell when the inline flashcard launcher is absent, including timeout/retry/embed-ready behavior and translated load-error/direct-open recovery.
- `tests/e2e/specs/quiz-popup-open-close.spec.js`
  - Verifies quiz popup open/close behavior, page-state cleanup, background selection blocking, and guarded Backspace/browser-back close behavior.
- `tests/e2e/specs/quiz-iframe-recovery.spec.js`
  - Verifies shared and standalone quiz dialogs trap focus, isolate background content, restore the opener, expose timeout/load-error recovery, wait for and accept late embed-ready signals, and honor reduced-motion preferences.
- `tests/e2e/specs/quiz-results-repeat-restart.spec.js`
  - Verifies the results-page Repeat action starts a fresh practice round instead of leaving the loader stuck.
- `tests/e2e/specs/self-check-shared-image-grouping.spec.js`
  - Verifies Self-check groups words that share one image into a single review
    card while preserving per-word answer audio, and that client-side bounded
    image-hash comparisons block similar options while preserving explicit
    similarity overrides and unconditional exact-image blocking.
- `tests/e2e/specs/wordset-page-category-search.spec.js`
  - Verifies main wordset category search uses the durable tokenized async word/translation lookup while preserving a loading state across bounded preparation retries, exposing an explicit error/Retry state instead of a false empty result, stopping irrelevant warming when a visible result navigates, pausing it while a result quiz owns the popup loader, and retaining hidden-selection cleanup, add-category hiding, clear-button behavior, and diacritic-insensitive matching. Staff pending-transcription visibility remains covered at the PHP privacy/query layer.
- `tests/e2e/specs/wordset-page-lazy-loading.spec.js`
  - Verifies lazy wordset-page card hydration from ID-only category shells and sparse registry defaults, deferred preview shells, unloaded category/content search hydration with bounded request chunks, inactive-category card actions including durable pending-to-complete deletion, and mixed content lesson order with category-only selection behavior.
- `tests/e2e/specs/wordset-page-progress-loading.spec.js`
  - Verifies the 2,714-ID Zazaca filtered snapshot is reused without a duplicate ID request, transported in one scalar request field, replanned into bounded server chunks, hydrates only the first candidate chunk at launch, and retains the full logical session identity for serial continuation. The popup and loader must appear in the original click turn, stage markers cover IDs/plan/hydration/commit, cached IDs invalidate on progress changes, active ID/plan/hydration requests really abort on Close/Escape, and immediate retries produce one non-overlapping launch. The same surface disables conflicting controls while active, rejects stale scope after a filter change, holds its inline loading state through flashcard commit, and exposes one inline Retry path after acquisition, planning, or hydration failure.
- `tests/e2e/specs/site-tools-frontend.spec.js`
  - Verifies the frontend `[ll_site_tools]` workspace exposes admin setting forms, recording-type controls, managed-page controls, and maintenance action wiring, including the cache-flush form target and mobile overflow check.
- `tests/e2e/specs/audio-recorder-prompt-card-fixture.spec.js`
  - Verifies a local WordPress-backed prompt-card fixture is exposed through `[audio_recording_interface]` as a prompt-audio queue item with the expected wordset, category, and prompt-card payload.
- `tests/e2e/specs/word-audio-theme-resilience.spec.js`
  - Verifies `[word_audio]` remains a compact 1.5rem square under later-loading Astra/Elementor-style global button rules, including hover and keyboard-focus states.
- `tests/e2e/specs/audio-recorder-prompt-card-upload.spec.js`
  - Verifies a limited `audio_recorder` user can upload prompt-card prompt audio through the real WordPress AJAX handler, stores the prompt-audio attachment, and cannot upload to an inaccessible prompt card.
- `tests/e2e/specs/audio-processor-queue-pagination.spec.js`
  - Verifies Audio Processor tabs load bounded queue pages lazily through returned keyset cursors, preserve page-local selection and tab-specific cursor state, restore a processed recording's return page, and choose the first non-empty work tab when the default queue is empty.
- `tests/e2e/specs/audio-upload-speaker-search.spec.js`
  - Verifies bulk audio upload searches a bounded manager-visible speaker endpoint and exposes selected, empty, and request-error states without preloading every account.
- `tests/e2e/specs/content-lesson-route-media.spec.js`
  - Verifies a local WordPress-backed content lesson route plays a real uploaded WAV with range support and finite duration, seeks from a transcript cue, and renders notes plus its related vocab lesson link. The same fixture verifies a corpus collection page and reader, public content-index pagination/accessibility, ranked numeric order/translations/pagination, and a retained-shadow permanent redirect.
- `tests/e2e/specs/content-lesson-progress.spec.js`
  - Verifies completion autosave sends one canonical request, exposes saving/saved accessible state, updates the completion control only after success, and preserves the prior state when saving fails.
- `tests/e2e/specs/teacher-classes-frontend.spec.js`
  - Verifies frontend teacher-class workflows including teacher-role create/delete, selected-class redirects, signup invite registration, admin assignment of an existing learner, progress-table sorting, and learner removal, plus legacy wp-admin class/account search and redirect-state preservation through deletion.
- `tests/e2e/specs/transcription-manager-review-filter-regression.spec.js`
  - Verifies marking a transcription as reviewed updates the row in place and does not auto-refresh the filtered result list out from under the current admin session.
- `tests/e2e/specs/vocab-lesson-bulk-editor-mobile.spec.js`
  - Verifies vocab lesson bulk editor controls stay within viewport on mobile layouts.
- `tests/e2e/specs/vocab-lesson-word-editor-mobile.spec.js`
  - Verifies the vocab lesson word editor keeps its save/cancel footer visible while the form body scrolls on mobile layouts.
- `tests/e2e/specs/vocab-lesson-deferred-grid.spec.js`
  - Verifies ordinary deferred lesson shells expose image-sized shimmer cards before hydration, enforce the AJAX timeout, hydrate legacy grids, and serially append prepared large-lesson pages without overlapping requests. Staff cards open the detached word editor only after word or recording edit actions, and hidden feedback stays hidden under theme overrides. PHP integration coverage verifies bounded public/staff order preparation, signed cursor pages, manual order, staff draft/hidden-card preservation, page boundaries, lightweight admin cards, and the initial large-count DOM ceiling.
- `tests/e2e/specs/vocab-lesson-prereq-editor.spec.js`
  - Verifies lesson-page prerequisite editing supports search, multi-select, deselect, and stable saved-state feedback on desktop and mobile layouts.
- `tests/e2e/specs/maintenance-doc-contracts.spec.js`
  - Verifies source/docs contracts that are cheap to check in the Playwright runner, including registered public shortcodes being documented in `README.md`, `CODEBASE_ARCHITECTURE.md` matching direct bootstrap include order, high-confidence hardcoded UI-string contexts using WordPress i18n wrappers, wordset-games public JS avoiding duplicated English `i18n` fallback strings, and Turkish PO high-risk glossary/tone checks.
- Known E2E coverage gaps still worth adding:
  - Prompt-card recorder remaining gaps are real browser microphone permission permutations and future data-contract changes. The local WordPress-backed queue fixture, limited-recorder real upload regression, self-contained prompt-card upload/advance regression, prompt-card quiz payload coverage, and lesson-grid browser coverage are already represented.
  - Real browser permission-prompt permutations and live hosted API behavior under real credentials/latency beyond the mocked Speaking Practice microphone-denial, record/transcribe/score, and hosted transcribe/score failure flows.
  - Offline app service-worker/install behavior if a browser PWA/service-worker runtime is added, plus broader hosted/offline deployment permutations beyond the local WordPress-backed conflict retry fixture.
- `tests/e2e/specs/wordset-pages-listening-launch.spec.js`
  - Verifies wordset page launch actions can open Listening mode with the
    expected category/wordset context, avoid the signed-in dashboard bulk-word
    fetch, preserve popup loading while category materializations warm, and use
    the paged payload envelope for no-candidate category hydration. Broad and
    progress-filtered logged-in selections use one bounded launch-plan request
    that either preserves every match across server-planned transport chunks or
    fails closed for an impossible sparse layout. They serially hydrate only the
    current chunk, then append the next verified chunk before results so the
    learner sees one logical practice session with a full progress denominator
    and no intermediate Continue/Next Set action. Bounded Learning keeps
    presentation- and aspect-compatible 8-15-word chunks within eight categories,
    tracks exact targets separately from compatible fillers without a full-category
    fallback, and advances later chunks through the results-screen Next action.
    Other bounded non-practice modes retain their explicit results-stage continuation. Direct specific-wrong-answer-only
    rows remain available as options without entering the target plan, while a
    prompt card's canonical answer remains targetable. Category queues are
    kept contiguous and owned queues are ordered largest-first. Runnable chunks
    then sort by fewest categories, fullest word count, and stable original order
    so the opening card needs the fewest serial category requests,
    and the selected progress filter remains visible across hydration boundaries. Verified candidate rows are handed
    directly to the flashcard runtime without a second AJAX fetch for the same
    chunk. Aggregate score/replay state survives each boundary and results plus
    mode-session completion occur once after the final chunk. A failed batch
    keeps the same index retryable; a simulated 429 must close every loading
    surface, issue no category requests, and show one retryable error.
- `tests/e2e/specs/wordset-games-space-shooter.spec.js`
  - Verifies the wordset games page bootstraps availability correctly, covers Line Up startup/retry/reorder/completion and Unscramble startup/keyboard tile reorder/completion, checks Word Stack layout/fall-speed regressions, verifies Speaking Practice's mocked mic record -> transcribe -> score path, microphone-denied retry state, and hosted transcribe/score failure retry states, and verifies Space Shooter/Bubble Pop runtime behavior and progress events.
- Additional specs in the same folder cover audio-recorder new-word flows, quiz audio gating, mobile/layout regressions, text fitting, wordset progress/loading shells, and more. Treat this section as a representative summary rather than a full inventory.

Optional env vars (set directly or in `tests/.env`):

```bash
LL_E2E_BASE_URL=https://starter-english-local.local
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
- The runner treats `POST /wp-admin/admin-ajax.php?action=ll_get_words_by_category`, `POST /wp-admin/admin-ajax.php?action=ll_get_flashcard_payload_page`, `POST /wp-admin/admin-ajax.php?action=ll_tools_wordset_page_lazy_cards`, `POST /wp-admin/admin-ajax.php?action=ll_tools_wordset_page_category_search`, `POST /wp-admin/admin-ajax.php?action=ll_tools_wordset_buttons_status`, and `POST /wp-admin/admin-ajax.php?action=ll_tools_get_vocab_lesson_grid` as allowed read-style public-page requests. It also allows exact-path infrastructure telemetry at `/cdn-cgi/rum`. Other same-origin non-GET requests still fail unless you explicitly allow exact paths with `network.allowedSameOriginNonGetPaths` or actions with `network.allowedAdminAjaxActions` in the site config.
- To verify a Cloudflare page rule/cache rule without changing the normal browser smoke flow, add an optional `cloudflareCache` array to a site entry. Each item can set `url`, anonymous request `headers`, `warmupRequests`, `expectServerIncludes`, and `expectCacheStatus`/`expectCacheStatuses`. For Zazacaogren, the useful pair is one default-language `/sozluk/` check expecting `HIT` and one Turkish `Accept-Language` check expecting `DYNAMIC`.

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
- The default-profile fixture is defined in `tests/performance/fixtures/performance-wordsets.json`; keep the wordset/category/word counts static and bump `fixtureVersion` when that file changes.
- The seeder reuses the existing fixture when the manifest version, checksum, expected counts, fixture tags, and key pages still match.
- The runner writes one JSONL record with plugin version, git commit, fixture version, throttle profile, medians, p95s, and comparison results.
- Named profiles (`xl`, `genc`, and `stress-2x`) authoritatively select their matching manifest, history, and report paths. `LL_PERF_SKIP_SEED=1` only reads and verifies the stored fixture option; it fails before Playwright when the fixture version or canonical checksum differs. The parent passes that small stored JSON to the PHP verifier as an explicit argument because redirected stdin is unreliable across WSL/Windows-PHP boundaries, then locks every exported `LL_E2E_PERF_*` value so child `.env` loading cannot change paths, run counts, history flags, completion limits, or budgets.
- Progress, settings-hub, and recorder-queue scenarios are authenticated, so keep `LL_E2E_ADMIN_USER` and `LL_E2E_ADMIN_PASS` set in `tests/.env.local`. A recorder-enabled manifest such as Genç fails instead of silently skipping those scenarios when credentials are absent.
- Focused recorder queue regression coverage must exercise limits after eligibility for canonical word/image, legacy missing-audio, and prompt-card sources. Sparse raw scans resume through an expiring signed cursor without repeating earlier prefixes or exposing raw candidate IDs to the client. Overview summary regressions must separately exhaust and total every applicable source while retaining only bounded previews; incomplete work remains a neutral shell and completed cards expose exact counts without `+`. Wordset-isolation regressions must prove the site-wide legacy option is not hydrated or used even when a title maps locally, while isolation-disabled coverage retains the bounded legacy fallback. Wordless multi-category regressions must prove sibling privacy/scope changes invalidate a completed target card and rebuild it to focused-hydrator parity. Cover canonical Base64URL enforcement (including same-byte padding-bit aliases), empty-but-continuable batches, cumulative same-page legacy/prompt results for nonincremental views, explicit page-one queue resets for invalid/expired/tampered/context-mismatched or pre-disable isolated tokens, and fail-closed token encoding without blank-cursor loops.

```bash
tests/bin/run-performance-benchmark.sh
```

- By default, history is appended to `tests/performance/history/performance-history.jsonl`.
- Latest JSON and Markdown summaries are written to
  `tests/performance/reports/performance-latest.*`.
- Summarize existing history without rerunning the benchmark:
  `node scripts/summarize-performance-history.js`.
- Set `LL_PERF_PROFILE=xl` to use the opt-in XL fixture (`60 x 50 = 3000`
  words), one run per scenario by default, and
  `tests/performance/history/performance-history-xl.jsonl` plus
  `tests/performance/reports/performance-latest-xl.*`.
- Set `LL_PERF_PROFILE=genc` for the production-shaped Genç fixture (`209 x 13
  = 2717` words, per-word images, and 8151 `word_audio` posts). It also seeds a
  fixture-only assigned recorder whose missing-question queue covers all 209
  categories, then measures authenticated settings-hub load, recorder-queue
  initial usability (at least three categories), and navigation-to-all-209-category
  lazy-summary completion separately. Completion is capped at the thirty-six
  requests required for one three-category request followed by six-category batches; ordinary
  category-content invalidation must not restart that structurally scoped stream.
  Search/progress/quiz interactions retain
  the normal 20-second cap; only recorder completion uses
  `LL_E2E_PERF_RECORDER_QUEUE_COMPLETION_MS` (120 seconds by default). Seed it once with
  `LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1`, then benchmark with
  `LL_PERF_SKIP_SEED=1`. Genç history and reports use the `*-genc` files under
  `tests/performance/history/` and `tests/performance/reports/`.
- Set `LL_PERF_PROFILE=stress-2x` for the full local stress fixture (`100 x 50 =
  5000` words, 15000 `word_audio` posts) with per-word image/audio posts
  sourced from the local Word Boat media pool when available. This profile writes to
  `tests/performance/history/performance-history-stress-2x.jsonl` and
  `tests/performance/reports/performance-latest-stress-2x.*`.
- For stress runs, seed separately first:
  `LL_PERF_PROFILE=stress-2x LL_PERF_FORCE_SEED=1 LL_PERF_SEED_ONLY=1 tests/bin/run-performance-benchmark.sh`.
  Then benchmark with `LL_PERF_SKIP_SEED=1`. On this Local stack, the first
  cold search run may need `LL_E2E_PERF_MAX_INTERACTION_MS=60000`; inspect
  `tests/performance/STRESS_2X_FINDINGS.md` before changing budgets.
- Set `LL_E2E_PERF_WRITE_HISTORY=0` for a dry verification run that does not modify the history log.
- Set `LL_E2E_PERF_COMPARE_HISTORY=0` to record metrics without failing on a historical comparison.
- Set `LL_PERF_FORCE_SEED=1` for a full fixture reset, or `LL_PERF_SEED_ONLY=1` when you only want to verify or refresh the fixture.
- Fixture manifest checksums are based on canonical parsed JSON, so CRLF/LF,
  indentation, and JSON object-key ordering do not split comparable history.
  Use `php tests/performance/verify-performance-manifest.php` for the lightweight
  checksum contract. In `--verify-stored` mode the verifier accepts stored JSON
  as its third argument (preferred by the runner) or from stdin for compatibility.
- Canonical checksum history may use one same-version/same-throttle legacy row
  as a migration baseline when no canonical row exists yet; after that, only a
  matching `canonical-json-v1` manifest checksum is comparable.

## Notes

- PHPUnit runs against a WordPress test database, not your production site DB.
- Playwright and performance fixtures target the configured Local site. They can mutate Local-site content; the performance seeder deletes only objects already carrying its fixture marker and refuses untagged slug collisions.
- Avoid running multiple PHPUnit commands in parallel against the same `wptests` database; InnoDB deadlocks can produce intermittent false failures.
- Keep all new tests under `tests/Integration/` and use translation-ready messages in assertions where relevant.
- `run-tests.sh` supports Linux PHP or Local Windows `php.exe` through `bin/php-local.sh` from WSL (`/mnt/c/...`) and Git Bash (`/c/...`); path conversion uses `wslpath` or `cygpath` respectively.
- `install-wp-tests.sh` writes `WP_PHP_BINARY` and `$table_prefix` into `wp-tests-config.php` for Local Windows PHP compatibility.
- Playwright failure artifacts default to `tests/e2e/test-results/` (relative to `tests/e2e/`), and the HTML report is in `tests/e2e/playwright-report/`.
- If `tests/.run-tests.lock` is left behind after an interrupted run, first confirm no PHPUnit wrapper is active, then remove that stale lock before rerunning.
- If needed, set `COMPOSER_PHAR` to a custom Composer PHAR path.
- If `run-tests.sh` fails with `Could not open input file .../tests/vendor/phpunit/phpunit/phpunit`, set an explicit Local PHP binary:
  - WSL: `PHP_BIN=/mnt/c/php/8.4/php.exe tests/bin/run-tests.sh`
  - Git Bash: `PHP_BIN=/c/php/8.4/php.exe tests/bin/run-tests.sh`
- Dictionary browser/import changes should always include `tests/bin/run-tests.sh Integration/DictionaryFeatureTest.php` before the full suite. Public query-shape/admission changes should also run `Integration/DictionaryPublicFilterBoundsTest.php`; dictionary admin-import UI changes should include `tests/bin/run-e2e.sh specs/admin-import-preview-undo.spec.js`.
- One PHPUnit import regression may be skipped on some machines:
  - `ExternalCsvBundleImportTest::test_import_decodes_windows_1255_csv_values_and_generates_quiz_page`
  - This depends on runtime `iconv` / `mbstring` support for reliably round-tripping the non-UTF Hebrew fixture encoding.
