# Maintenance Backlog

Updated June 10, 2026 after the weekly maintenance/performance audit, the
first public lazy-card resource-protection follow-up pass, the E2E
runner-health follow-up, the Speaking Practice E2E follow-up, and the shared
flashcard shell follow-up, and the content lesson route/media E2E follow-up.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## Current Short List

The active maintenance list for the current round is intentionally narrowed to
browser regression coverage, helper cleanup decisions, and documentation upkeep.

## Recently Closed

- June 10 content lesson route/media E2E follow-up:
  `tests/e2e/specs/content-lesson-route-media.spec.js` now uses a marked
  WP-CLI fixture to seed a real `ll_content_lesson` route with audio media,
  parsed transcript cues, post notes, and a related vocab lesson link. This
  closes the main WordPress-backed browser gap for content lesson route and
  media payload rendering while leaving real uploaded media playback and
  corpus-text route variants as deliberate future coverage.
- June 10 flashcard shell duplication follow-up: added
  `includes/flashcard-shell.php` as the shared renderer for the flashcard
  overlay popup, mode switcher, results controls, and guarded repeat-button
  initializer. The public flashcard template, offline app shell, and
  quiz-page/vocab-lesson popup bootstrap now call that renderer instead of
  carrying separate copies of the same ID-sensitive shell.
- June 10 E2E admin REST helper follow-up: `tests/e2e/helpers/admin.js` now
  wraps in-page WordPress REST fixture calls with a hard Promise timeout, so
  temporary admin fixture setup can fail fast and fall back instead of consuming
  the full Playwright test timeout.
- June 10 Speaking Practice browser coverage follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now includes a
  self-contained mocked microphone/MediaRecorder/AudioContext path that launches
  Speaking Practice, records an attempt, posts the transcribe and score AJAX
  actions, renders the scored result, and queues the expected progress outcome.
- June 10 architecture documentation drift follow-up: `CODEBASE_ARCHITECTURE.md`
  now has a direct bootstrap include index that mirrors plugin-owned
  `includes/bootstrap.php` `require_once` paths in load order, and the
  maintenance source-contract spec now fails if that index drifts from the
  loaded module list.

- June 10 Turkish localization quality follow-up: normalized the Turkish
  `part of speech` glossary cluster from `Sözcük Türü`/`sözcük türü` to
  `Konuşma Parçası`/`konuşma parçası`, rebuilt the compiled Turkish locale
  files, added the glossary term to the Turkish review guide, and added a
  maintenance source-contract check for high-risk Turkish tone/glossary drift.
- June 10 public JS localization fallback follow-up: `js/wordset-games.js`
  now reads game UI labels, status text, alerts, and ARIA copy through localized
  `llWordsetPageData.i18n` helpers instead of duplicating English fallbacks in
  the public runtime. The wordset-games Playwright fixture now supplies the full
  game i18n payload, and the maintenance source-contract spec guards against
  reintroducing English `ctx.i18n`/`cfg.i18n` fallback strings in the games JS.
- June 10 Word Images admin category-count follow-up: the
  `word_images` list-table category filter now computes all visible image
  counts with one aggregate taxonomy/post query instead of running one
  `WP_Query` per category. Focused PHPUnit coverage verifies visible admin
  status semantics, empty categories, selected-option rendering, and the
  single-query shape.
- June 10 full local E2E timeout investigation: `bash tests/bin/run-e2e.sh --list`
  found 314 Playwright tests, and all four local shards completed successfully
  with 313 passed and 1 skipped. No hung spec was isolated. Treat the
  unsharded local E2E command as a long serial suite in automation; use shards
  or a timeout of at least 35 minutes before declaring a runner hang.
- June 10 wordset category search indexing follow-up: the main wordset render
  path now localizes a tokenized `categorySearch` config instead of the full
  per-category word-search text. The first word/translation search request uses
  a cached public AJAX lookup that returns matching category IDs, preserving
  hidden-selection cleanup, empty-state behavior, clear-button behavior,
  lazy-card hydration, and diacritic-insensitive matching.

1. Add browser/source-contract coverage for major feature areas that still have mostly PHP or manual coverage.
   - Content lessons in the mixed lesson grid now have PHP ordering coverage plus focused browser coverage for rendered order, content-card search, category-only selection behavior, and a WordPress-backed real route/media/cue/related-vocab fixture. Remaining content-lesson gaps are real uploaded media playback and corpus-text route variants.
   - Prompt-card recorder queue flows. Focused browser fixtures now cover prompt-card prompt-audio upload/advance behavior and a local WordPress-backed prompt-card queue item; the remaining gap is permissions plus real media upload. Prompt-card quiz payload and lesson-grid shells also have focused browser coverage; keep extending those specs when the data contract changes.
   - Teacher class assignment, invite, and progress-table flows. Teacher class
     creation now has frontend Playwright coverage for a teacher-role user,
     including the limited-role `admin-post.php` path and selected-class
     redirect.
   - Offline app shell launcher and sync-panel wiring now have self-contained
     browser coverage; remaining offline gaps are service-worker/install
     behavior and real remote snapshot sync edge cases.
   - Less-covered games: Line Up now has browser startup, retry, reorder, progress-event, and completion coverage; Unscramble now has keyboard tile-reorder, progress-event, and completion coverage; Speaking Stack has focused browser coverage for stack placement and pre-attempt fall speed; Speaking Practice now has mocked browser coverage for record -> transcribe -> score UI and progress behavior. Remaining live game gaps are real browser/media permission variations and hosted API error edges.
   - The Site Tools frontend now has Playwright coverage for admin form wiring,
     recording-type controls, managed-page controls, maintenance action wiring,
     a safe cache-flush submit path, and mobile overflow.
   - A lightweight Playwright source-contract spec now checks that registered
     public shortcodes stay listed in `README.md`, high-confidence user-facing
     PHP/JS string contexts use translation-ready wrappers, wordset game
     UI copy does not duplicate English `i18n` fallbacks in public JS, and the
     Turkish PO avoids high-risk glossary/tone regressions. It also guards the
     architecture guide's direct bootstrap include index against load-map drift.
     Keep extending this kind of static coverage where it catches real
     maintenance drift with low flake risk.

2. Keep the site-sync snapshot policy unchanged unless live usage shows pressure.
   - `GET /wordsets/{wordset}/site-sync/snapshot` intentionally continues to permit an unpaged snapshot when `per_page` is omitted or `0`; `include_media` defaults to true.
   - Automation users may depend on full snapshots, so treat any future cap/default change as a deliberate compatibility decision.
   - If production usage ever shows resource pressure, verify any behavior change against `docs/REST_AUTOMATION.md`, local REST tests, and at least one controlled staging sync workflow before deployment.

3. Keep the audited helper decisions explicit.
   - `ll_tools_dictionary_get_scope_filter_index()` is currently an internal/cache-validation helper covered by tests; keep it until dictionary filters render from a precomputed index or remove it together with the cache-validation test.
   - The global `get_deepl_language_codes()` helper in `includes/admin/api/deepl-api.php` is a legacy supported-language-map helper, not a duplicate of the wordset source/target resolver `ll_tools_get_deepl_language_codes()`. Keep it for compatibility unless a future external-usage audit proves it can be deprecated.

4. Keep architecture and operator docs current after large feature work.
   - `CODEBASE_ARCHITECTURE.md` now includes the newer cache, automation, offline, prompt-audio, teacher-class, and dictionary-source modules, plus a source-contract-guarded direct bootstrap include index. Keep refreshing narrative flow docs whenever another large workflow lands.
   - `README.md` shortcode coverage is now guarded by a Playwright source-contract regression; update the README and the test together when intentionally adding or removing public shortcodes.

## Deferred Larger Projects

1. Plan scalable wordset and vocab-lesson routing as its own migration project.
   - Current root-level pretty routes are registered per enabled wordset in
     `includes/pages/wordset-pages.php` and
     `includes/pages/vocab-lesson-pages.php`.
   - This is acceptable at the current scale, but it should be replaced or
     supplemented before a large live site has many dozens of wordsets.
   - Treat this as a deliberate compatibility migration, not an opportunistic
     cleanup. Existing saved URLs and bookmarks must keep working or redirect
     cleanly.
   - The embed route is a hard compatibility constraint:
     `/embed/<category>?wordset=<slug>&mode=practice|learning|listening|gender|self-check`
     must keep working for Word Boat embedded quizzes.
   - Add regression tests for root wordset URLs, vocab lesson URLs, canonical
     query URLs, and embedded Biblical Hebrew quiz URLs before changing the
     route shape.
   - A likely target shape is a small fixed set of prefixed routes such as
     `/learn/<wordset>/...`, with old root pretty URLs preserved through narrow
     redirects.

2. Split the largest modules along existing ownership boundaries.
   - `includes/pages/wordset-pages.php` (roughly 15k lines): routing, teacher classes, settings, render helpers, analytics payloads, games launch, and mixed-grid rendering are packed together.
   - `includes/admin/export-import.php` (roughly 15k lines): import preview, undo, export, offline-ish payload work, and admin rendering should become smaller services/controllers.
   - `includes/shortcodes/word-grid-shortcode.php` (roughly 9k lines): rendering, inline edit handling, media selection, REST/AJAX helpers, and lesson-grid behavior need clearer boundaries.
   - `includes/shortcodes/audio-recording-shortcode.php` (roughly 7k lines): recorder UI, queue construction, upload handling, prompt-card handling, and translation helpers should be separated.
   - `js/wordset-pages.js` and `js/wordset-games.js` are both large enough to make targeted game/page work riskier than it needs to be.

3. Continue the tier-2 public UI translation rollout deliberately.
   - Run a QA pass over `languages/tier2-public-ui-strings.json` to reduce the
     public string set by removing unnecessary copy, replacing text with icons
     where appropriate, and reusing existing strings where the context allows.
   - Audit whether a tier-2 learner locale can be used on public surfaces while
     elevated manager/admin UI falls back to a core full locale such as English.
   - Active tier-2 public UI PO files now cover Russian (`ru_RU`), Hindi
     (`hi_IN`), Spanish (`es_ES`), French (`fr_FR`), Portuguese Brazil
     (`pt_BR`), Indonesian (`id_ID`), Korean (`ko_KR`), and Italian (`it_IT`).
     Keep using the manifest coverage checker and targeted browser smoke checks
     when changing public locale coverage.
   - For tier-2 public locales, release readiness is based on the public UI
     manifest, not raw full-plugin PO absence counts. A locale can have complete
     learner/visitor coverage while still omitting admin-only or manager-only
     strings outside `tier2-public-ui-sources.php`.
   - Planned tier-2 locales without PO files remain Chinese Simplified
     (`zh_CN`), Arabic (`ar`), and Bengali (`bn_BD`). Add them deliberately from
     the manifest workflow rather than treating their missing files as ordinary
     active-locale regressions.
   - Expand `[ll_language_switcher]` display modes for larger tier-2 language
     sets, including compact icon/button triggers and a dropdown or modal list.

5. Formalize a generic bulk-operation job framework.
   - Existing REST automation works well as a control plane for reports, dry
     runs, bounded writes, and import job polling, while WP-CLI works well for
     trusted server-side maintenance.
   - The next step is a reusable job pattern for heavy live operations where
     Codex may only have WordPress credentials but the work should still run in
     durable server-owned chunks.
   - A generic framework should record operation name, scope, caller/lease
     context, input manifest hash or idempotency key, cursor, progress counts,
     recent errors, per-row results, and final readback hints.
   - Use it for future workflows that touch hundreds of rows and perform
     expensive validation, media handling, taxonomy repair, cache rebuilding, or
     cross-post recomputation instead of adding one long synchronous REST
     request per operation.

## Lower Priority

1. Audio/Image Matcher scalability.
   - Current categories are usually small, so pagination or lazy loading for the matcher is deferred.
   - Relevant files: `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`.
