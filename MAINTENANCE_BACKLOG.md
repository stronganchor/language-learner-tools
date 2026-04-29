# Maintenance Backlog

Updated April 29, 2026 after the security, i18n, and UI maintenance passes.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## Highest Impact

1. Investigate the remaining full Playwright failures.
   - `tests/e2e/specs/admin-import-preview-undo.spec.js` timed out after the undo flow while the page showed an `Undo complete` state in the last full-suite run. Re-run in isolation first, then decide whether the bug is the importer state transition or a too-broad assertion.
   - `tests/e2e/specs/page-speed-throttled-load.spec.js` timed out on the warm `/learn/` GET in the last full-suite run. Treat this as a performance investigation, not just a timeout bump.

2. Add browser coverage for major feature areas that still have mostly PHP or manual coverage.
   - Content lessons in the mixed lesson grid, including prerequisite ordering.
   - Prompt-card quiz payloads, prompt-card lesson grids, and recorder queue flows.
   - Teacher class creation, assignment, progress views, and limited-role `admin-post.php` redirects.
   - Offline app shell launch/sync behavior.
   - Site Tools frontend maintenance actions.
   - Less-covered games: Line Up, Unscramble, Speaking Practice, and Speaking Stack.
   - A lightweight i18n static scan that fails on new hardcoded user-facing PHP or JS strings.

3. Split the largest modules along existing ownership boundaries.
   - `includes/pages/wordset-pages.php` (roughly 15k lines): routing, teacher classes, settings, render helpers, analytics payloads, games launch, and mixed-grid rendering are packed together.
   - `includes/admin/export-import.php` (roughly 15k lines): import preview, undo, export, offline-ish payload work, and admin rendering should become smaller services/controllers.
   - `includes/shortcodes/word-grid-shortcode.php` (roughly 9k lines): rendering, inline edit handling, media selection, REST/AJAX helpers, and lesson-grid behavior need clearer boundaries.
   - `includes/shortcodes/audio-recording-shortcode.php` (roughly 7k lines): recorder UI, queue construction, upload handling, prompt-card handling, and translation helpers should be separated.
   - `js/wordset-pages.js` and `js/wordset-games.js` are both large enough to make targeted game/page work riskier than it needs to be.

4. Reduce duplicated flashcard shell markup and startup behavior.
   - The public flashcard template, offline app shell, and quiz-page shell share many IDs and controls but still duplicate markup and repeat-button initialization.
   - Prefer a shared PHP renderer or small partials before adding more shell controls.

5. Rework wordset and vocab-lesson rewrite registration for scale.
   - `includes/pages/wordset-pages.php` and `includes/pages/vocab-lesson-pages.php` register per-wordset rewrite rules on `init`.
   - This is easy to reason about on small local data, but it should be replaced or cached before large live wordset counts make every request pay for route setup.

6. Audit production-unused or test-only helpers before deleting them.
   - `ll_tools_dictionary_get_scope_filter_index()` currently appears to be exercised by tests and may only be needed as a cache-validation helper.
   - The global `get_deepl_language_codes()` helper in `includes/admin/api/deepl-api.php` appears unused by production code, while `ll_tools_get_deepl_language_codes()` is the active wordset-aware helper.
   - Confirm no external integrations call either before removal or deprecation.

## Lower Priority

1. Audio/Image Matcher scalability.
   - Current categories are usually small, so pagination or lazy loading for the matcher is deferred.
   - Relevant files: `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`.

2. Keep architecture docs current after large feature work.
   - `CODEBASE_ARCHITECTURE.md` now includes the newer cache, automation, offline, prompt-audio, teacher-class, and dictionary-source modules, but it should be refreshed whenever another large workflow lands.
