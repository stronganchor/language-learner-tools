# Maintenance Backlog

Updated May 1, 2026 after the security, i18n, UI, routing-maintenance, and
follow-up maintenance passes.

This file is for worthwhile work that should be planned deliberately instead of
being folded into a small opportunistic fix.

## Current Short List

The active maintenance list for the current round is intentionally narrowed to
browser regression coverage, flashcard-shell duplication, helper cleanup
decisions, and documentation upkeep.

1. Add browser/source-contract coverage for major feature areas that still have mostly PHP or manual coverage.
   - Content lessons in the mixed lesson grid, including prerequisite ordering.
   - Prompt-card quiz payloads, prompt-card lesson grids, and recorder queue flows.
   - Teacher class assignment, invite, and progress-table flows. Teacher class
     creation now has frontend Playwright coverage for a teacher-role user,
     including the limited-role `admin-post.php` path and selected-class
     redirect.
   - Offline app shell launch/sync behavior.
   - Less-covered games: Line Up, Unscramble, Speaking Practice, and Speaking Stack.
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

3. Keep the audited helper decisions explicit.
   - `ll_tools_dictionary_get_scope_filter_index()` is currently an internal/cache-validation helper covered by tests; keep it until dictionary filters render from a precomputed index or remove it together with the cache-validation test.
   - The global `get_deepl_language_codes()` helper in `includes/admin/api/deepl-api.php` is a legacy supported-language-map helper, not a duplicate of the wordset source/target resolver `ll_tools_get_deepl_language_codes()`. Keep it for compatibility unless a future external-usage audit proves it can be deprecated.

4. Keep architecture and operator docs current after large feature work.
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

## Lower Priority

1. Audio/Image Matcher scalability.
   - Current categories are usually small, so pagination or lazy loading for the matcher is deferred.
   - Relevant files: `includes/admin/audio-image-matcher.php`, `js/audio-image-matcher.js`.
