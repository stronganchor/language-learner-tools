# Maintenance Backlog

Updated June 12, 2026 after the weekly maintenance/performance audit, the
first public lazy-card resource-protection follow-up pass, the E2E
runner-health follow-up, the Speaking Practice E2E follow-up, the shared
flashcard shell follow-up, the content lesson route/media E2E follow-up, and
the prompt-card recorder real-upload E2E follow-up, the teacher-class coverage
verification follow-up, the Speaking Practice microphone-denial follow-up, and
the Speaking Practice hosted API failure follow-up, and the offline remote
snapshot follow-up, the offline sync error-UX follow-up, the June 12
large-wordset performance/regression follow-up pass, the June 19 public
flashcard AJAX cache-stampede follow-up, and the June 19 WebP optimizer queue
resource-guard follow-up.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## Current Short List

The active maintenance list for the current round is intentionally narrowed to
browser regression coverage, helper cleanup decisions, and documentation upkeep.

## Recently Closed

- June 19 WebP optimizer queue resource-guard follow-up:
  the admin WebP optimizer queue no longer requests all `word_images` IDs in a
  single unbounded query on every page load. Queue indexing now scans in bounded
  chunks, stores only compact sortable rows, reuses a short-lived invalidated
  index for follow-up pages and bulk ID fetches, and hydrates only the visible
  page rows for card rendering. `ImageWebpOptimizerAdminTest` covers the
  bounded query shape and compact-index reuse.
- June 19 public flashcard AJAX cache-stampede follow-up:
  anonymous cold misses for `ll_get_words_by_category` now acquire a short
  build lock keyed to the normalized public cache arguments. Duplicate misses
  get a retryable `cache_warming`/429 response using existing localized retry
  copy while the first request warms the cache. `SecurityHardeningRegressionTest`
  covers the server guard, and `flashcard-loader-wordset-isolation.spec.js`
  covers the client retry branch.
- June 10 offline sync error-UX follow-up:
  `tests/e2e/specs/offline-app-shell-launcher.spec.js` now covers offline sync
  login failure and server sync failure responses in the real offline shell
  wiring. Failed logins keep the sign-in sheet open and re-enable submit
  without starting sync; failed server sync keeps the user connected, leaves
  pending local progress visible, and exposes the failure feedback so progress
  is not silently discarded.
- June 10 offline remote snapshot follow-up:
  `offline-app/offline-app.js` now mirrors remote `userStudyState` starred
  words, star mode, and fast-transition preferences into the top-level
  flashcard fields consumed by offline launches. `tests/e2e/specs/offline-app-shell-launcher.spec.js`
  now covers a remote sync snapshot updating selected categories, progress sort
  metrics, next recommendation UI, session-word launch plans, and synced study
  preferences.
- June 10 Speaking Practice hosted API failure follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now covers hosted
  transcribe and score failures in the real Speaking Practice popup flow. The
  runtime returns to retry state, keeps the result panel hidden, avoids progress
  outcome writes, does not call score after transcribe failure, and resolves raw
  upstream/provider messages to the localized retry message.
- June 10 Speaking Practice microphone-denial follow-up:
  `tests/e2e/specs/wordset-games-space-shooter.spec.js` now covers a hosted
  Speaking Practice launch where `getUserMedia()` rejects with a browser
  permission error. The runtime shows the localized microphone failure message,
  returns the record control to retry state, avoids transcription/scoring POSTs,
  and no longer surfaces raw browser permission text.
- June 10 teacher-class coverage verification follow-up:
  `tests/e2e/specs/teacher-classes-frontend.spec.js` passed locally and already
  covers the frontend teacher-role create/delete path, signup invite
  registration, admin assignment of an existing learner, class progress-table
  sorting, and learner removal. This closes the stale backlog wording that
  listed assignment, invite, and progress-table flows as still uncovered.
- June 10 prompt-card recorder real-upload E2E follow-up:
  `tests/e2e/specs/audio-recorder-prompt-card-upload.spec.js` now seeds a
  marked local WordPress fixture, logs in as the limited `audio_recorder` role,
  verifies inaccessible prompt cards are rejected by the real AJAX handler, and
  posts a valid WAV multipart upload that becomes the prompt card's prompt-audio
  attachment. This closes the main permission plus real-media-upload browser
  gap for prompt-card recorder queues while leaving real browser microphone
  permission permutations as future coverage.
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
   - Prompt-card recorder queue flows. Focused browser fixtures now cover prompt-card prompt-audio upload/advance behavior, a local WordPress-backed prompt-card queue item, and a limited-recorder real multipart prompt-audio upload with an inaccessible-card rejection check. Remaining prompt-card recorder gaps are real browser microphone permission permutations and future data-contract changes. Prompt-card quiz payload and lesson-grid shells also have focused browser coverage; keep extending those specs when the data contract changes.
   - Teacher class flows now have frontend Playwright coverage for a
     teacher-role user creating/deleting a class, signup invite registration,
     admin assignment of an existing learner, progress-table sorting, learner
     removal, and the limited-role `admin-post.php` selected-class redirect.
   - Offline app shell launcher and sync-panel wiring now have self-contained
     browser coverage for launcher selection/sort/launch, sync-panel sign-in,
     login failure, manual sync/disconnect, failed server sync feedback, and
     remote snapshot application to selected categories, progress sorting,
     recommendations, and study preferences. Remaining offline gaps are
     service-worker/install behavior and WordPress-backed server sync
     conflict/error fixtures beyond the local mocked browser shell.
   - Less-covered games: Line Up now has browser startup, retry, reorder, progress-event, and completion coverage; Unscramble now has keyboard tile-reorder, progress-event, and completion coverage; Speaking Stack has focused browser coverage for stack placement and pre-attempt fall speed; Speaking Practice now has mocked browser coverage for record -> transcribe -> score UI and progress behavior, microphone-denied retry state, and hosted transcribe/score failure retry states. Remaining live game gaps are real browser permission-prompt variations and live hosted API behavior under real credentials/latency.
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

## Follow-Up Notes

- Offline app service-worker/install behavior is still a future coverage item
  only if a browser PWA/service-worker runtime is added; the current offline app
  path is a local-first web/APK shell and does not register a service worker.
- Offline sync still deserves a WordPress-backed browser fixture for
  admin-ajax conflict/error responses if the server contract gains explicit
  merge-conflict semantics. Current coverage includes PHP endpoint throttling,
  payload caps, token errors, local browser error UX, and remote snapshot
  application.
- Live hosted API checks for Speaking Practice should remain behind explicit
  approval and credentials; local mocked coverage now covers success, mic
  denial, transcribe failure, and score failure UI states.
- Real browser permission-prompt permutations are best handled as targeted
  manual/staged checks because Chromium automation can reliably fake device
  errors but not every browser prompt/state combination users see in Chrome and
  OS-level privacy settings.
- Wordset editor IPA keyboard metadata needs a larger cached-data design if
  editors expect off-page IPA symbols and letter aids while browsing a paged
  large wordset. The current modal path keeps `word_grid` rendering bounded by
  visible `specific_word_ids`, so its IPA symbol inventory can omit symbols
  that only appear on other pages. Do not fix this by restoring a synchronous
  full-wordset `word_audio` scan in the editor modal. Prefer a materialized
  wordset-level IPA inventory/letter-map cache, maintained by bounded
  recording-save hooks or an explicit admin rebuild job, or lazy-load keyboard
  metadata asynchronously when the editor opens. Existing wordset term meta
  such as `ll_wordset_ipa_special_chars` and `ll_wordset_ipa_letter_map` can
  be part of the read path, but only after invalidation/rebuild behavior is
  clear enough for large wordsets.

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
