# Maintenance Backlog

Updated May 29, 2026 after the weekly maintenance/performance audit and the
autonomous REST/docs, i18n, public-resource, and wordset-search follow-up
passes.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## Current Short List

The active maintenance list for the current round is intentionally narrowed to
browser regression coverage, flashcard-shell duplication, helper cleanup
decisions, and documentation upkeep.

1. Add browser/source-contract coverage for major feature areas that still have mostly PHP or manual coverage.
   - Content lessons in the mixed lesson grid now have PHP ordering coverage plus focused browser coverage for rendered order, content-card search, and category-only selection behavior. The remaining gap is a WordPress-backed browser fixture for real content lesson routes and media payloads.
   - Prompt-card recorder queue flows. Focused browser fixtures now cover prompt-card prompt-audio upload/advance behavior and a local WordPress-backed prompt-card queue item; the remaining gap is permissions plus real media upload. Prompt-card quiz payload and lesson-grid shells also have focused browser coverage; keep extending those specs when the data contract changes.
   - Teacher class assignment, invite, and progress-table flows. Teacher class
     creation now has frontend Playwright coverage for a teacher-role user,
     including the limited-role `admin-post.php` path and selected-class
     redirect.
   - Offline app shell launcher and sync-panel wiring now have self-contained
     browser coverage; remaining offline gaps are service-worker/install
     behavior and real remote snapshot sync edge cases.
   - Less-covered games: Line Up now has browser startup, retry, reorder, progress-event, and completion coverage; Unscramble has startup coverage into its dedicated tile stage; Speaking Stack has focused browser coverage for stack placement and pre-attempt fall speed. Remaining game gaps are deeper Unscramble interactions/completion plus Speaking Practice recording/API behavior.
   - The Site Tools frontend now has Playwright coverage for admin form wiring,
     recording-type controls, managed-page controls, maintenance action wiring,
     a safe cache-flush submit path, and mobile overflow.
   - A lightweight Playwright source-contract spec now checks that registered
     public shortcodes stay listed in `README.md` and that high-confidence
     user-facing PHP/JS string contexts use translation-ready wrappers. Keep
     extending this kind of static coverage where it catches real maintenance
     drift with low flake risk.

2. Reduce duplicated flashcard shell markup and startup behavior.
   - The public flashcard template, offline app shell, and quiz-page shell share many IDs and controls but still duplicate markup and repeat-button initialization.
   - Prefer a shared PHP renderer or small partials before adding more shell controls.

3. Keep the site-sync snapshot policy unchanged unless live usage shows pressure.
   - `GET /wordsets/{wordset}/site-sync/snapshot` intentionally continues to permit an unpaged snapshot when `per_page` is omitted or `0`; `include_media` defaults to true.
   - Automation users may depend on full snapshots, so treat any future cap/default change as a deliberate compatibility decision.
   - If production usage ever shows resource pressure, verify any behavior change against `docs/REST_AUTOMATION.md`, local REST tests, and at least one controlled staging sync workflow before deployment.

4. Keep the audited helper decisions explicit.
   - `ll_tools_dictionary_get_scope_filter_index()` is currently an internal/cache-validation helper covered by tests; keep it until dictionary filters render from a precomputed index or remove it together with the cache-validation test.
   - The global `get_deepl_language_codes()` helper in `includes/admin/api/deepl-api.php` is a legacy supported-language-map helper, not a duplicate of the wordset source/target resolver `ll_tools_get_deepl_language_codes()`. Keep it for compatibility unless a future external-usage audit proves it can be deprecated.

5. Keep architecture and operator docs current after large feature work.
   - `CODEBASE_ARCHITECTURE.md` now includes the newer cache, automation, offline, prompt-audio, teacher-class, and dictionary-source modules, but it should be refreshed whenever another large workflow lands.
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

3. Move main wordset category search indexing off the first render path.
   - The wordset main page currently builds and localizes the full category
     word-search index via `ll_tools_get_wordset_page_category_search_index()`
     in `includes/pages/wordset-pages.php`.
   - The May 29 maintenance pass narrowed the SQL to words that have at least
     one allowed category, which reduces wasted scans without changing the
     client-side search contract.
   - That preserves instant client-side category search, including matches on
     word titles/translations and diacritic-insensitive text, but first render
     can still scan many published words for large allowed category sets.
   - Treat this as a larger performance project because the replacement should
     be an on-demand cached AJAX/REST lookup that loads when search is focused
     or typed, returns matching category IDs without forcing lazy-card loads,
     and preserves hidden-selection cleanup, empty-state behavior, clear-button
     behavior, and diacritic-insensitive matching.
   - Reuse and extend the existing regressions around
     `wordset-page-category-search.spec.js`, `wordset-page-lazy-loading.spec.js`,
     and `wordset-page-speed-large-wordset.spec.js` before changing the search
     data contract.

4. Continue the tier-2 public UI translation rollout deliberately.
   - Run a QA pass over `languages/tier2-public-ui-strings.json` to reduce the
     public string set by removing unnecessary copy, replacing text with icons
     where appropriate, and reusing existing strings where the context allows.
   - Audit whether a tier-2 learner locale can be used on public surfaces while
     elevated manager/admin UI falls back to a core full locale such as English.
   - Add Russian (`ru_RU`) as the first tier-2 public UI PO file, then run the
     manifest coverage checker and targeted browser smoke checks against public
     pages.
   - Expand `[ll_language_switcher]` display modes for larger tier-2 language
     sets, including compact icon/button triggers and a dropdown or modal list.

## Lower Priority

1. Audio/Image Matcher scalability.
   - Current categories are usually small, so pagination or lazy loading for the matcher is deferred.
   - Relevant files: `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`.
