---
title: Language Learner Tools - Codebase Architecture (AI Guide)
entry_points:
  - language-learner-tools.php
  - includes/bootstrap.php
read_first:
  - language-learner-tools.php
  - includes/bootstrap.php
  - includes/template-loader.php
  - includes/assets.php
  - includes/lib/flashcard-payload-materializer.php
  - includes/lib/word-option-rules.php
  - includes/lib/word-grid-bulk-operations.php
  - includes/api/automation-rest.php
  - includes/api/word-metadata-plan-rest.php
  - includes/lib/site-sync.php
  - includes/pages/quiz-pages.php
  - includes/pages/embed-page.php
  - includes/pages/default-shortcode-page-helper.php
  - includes/pages/recording-page.php
  - includes/pages/editor-hub-page.php
  - includes/shortcodes/flashcard-widget.php
  - includes/shortcodes/editor-hub-shortcode.php
  - includes/shortcodes/audio-recording-shortcode.php
  - includes/user-study.php
  - includes/user-progress.php
  - includes/taxonomies/word-category-taxonomy.php
  - includes/taxonomies/wordset-taxonomy.php
  - includes/post-types/words-post-type.php
  - includes/post-types/dictionary-entry-post-type.php
  - includes/post-types/word-audio-post-type.php
  - includes/admin/settings.php
  - includes/admin/audio-processor-admin.php
  - includes/admin/site-sync-admin.php
  - js/flashcard-widget/main.js
  - js/flashcard-widget/selection.js
  - js/flashcard-widget/modes/listening.js
---

# Overview (30-second tour)
- WordPress plugin for vocabulary-driven language learning.
- Custom post types for words, dictionary entries, word images, word audio recordings, vocab lessons, content lessons, prompt cards, and teacher classes.
- Taxonomies for word categories, word sets, language, part of speech, and recording types.
- `word-category` is a flat taxonomy in LL Tools behavior. Existing database parent values may exist, but quiz, lesson, editor, import/export, and sync code should ignore parent/child relationships and treat each category term as its own exact entity.
- Flashcard quizzes with multiple modes (practice, learning, listening, gender, self-check) and per-category prompt/option config.
- Auto quiz pages under `/quiz/<category>` are generated `ll_quiz_page` records, not normal WordPress Pages; embeddable pages under `/embed/<category>`, wordset hub routes, vocab lesson routes, content lessons, games, and teacher class views.
- Audio workflow: recording interface, bulk uploader, processing/review, recording type management.
- Admin tools for bulk translation, bulk word import, export/import, site sync, and legacy cleanups.
- Dictionary tooling: TSV import into `ll_dictionary_entry`, grouped sense metadata, snapshot import/export, and a public `[ll_dictionary]` browse/search page.
- Template override system and GitHub update checker (`main` stable via release asset zip, `dev` via branch for testing).

# Production scale invariant
- Large wordsets are normal production data. A single wordset may have thousands of `words` posts and many thousands of `word_audio`, `word_images`, prompt cards, and generated media records.
- Interactive UI, AJAX, shortcode, and game-launch paths should stay bounded by the page size or launch-candidate size. Do not hydrate or iterate a whole wordset's posts just to render a catalog card, count availability, or launch a game.
- Prefer ID-only queries, capped launch chunks, pagination, cached/materialized aggregates, and explicit admin batch jobs with progress behavior for operations that intentionally need the whole wordset.
- `[ll_ranked_word_list]` is the bounded reference-table path for large ordered collections: query one exact category page (100 words maximum), require the allowlisted numeric `_ll_tools_word_rank` meta, use word ID as the stable tie-breaker, and bulk-collect audio only for that page. Do not replace it with an unbounded `[word_grid]` render.
- `[ll_content_lesson_index]` is the bounded catalog for article and standard content lessons: scope every query to one visible wordset, optionally match exact repeated legacy category-ID meta, fetch no more than 100 lessons plus one continuation row, and cap numeric pagination at 100 pages. The legacy `[display_prereq_tree]` shim delegates to this surface rather than restoring an all-post prerequisite scan.
- Derived quiz/content cache invalidation separates structure from content. Category identity/order mutations advance the structural epoch; fully resolved word, audio, image, and prompt mutations advance affected category versions and known wordset content epochs. Incomplete scope must advance the unknown component, and any failed narrow epoch write must advance the failsafe so a committed mutation cannot leave an old generation addressable. Read and increment epochs through the direct atomic helpers; do not trust stale persistent-object-cache values.
- A database error is not an empty catalog, zero count, or exhausted page. Cacheable builders and maintenance/page cursors must propagate source completeness through term, meta, visibility, default-wordset, sign-mode, prompt, media, and owner-map reads. Publish or advance only when every source is complete; otherwise retain the prior durable generation and retry the same key or cursor.
- A cold flashcard category is a durable materialization, not an interactive full-category hydration. `includes/lib/flashcard-payload-materializer.php` advances bounded primary-word and prompt-card keyset batches into generation-scoped rows; readers expose only an unlocked, complete generation whose dependency signature still matches. The public/signed-in page endpoints must return retryable preparation state until then, never partial quiz data disguised as a complete category.
- Main wordset category rows must discard categories already proven ineligible before building content summaries, bulk-prime the exact candidate term, lesson, and wrong-answer-owner sets, and trust the exact membership established by flat `word-category` queries instead of re-querying each candidate word. Preserve the bounded initial-card/lazy-card split.
- The browser still needs the complete category registry for selection, sorting, search placeholders, and launch configuration, but that registry must be sparse: omit values supplied by the JavaScript normalizer or top-level wordset context. Lazy category shells are ordered `{type, id}` references into that registry; do not duplicate category rows in the shell list. Content-lesson shells retain their bounded title/excerpt/media metadata because unloaded-content search uses it.
- Logged-in broad or filtered multi-category wordset launches use `ll_user_study_selection_launch_plan`: resolve exact progress criteria against the cached ID-only category aggregate and, on success, preserve every matching word ID in balanced, category-aware transport chunks. Each chunk remains capped at 30 word IDs and eight categories (15 and three preferred by default). A sparse match set that cannot be partitioned into five-word rounds even at the eight-category hard cap must return the typed fail-closed planning error instead of an incomplete plan. The browser hydrates media only for the current chunk, verifies that candidate hydration covers the planned IDs, and hands those verified rows directly to the flashcard runtime. Practice treats those chunks as one logical session: it requests and appends the next chunk serially before results, preserves cumulative score/progress/replay state, and emits results plus mode completion only after the final chunk. Other bounded modes retain their explicit results-stage Next action. The runtime must not issue a second AJAX fetch for the same chunk. A 429 or other launch-plan/candidate failure must close the loader and expose a retry for the unchanged pending chunk; never hydrate every selected category at once, resume the former full-category payload drain, or start a long foreground warming loop.
- Metric-dependent saved main-category sorts (`progress-*` and `recent-*`) must not trigger full-wordset metric hydration while analytics is deferred. Keep the bounded initial cards and lazy offsets in canonical/default order, preserve the saved sort key for the browser, and reorder only after the summary-only per-category analytics aggregate arrives.
- Settings routes should enqueue only the runtime they use: plain settings tools and the hub skip the main wordset-page monolith and locale sorter; `study`, `editor`, and `recorder-queues` retain them; `advanced` retains its dedicated manager/media/autocomplete assets and locale sorter; confetti remains main/progress-only. The settings hub reads only the stored values displayed in its Advanced summary card; category-ordering catalogs, font discovery, and answer-option preview sampling belong exclusively to the opened Advanced tool.
- Frontend Advanced settings and the legacy wordset taxonomy form share `ll_tools_wordset_save_category_ordering_settings()`. Treat ordering mode, manual order, and prerequisite map as one section: validate the submitted category registry and cycle-free graph before writing, verify each meta write, and restore the section snapshot on a failed write. Unrelated settings still save and must produce a partial-success warning when this section is rejected or restored.
- Recorder category overviews use the same bounded compact summary pipeline in both the manager and `[audio_recording_interface]`: hydrate authenticated batches through `ll_tools_recorder_queue_summaries` (six categories by default, twenty maximum), and remove categories whose completed summary has no queued work. A summary retains only two preview identities, but its canonical word/image, legacy missing-audio, and prompt-card sources keep separate cumulative cursors and counts until every bounded scan genuinely exhausts; publish only the resulting exact total and never expose a lower-bound `3+` card. The manager may reuse complete cached summaries during its PHP render, but it performs no cold summary refresh there. Its unresolved work starts with one three-category AJAX request, then continuously advances one serial six-category batch while the dedicated end sentinel remains in or just below the viewport. There is never more than one request in flight, and moving away from the end pauses before the next batch. Only the active bounded identities render full shimmer cards with their already-known category names; later identities retain canonical DOM positions as hidden lightweight markers, and unknown queue counts/previews remain skeletons until their scan resolves. Normal operation has no visible loaded-count copy or Load more control. Select untouched categories before retrying pending scans, use a bounded request timeout and short retry delay, and clear `aria-busy` with an explicit error-only Retry control on timeout, incomplete catalogs, or unclassified responses. The recording shortcode keeps its separate limit of three identity-free shells plus an overflow cue. The stream generation is structural: it keys the ordered category identities plus recorder/wordset/filter scope, while each category summary has its own content signature. Ordinary word/audio/image/prompt changes invalidate only affected cards and must not restart an otherwise valid bounded stream; true category identity/order/scope changes still reload it. The no-category shortcode route is an overview only: it must not hydrate or select a focused queue, render progress, or open New Word automatically. Its category cards navigate to dedicated `ll_record_category` pages, whose hidden active-category state supports bounded continuation and whose back link returns to the overview; do not restore the legacy visible category dropdown or in-place switching. An incomplete initial category catalog is not an authoritative empty queue: do not emit a generation or empty-state conclusion, render an explicit retry, and permit only bounded automatic retries before requiring user action. Focused-category and hidden queue item views stay paged; do not replace any of these surfaces with full-queue hydration.
- Focused recorder pages apply limits after recording eligibility across canonical word/image candidates, legacy missing-audio rows, and prompt cards in that order. Raw scans must be query-budgeted and resumable, and later pages plus same-page continuations must carry the prior keyset/offset instead of rescanning the source prefix. The site-wide legacy missing-audio option has no collision-safe wordset identity, so wordset-isolated queues must ignore it entirely without hydrating it; canonical word/image/prompt sources remain authoritative. Isolation-disabled sites retain the legacy global fallback. Isolated cursor contexts carry the disabled-source mode so tokens issued before this invariant rebase instead of retaining legacy page rows, while non-isolated cursor contexts stay compatible. Browser-visible cursors are short-lived HMAC tokens bound to the viewer, target recorder, wordset, category, filters, requested page, page size, and structural/recording-type epochs; never accept raw candidate IDs or offsets from request data. Both token segments must use canonical unpadded Base64URL so padding-bit aliases are rejected even when they decode to the same bytes. Do not bind cursors to ordinary audio or hide mutations: recorders consume and hide items between lazy batches, so the stable raw cursor must survive those expected eligibility changes. An invalid, expired, tampered, or context-mismatched supplied token must explicitly rebase to page one with `cursor_rebased` and `reset_queue`; never fall back to mutable numeric offsets. An empty bounded batch with `has_more` remains continuable (with repeat-token protection), and nonincremental same-page navigation must carry cumulative `page_items` so earlier legacy or prompt rows do not disappear. Never advertise `has_more` when a signed token cannot be produced: keep the bounded items, terminate automatic continuation, and expose `continuation_unavailable` for explicit recovery. Client continuation requests are category-generation scoped; a late response from a previously selected category must be discarded without mutating the new queue or pagination. Compact summaries must request the queue image's attachment or featured-image size before its linked word image or raw URL, reject unrenderable rows before filling preview slots, and remain incomplete while a bounded refill could still find useful work.
- Recorder prompt-card source pages commit `raw_offset` and `eligible_seen` only after the post batch, term scope, reference/meta/attachment reads, visibility, and prompt hints are complete. Incomplete work remains retryable and cannot become a final summary.
- Recorder queue summary caches use each word-category's canonical cache version plus a small shared signature for recorder-hidden entries, recording-type taxonomy changes, global desired-recording defaults, the recorder's effective `manage_options` private-category bypass, and the rarely changing structural category epoch as a wordless-image eligibility component. User-scoped catalog, category-map, and completed-summary identities must all separate that capability branch so an admin-to-recorder demotion cannot retain private category names, counts, or previews. Wordless images inherit the recording-type union of every user-visible, wordset-scoped sibling category, so sibling owner/source/privacy/access mutations and category deletion must invalidate a target summary even when the target category's own version is unchanged. Word, image, prompt-card, audio, and recording-type relationship mutations otherwise bump only their affected category versions; the virtual uncategorized summary conservatively retains the broader plugin epochs because it has no category identity. A matching completed summary stays reusable for its retention TTL (one day by default), without an age-only five-minute rebuild. The compact structural category catalog keeps its separate structure key.
- Expired database transients are reclaimed only by the hourly LL Tools maintenance job in `includes/lib/expired-transient-maintenance.php`: it skips external object-cache installs, selects at most 200 timeout rows from an exact LL-owned cache/rate-limit prefix allowlist after a five-minute grace period, and conditionally deletes each exact timeout/value pair only while the selected timeout remains expired. Do not broaden it to a global transient purge, persistent `ll_tools_*` options/jobs, request-path cleanup, or unbounded batches.

# Entry points and runtime flow
- `language-learner-tools.php`
  - Defines `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, `LL_TOOLS_MAIN_FILE`, `LL_TOOLS_MIN_WORDS_PER_QUIZ`.
  - Registers GitHub update checker (`main` only accepts packaged GitHub release assets; `dev` stays branch-based).
  - Activation adds `view_ll_tools`, seeds default wordset and recording page via transients.
  - Registers `/embed/<category>` rewrite + query var + template_include hook.
- `includes/bootstrap.php`
  - Loads all CPTs, taxonomies, roles, admin tools, pages, shortcodes, API wrappers, utilities, and vendor update checker.
  - Also loads shared quiz/data helpers like `includes/lib/flashcard-payload-materializer.php`, `includes/lib/word-option-rules.php`, `includes/lib/internal-review-notes.php`, `includes/lib/expired-transient-maintenance.php`, `includes/lib/public-ajax-resource-guards.php`, `includes/lib/wordset-category-search-index.php`, `includes/user-progress.php`, `includes/privacy.php`, `includes/login-window.php`, and `includes/teacher-classes.php`.
  - Loads `includes/api/automation-rest.php` directly; that controller loads `includes/api/word-metadata-plan-rest.php` for durable word-metadata plan job storage, processing, status, discard, and result helpers.
- `includes/assets.php`
  - `ll_enqueue_asset_by_timestamp()` enqueues local JS/CSS with `filemtime` versioning.
  - Public enqueue provides shared base LL Tools styles; feature-specific libraries (jQuery UI autocomplete, canvas-confetti) are enqueued on demand by the features that use them.
  - Non-admin style lives in `css/non-admin-style.css`.
- `includes/pages/quiz-pages.php`
  - Registers the generated `ll_quiz_page` CPT and creates one `/quiz/<category>` record per `word-category` (meta `_ll_tools_word_category_id`).
  - Migrates legacy generated WP Page children into the CPT, keeps legacy records discoverable during migration, and hides any remaining generated Page records from the normal Pages admin list.
  - Syncs on category/content changes; daily and on file mtime change; manual cleanup in admin.
  - Uses `templates/quiz-page-template.php` and `js/quiz-pages.js`; popup and iframe fallbacks keep dialog semantics, focus containment/restoration, translated loading/failure/timeout states, and retry/direct-open recovery.
  - A top-level full-screen quiz portals `#ll-tools-flashcard-popup` to `document.body` before dialog activation so wordset/theme descendants, compact-layout variables, and transformed ancestors cannot restyle or scale it. True embed/iframe runtimes stay inside `#ll-tools-flashcard-container` so their bounded viewport-fit variables remain in scope. Preserve focus trapping, background isolation, and opener restoration in both placements.
  - The quiz/game viewport zoom guard is an intentional mobile child-UX policy: accidental pinch/double-tap zoom during quizzes is prevented because young learners commonly cannot recover the layout. Preserve it unless product requirements explicitly change; accessibility work should improve semantics, keyboard/focus behavior, reduced motion, and recovery without silently removing this guard.
- `includes/pages/embed-page.php`
  - Minimal page for iframes; noindex; uses `[flashcard_widget]`.
  - Accepts `?wordset=<slug>` and `?mode=practice|learning|listening|gender|self-check`.
  - Posts `ll-embed-ready` to parent when initialized.
- `includes/pages/recording-page.php`
  - Ensures a default recording page with `[audio_recording_interface]`.
  - Uses shared shortcode-page admin helpers for notices, settings-row actions, and AJAX recreation.
  - Redirects `audio_recorder` users on login to the default recording page or a per-user page override.
- `includes/pages/editor-hub-page.php`
  - Ensures a default Editor Hub page with `[editor_hub]`.
  - Uses shared shortcode-page admin helpers for notices, settings-row actions, and AJAX recreation.
  - Redirects `ll_tools_editor` users on login to the default Editor Hub page.
- `includes/pages/site-tools-page.php`
  - Ensures a default Site Tools page with `[ll_site_tools]`.
  - Uses shared shortcode-page admin helpers for notices, settings-row actions, and AJAX recreation.
  - Provides a front-end home for selected sitewide settings, managed shortcode pages, plugin-update controls, privacy retention, and maintenance actions that previously lived only in wp-admin.
- `includes/lib/ai-crawler-support.php`
  - Serves generated `/llms.txt`, `/ll-tools/*.md`, and `/ll-tools/index.jsonld` exports for anonymous public AI-crawler discovery.
  - Exports must stay bounded and filter through explicit anonymous wordset/category visibility checks; canonical HTML pages remain the source of record.
  - GET export bodies use route-level object/transient caching; HEAD requests remain header-only and must not build cold export bodies.
- `includes/lib/public-ajax-resource-guards.php`
  - Provides atomic fixed-window counters, expiring per-client leases, exact-owner release, and bounded cache-wait polling for anonymous cache-miss admission.
- `includes/lib/media-proxy.php`
  - Signed image proxy (`lltools-img`, `lltools-size`, `lltools-sig`) to hide filenames.
  - Missing local files may use only validated public HTTP(S) fallback URLs. Remote bytes stream into a size-bounded temporary file, must validate as an allowed raster image, and publish through an exact-owner per-key lease into the uploads cache. Fresh disk hits avoid origin work; contention waits briefly for the owner, then serves validated stale data no older than 14 days or redirects to the safe origin. Origin failures back off for five minutes. Each attachment/size bucket keeps at most four cached images and 32 MB by default, attachment deletion removes all deterministic shards for that attachment, and daily maintenance advances through the 65,536 attachment/size shards in bounded one-minute continuations with native seeks capped at 1,024 entries. Open cache handles protect Windows readers across atomic replacement, and fallback response cache headers never outlive the server freshness TTL.

# Direct bootstrap include index

This list mirrors plugin-owned direct includes in `includes/bootstrap.php` in
load order. Update it with bootstrap changes; the maintenance source-contract
test compares this block against the file. The external plugin-update-checker
vendor include is documented in the directory map instead of this plugin-owned
module list.

<!-- bootstrap-include-index:start -->
- includes/assets.php
- includes/lib/php-compat.php
- includes/lib/expired-transient-maintenance.php
- includes/lib/public-ajax-resource-guards.php
- includes/lib/wordset-category-search-index.php
- includes/lib/sort.php
- includes/lib/text-display.php
- includes/lib/entity-translations.php
- includes/lib/wordset-language-settings.php
- includes/lib/word-translations.php
- includes/lib/audio-originals.php
- includes/lib/custom-stt-endpoint.php
- includes/lib/internal-review-notes.php
- includes/lib/interlinear.php
- includes/lib/site-sync.php
- includes/template-loader.php
- includes/teacher-classes.php
- includes/login-window.php
- includes/lib/media-proxy.php
- includes/lib/image-aspect.php
- includes/lib/image-animation.php
- includes/lib/word-option-rules.php
- includes/lib/image-hash.php
- includes/lib/image-match-index.php
- includes/flashcard-shell.php
- includes/post-types/words-post-type.php
- includes/post-types/dictionary-entry-post-type.php
- includes/post-types/word-image-post-type.php
- includes/post-types/word-audio-post-type.php
- includes/post-types/prompt-card-post-type.php
- includes/post-types/vocab-lesson-post-type.php
- includes/post-types/content-lesson-post-type.php
- includes/lib/dictionary-sources.php
- includes/lib/dictionary-static-cache.php
- includes/lib/public-static-cache.php
- includes/lib/dictionary-search-index.php
- includes/lib/dictionary-browser.php
- includes/lib/dictionary-snapshot.php
- includes/taxonomies/word-category-taxonomy.php
- includes/taxonomies/wordset-taxonomy.php
- includes/taxonomies/language-taxonomy.php
- includes/taxonomies/part-of-speech-taxonomy.php
- includes/taxonomies/recording-type-taxonomy.php
- includes/wordset-isolation.php
- includes/wordset-templates.php
- includes/lib/flashcard-payload-materializer.php
- includes/user-roles/wordset-manager.php
- includes/user-roles/ll-tools-editor.php
- includes/user-roles/learner-role.php
- includes/user-roles/audio-recorder-role.php
- includes/user-roles/teacher-role.php
- includes/admin/uploads/upload-scope.php
- includes/admin/uploads/audio-upload-form.php
- includes/admin/uploads/image-upload-form.php
- includes/user-progress.php
- includes/content-lesson-progress.php
- includes/user-study.php
- includes/user-progress-report-data.php
- includes/offline-app-sync.php
- includes/privacy.php
- includes/admin/api/deepl-api.php
- includes/admin/api/assemblyai-api.php
- includes/api/automation-rest.php
- includes/admin/word-option-rules-admin.php
- includes/admin/admin-dashboard-menu.php
- includes/admin/missing-audio-admin-page.php
- includes/admin/audio-image-matcher.php
- includes/admin/settings.php
- includes/admin/audio-processor-admin.php
- includes/admin/login-blocks-admin.php
- includes/admin/recording-types-admin.php
- includes/admin/metabox-word-audio-parent.php
- includes/admin/bulk-translation-admin.php
- includes/admin/bulk-word-import-admin.php
- includes/admin/prompt-audio-import-admin.php
- includes/admin/dictionary-import-admin.php
- includes/admin/dictionary-sources-admin.php
- includes/admin/export-import.php
- includes/admin/offline-app-export.php
- includes/admin/user-progress-report.php
- includes/admin/teacher-classes-page.php
- includes/admin/word-images-fixer.php
- includes/admin/example-sentence-migration.php
- includes/admin/ipa-keyboard-admin.php
- includes/admin/image-aspect-normalizer-admin.php
- includes/admin/image-webp-optimizer-admin.php
- includes/admin/orphan-media-admin.php
- includes/admin/split-word-admin.php
- includes/admin/duplicate-category-words-admin.php
- includes/admin/site-sync-admin.php
- includes/migrations/legacy-content-lessons.php
- includes/cli/cli-support.php
- includes/cli/class-ll-tools-cli-command.php
- includes/pages/quiz-pages.php
- includes/pages/vocab-lesson-pages.php
- includes/pages/content-lesson-pages.php
- includes/pages/wordset-games.php
- includes/pages/wordset-pages.php
- includes/pages/wordset-editor.php
- includes/pages/default-shortcode-page-helper.php
- includes/pages/recording-page.php
- includes/pages/editor-hub-page.php
- includes/pages/dictionary-page.php
- includes/pages/site-tools-page.php
- includes/lib/ai-crawler-support.php
- includes/shortcodes/flashcard-widget.php
- includes/shortcodes/word-audio-shortcode.php
- includes/shortcodes/interlinear-shortcode.php
- includes/shortcodes/semantic-mark-shortcode.php
- includes/shortcodes/content-lesson-index-shortcode.php
- includes/shortcodes/word-grid-shortcode.php
- includes/shortcodes/ranked-word-list-shortcode.php
- includes/shortcodes/editor-hub-shortcode.php
- includes/shortcodes/image-copyright-grid-shortcode.php
- includes/shortcodes/audio-credit-grid-shortcode.php
- includes/shortcodes/quiz-pages-shortcodes.php
- includes/shortcodes/audio-recording-shortcode.php
- includes/shortcodes/language-switcher-shortcode.php
- includes/shortcodes/wordset-page-shortcode.php
- includes/shortcodes/wordset-buttons-shortcode.php
- includes/shortcodes/dictionary-shortcode.php
- includes/shortcodes/site-tools-shortcode.php
- includes/i18n/language-switcher.php
<!-- bootstrap-include-index:end -->

# Directory map (top level)
```
language-learner-tools.php    # Bootstrap, constants, updates, /embed rewrite
includes/
  assets.php                  # Versioned enqueue helper + public assets
  bootstrap.php               # Central includes
  flashcard-shell.php         # Shared flashcard overlay shell/results/repeat-button renderer
  offline-app-sync.php        # REST/manifest sync support for offline exports
  privacy.php                 # Progress privacy controls, exporter/eraser hooks, policy text, retention cleanup
  template-loader.php         # Theme override resolver
  teacher-classes.php         # Teacher class helpers, frontend actions, class membership
  login-window.php            # Frontend login/signup window support
  legacy-content-lesson-contracts.php # Stable legacy source/category/default-wordset migration contract
  user-progress.php           # Learner progress writes/lookups
  user-progress-report-data.php # Shared progress report queries
  wordset-isolation.php       # Wordset-owned category isolation/remapping
  wordset-templates.php       # Reusable wordset template bundles
  lib/
    php-compat.php            # Compatibility helpers for older PHP runtimes
    expired-transient-maintenance.php # Bounded hourly cleanup for expired LL-owned database transients
    public-ajax-resource-guards.php # Atomic anonymous cache-miss budgets, leases, and bounded cache waits
    wordset-category-search-index.php # Durable bounded wordset category-search materializer
    flashcard-payload-materializer.php # Durable generation-scoped flashcard rows and signed page cursors
    sort.php                  # Shared sorting helpers
    text-display.php          # Display text normalization/helpers
    entity-translations.php   # Locale-keyed wordset/category/lesson display text translations
    wordset-language-settings.php # Per-wordset target/title/translation language settings
    audio-originals.php       # Optional preserved-original audio helpers
    custom-stt-endpoint.php   # Wordset-scoped custom STT endpoint validation/storage
    internal-review-notes.php # Staff-only notes for words and prompt cards
    site-sync.php             # Wordset-scoped live/staging sync snapshots and merge planning
    dictionary-sources.php    # Dictionary source metadata/import attribution
    dictionary-static-cache.php # Public dictionary cache helpers
    public-static-cache.php   # Anonymous public-page cache helpers
    dictionary-search-index.php # Dictionary normalized search index helpers
    dictionary-browser.php    # Dictionary import/search helpers
    dictionary-snapshot.php   # Dictionary export/snapshot helpers
    ll-matching.php           # Audio <-> image matching heuristics
    image-match-index.php     # Bounded normalized-title candidates for automatic audio/image matching
    media-proxy.php           # Signed image proxy for quizzes
    image-aspect.php          # Image aspect utilities for normalizer/admin tools
    image-animation.php       # Animated-image detection/helpers
    word-option-rules.php     # Word option group/conflict rules storage/helpers
    word-grid-bulk-operations.php # Durable owner-scoped word-grid bulk batches, rollback chunks, continuation, and undo
    image-hash.php            # Perceptual image hashing/similarity helpers
  api/
    automation-rest.php       # JSON-first automation endpoints with server-side guardrails
    word-metadata-plan-rest.php # Durable word-metadata plan job helpers loaded by automation-rest.php
  pages/
    quiz-pages.php            # Auto /quiz CPT records + sync + assets
    embed-page.php            # /embed/<category> template
    default-shortcode-page-helper.php # Shared ensure/find/admin-action helpers for plugin-owned shortcode pages
    recording-page.php        # Recording page creation + login redirect
    editor-hub-page.php       # Editor Hub page creation + login redirect
    dictionary-page.php       # Dictionary page creation + settings-row controls
    site-tools-page.php       # Front-end Site Tools page creation + URL helper
    wordset-pages.php         # Wordset hub pages (main/progress/settings/hidden)
    wordset-editor.php        # Frontend wordset editor tool for filtering, bulk edits, media review, recording moves, and action undo
    vocab-lesson-pages.php    # Vocab lesson pages + enable/sync flows
    content-lesson-pages.php  # Content lesson routing/rendering
    wordset-games.php         # Wordset game catalog/runtime helpers
  post-types/
    words-post-type.php
    dictionary-entry-post-type.php
    word-image-post-type.php
    word-audio-post-type.php
    vocab-lesson-post-type.php
    content-lesson-post-type.php
    prompt-card-post-type.php
  taxonomies/
    word-category-taxonomy.php # Category behavior plus aggregate wordset category-scope ID queries
    wordset-taxonomy.php
    language-taxonomy.php
    part-of-speech-taxonomy.php
    recording-type-taxonomy.php
  shortcodes/
    flashcard-widget.php
    quiz-pages-shortcodes.php
    word-grid-shortcode.php
    ranked-word-list-shortcode.php # [ll_ranked_word_list] + bounded generic rank-row importer helper
    editor-hub-shortcode.php
    site-tools-shortcode.php  # [ll_site_tools] sitewide settings + maintenance UI
    word-audio-shortcode.php
    semantic-mark-shortcode.php # [ll_mark] + legacy [color1]/[color2]/[color3]
    content-lesson-index-shortcode.php # [ll_content_lesson_index] + bounded legacy lesson shortcode shims
    wordset-page-shortcode.php
    wordset-buttons-shortcode.php
    audio-credit-grid-shortcode.php
    audio-recording-shortcode.php
    image-copyright-grid-shortcode.php
    language-switcher-shortcode.php
    dictionary-shortcode.php  # [ll_dictionary] + legacy dictionary shortcode aliases
  migrations/
    legacy-content-lessons.php # Resumable lessons, relations/link rewrites, and completion migration
  admin/
    admin-dashboard-menu.php
    settings.php
    user-progress-report.php  # Admin-only learner progress/usage report
    audio-processor-admin.php
    audio-image-matcher.php
    missing-audio-admin-page.php
    recording-types-admin.php
    bulk-translation-admin.php
    bulk-word-import-admin.php
    prompt-audio-import-admin.php
    dictionary-import-admin.php
    dictionary-sources-admin.php
    export-import.php
    site-sync-admin.php       # LL Site Sync admin workflow for pull/preview/push
    offline-app-export.php
    teacher-classes-page.php
    example-sentence-migration.php
    ipa-keyboard-admin.php
    word-option-rules-admin.php
    split-word-admin.php
    duplicate-category-words-admin.php
    image-aspect-normalizer-admin.php
    image-webp-optimizer-admin.php
    orphan-media-admin.php
    word-images-fixer.php
    metabox-word-audio-parent.php
    uploads/
      upload-scope.php       # Shared wordset/category target-scope renderer
      audio-upload-form.php
      image-upload-form.php
    api/deepl-api.php
    api/assemblyai-api.php
  user-study.php
  i18n/language-switcher.php
  user-roles/
    wordset-manager.php
    ll-tools-editor.php
    learner-role.php
    audio-recorder-role.php
js/
  flashcard-widget/           # Modular quiz system
  audio-processor.js
  audio-image-matcher.js
  audio-recorder.js
  quiz-pages.js
  quiz-pages-shortcodes.js
  wordset-pages.js
  wordset-games.js
  wordset-settings-media.js
  vocab-lesson-page.js
  vocab-lesson-print-page.js
  vocab-lesson-word-options-modal.js
  content-lesson-player.js
  content-lesson-progress.js
  content-lesson-admin.js
  text-document.js
  text-document-review-notes.js
  word-audio.js
  word-grid.js
  word-edit-modal.js
  manage-wordsets.js
  bulk-category-edit.js
  dictionary-shortcode.js
  editor-hub.js
  export-import-admin.js
  language-switcher.js
  login-window.js
  frontend-utility-menu.js
  public-viewport-guard.js
  self-check-shared.js
  site-tools.js
  wordset-buttons-refresh.js
  wordset-offline-export.js
css/
  language-learner-tools.css
  quiz-pages.css
  quiz-pages-style.css
  recording-interface.css
  audio-processor.css
  audio-image-matcher.css
  content-lesson-admin.css
  content-lesson-index.css
  content-lesson-pages.css
  dictionary-shortcode.css
  frontend-utility-menu.css
  language-switcher.css
  ranked-word-list.css
  self-check-shared.css
  site-tools.css
  vocab-lesson-admin.css
  vocab-lesson-pages.css
  vocab-lesson-word-options-modal.css
  wordset-games.css
  wordset-offline-export.css
  wordset-pages.css
  flashcard/
    base.css
    mode-practice.css
    mode-learning.css
    mode-listening.css
templates/
  flashcard-widget-template.php
  quiz-page-template.php
  audio-image-matcher-template.php
media/
  right-answer.mp3
  wrong-answer.mp3
  play-symbol.svg
  stop-symbol.svg
data/
  iso-languages/              # ISO 639 language tables
vendor/
  getid3/                     # audio metadata
  plugin-update-checker/      # GitHub update checker
```
# Core data model (canonical)
## Custom post types
- `words` (public, REST)
  - Key meta: `word_translation`, legacy `word_english_meaning`, legacy `word_audio_file`.
  - Other meta: `word_example_sentence`, `word_example_sentence_translation`, `similar_word_id`.
  - Optional link meta: `ll_dictionary_entry_id` (links to umbrella dictionary entry).
  - Current image-migration state: the `words` featured image is a compatibility mirror only; new read paths should resolve the linked `word_images` record first via the effective-image helpers in `includes/post-types/word-image-post-type.php`.
  - Publish guard: requires at least one published `word_audio` when category config needs audio.
    - Bypass with `_ll_skip_audio_requirement_once` or filter `ll_tools_skip_audio_requirement`.
- `ll_dictionary_entry` (admin-facing umbrella entries)
  - Groups related `words` posts (e.g., different learnable forms) without changing `words` as the quiz/recording unit.
  - Imported dictionaries can store grouped structured senses in `ll_dictionary_entry_senses`, with derived summary/search meta for public browse/search and translation fallback lookup.
- `word_images` (public, REST)
  - Featured image is the media asset.
  - Meta: `copyright_info`, plus translation fields used by grids.
  - Canonical image record for vocabulary items; linked from `words` through `_ll_autopicked_image_id` and related matching/sync flows.
- `word_audio` (admin-only UI, REST)
  - Child of `words` via `post_parent`.
  - Meta: `audio_file_path`, `recording_date`, `speaker_user_id` or `speaker_name`, `_ll_needs_audio_processing`.
  - Terms: `recording_type` (isolation, sentence, question, introduction, etc).
- `ll_vocab_lesson` (public lesson pages)
  - Generated/synced from enabled wordsets and categories.
  - Routes are handled by custom rewrite rules rather than native CPT rewrites.
  - Lesson pages can include word grids, prompt-card grids, manager settings, prerequisites, text visibility, enabled games, desired recording types, and category-specific quiz config.
- `ll_content_lesson` (public content lessons)
  - Rewrite slug: `/lesson/<slug>`.
  - Meta includes wordset, lesson kind, media type/url, transcript source/format/cues, linked category ids, mix-in flag, and prerequisite category/content-lesson ids.
  - Lesson kind defaults to `standard`; `article` renders migrated/editorial lessons without media chrome; `corpus_text` suppresses empty audio/video chrome and renders text-document payloads from the interlinear payload meta.
  - The resumable legacy lesson migration keeps the source post ID/URL, a sanitized category snapshot, exact repeated source-category ID rows, concepts, level, and unresolved relation audit data. Successful apply batches set one compatibility wordset option used by temporary shortcode shims. Exact source-category rows are the only public category-index query contract; do not query arbitrary serialized snapshots. A retained-source migration is a narrow compatibility exception: one published empty shadow target preserves prerequisite/completion identity while all generated links and direct requests resolve to the still-published source; any retained marker fails closed out of normal lesson/mix/corpus/AI/search/feed/REST/sitemap catalogs. The temporary `[display_prereq_tree]` compatibility index is the sole opt-in catalog exception: it may show a valid retained bridge as a compact source link so legacy navigation does not lose the editorial page. Production operators must follow `docs/LEGACY_LESSON_MIGRATION_RUNBOOK.md`.
  - Text-document payloads support public Text, Interlinear, and Sources views from `reading_units`, `source_lines`, `witnesses`, `display_rows`, and regular interlinear `tokens`; ordinary learning-content interlinears remain staff-gated.
  - Corpus collection links resolve through the saved `_ll_tools_corpus_text_grid_collection` page index and a positive/negative transient. Save/delete hooks maintain the index; pre-index pages use only the bounded 20-candidate legacy lookup before materializing it. Do not restore a request-time scan of every Page.
  - Can render inside the mixed wordset lesson grid when `show_in_mix` is enabled.
- `ll_prompt_card` (admin UI, REST)
  - Prompt-first quiz/lesson cards that can use prompt text/audio/image plus correct and wrong answer word ids.
  - Can be surfaced in lesson grids, recorder queues, quiz payloads, progress tracking, and internal review notes.
- `ll_teacher_class` (hidden CPT)
  - Stores wordset-scoped class records with teacher ownership and learner membership.
  - Managed primarily through the wordset Classes view; invite and manual-assignment helpers live in `includes/teacher-classes.php`.
  - Both the frontend and legacy wp-admin Classes surfaces page class/account results and hydrate at most one learner-progress page. Keep class/account queries at `page_size + 1`, use `ID ASC` after title/display-name ordering so offset pages are deterministic, preserve the selected teacher outside the current account page, globally order admin progress before applying its bounded `number`/`offset`, reset empty class pages, clamp stale final learner-page requests, and label paged progress metrics as page-scoped rather than rebuilding full-class aggregates during an interactive request.

## Taxonomies
- `word-category` (flat; attached to `words` and `word_images`)
  - Translation meta: `term_translation` when translation is enabled.
  - Quiz config meta: `ll_quiz_prompt_type` (audio|image|text_translation|text_title), `ll_quiz_option_type` (image|text_translation|text_title|audio|text_audio).
  - Desired recording types: `ll_desired_recording_types` (list of slugs; sentinel `__none__` disables recording for the category).
  - Helpers: `ll_tools_get_category_display_name()`, `ll_tools_get_category_quiz_config()`, `ll_can_category_generate_quiz()`.
- `wordset` (flat; attached to `words` and `ll_prompt_card`)
  - Meta: `ll_language`, `ll_wordset_recorder_text_visibility`, `manager_user_ids` (canonical multi-manager list), legacy `manager_user_id` primary mirror.
  - Capabilities: `edit_wordsets` etc; non-admins see only managed wordsets.
  - Active wordset resolution: `ll_tools_get_active_wordset_id()`; default seeded on activation.
- `language` (attached to `words`, populated from `data/iso-languages` on first run).
- `part_of_speech` (attached to `words`).
- `recording_type` (attached to `word_audio`).

## Common meta and flags
- `_ll_tools_word_category_id` on generated `ll_quiz_page` records; legacy generated Pages may carry it until migration.
- `_ll_picked_count`, `_ll_picked_last`, `_ll_autopicked_image_id` for image matching usage tracking.
- `_ll_needs_audio_processing` for unprocessed audio queue.

# Deferred maintenance notes
## Editor Hub product review
- The Editor Hub remains loaded for shortcode/page compatibility and for existing
  login redirects, but it is no longer linked from the primary front-end utility
  nav.
- Before expanding or promoting this surface again, decide whether to rebuild it
  around current wordset-editor workflows or remove the standalone hub if no
  active users depend on it.

## Routing scalability migration
- Wordset/vocab-lesson routing scalability should be planned as a standalone
  compatibility migration. Do not replace the current root pretty routes without
  tests for existing bookmarks, canonical query URLs, reserved subpage slugs,
  vocab lesson URLs, and `/embed/<category>?wordset=<slug>&mode=...`.

## Word image migration follow-up
- Current direction: keep `word_images` as the canonical image object and treat the `words` post thumbnail as legacy compatibility state until all remaining consumers are migrated.
- Short-term follow-up:
  - Continue replacing direct `words` thumbnail reads (`has_post_thumbnail()`, `get_post_thumbnail_id()`, `get_the_post_thumbnail_url()`) with the effective-image helper layer in `includes/post-types/word-image-post-type.php`.
  - Keep wp-admin from being the primary authoring surface for word images; prefer linked-image editing flows in the Editor Hub, wordset tools, or other front-end management UI.
  - When adding new image-aware features, wire them to the linked `word_images` record instead of storing new image state on `words`.
- Later cleanup, once UI coverage is complete:
  - Move image maintenance/fixer workflows out of wp-admin utilities and into the normal plugin UI where practical.
  - Remove `thumbnail` support from the `words` post type after all read/edit paths no longer depend on it directly.
  - Retire one-way mirror/sync code and legacy fixer assumptions that exist only to support duplicated image state on `words`.

## Flashcard payload follow-ups
- The durable server protocol is paged. Generic flashcard-widget launches and
  the remaining specialized wordset flows can still drain every materialized
  page for a selected category before launch. Logged-in filtered and broad
  non-learning wordset selections are the bounded reference path: one
  `ll_user_study_selection_launch_plan` request either returns the complete
  matching ID-only session divided into capped, category-aware chunks or fails
  closed when a sparse layout cannot form valid rounds within the hard category
  cap. Candidate-specific requests hydrate only the current chunk; after exact
  coverage validation, pass those rows directly into the flashcard runtime with
  no second fetch for the same chunk. Append the server-planned transport chunks
  serially inside one logical practice session before rendering final results;
  do not restore full selected-category drains or hydrate all matching
  categories at once. Extend that contract deliberately if learning or another
  specialized flow moves to bounded startup.
- Prompt-card work is bounded to five prompt cards per materializer batch by
  default, but one prompt may still collect and hash up to 300 support images in
  that foreground lease. A nested prompt/support cursor or a separate bounded
  hash materialization is still needed; lowering the support ceiling alone can
  silently remove legitimate prompt options.
- Signature drift currently warms a replacement generation and rejects stale
  cursors. There is no last-known-good serving path for flashcard payload rows.
  Adding one requires an explicit compatibility and privacy design so a prior
  public or viewer-scoped generation cannot cross locale, presentation,
  visibility, wordset, or content-epoch boundaries.

## User meta and per-user state
- User study state (from `includes/user-study.php`):
  - `ll_user_study_wordset`, `ll_user_study_categories`, `ll_user_study_starred`, `ll_user_fast_transitions`.
  - `ll_user_star_mode` is legacy cleanup-only state. Starred-only mode is session/runtime state and must not be saved as persisted user meta.
- Audio recorder config (from `includes/user-roles/audio-recorder-role.php`):
  - `ll_recording_config` (wordset, category, recording type filters, allow_new_words, auto_process_recordings).
  - `ll_recording_page_id` (custom internal page override on login; legacy same-site URLs are migrated when encountered).

# Settings and options
Core settings live in `includes/admin/settings.php`:
- `ll_target_language`, `ll_translation_language`.
- `ll_enable_category_translation`, `ll_category_translation_source`.
- `ll_word_title_language_role` (target vs translation).
- `ll_max_options_override` (max multiple-choice options).
- `ll_flashcard_image_size` (small/medium/large).
- `ll_hide_recording_titles` (fallback recording UI default when no wordset-specific recorder text setting applies).
- `ll_quiz_font` and `ll_quiz_font_url` (font selection; fonts must already be enqueued by theme/plugin).
- `ll_update_branch` (`main` stable release asset channel, `dev` branch-testing channel).
- `ll_user_progress_events_retention_days` (retention for detailed learner activity events; summary progress stays until erasure/deletion).

Transcription and orthography behavior must be configurable at the site,
wordset, recording type, or entry/word-bound setting layer. Do not hard-code
new language-specific, site-specific, or wordset-specific transcription rules
in plugin code; if a production language needs another exception or phonetic
allowance, add a reusable setting and tests that prove an individual site or
wordset can opt into it.

# Public UI surfaces and routes
## Shortcodes (user-facing)
- `[flashcard_widget]` (controller: `includes/shortcodes/flashcard-widget.php`)
  - Attributes: `category`, `mode`, `embed`, `quiz_mode` (practice|learning|listening|gender|self-check), `wordset`, `wordset_fallback`.
- `[quiz_pages_grid]` and `[quiz_pages_dropdown]` (`includes/shortcodes/quiz-pages-shortcodes.php`).
  - Cold public reads use a durable stale snapshot and never run the synchronous full rebuild inline. Refreshes persist generation-scoped chunks, keyset-query one canonical quiz-page post-type batch at a time (100 rows by default, hard cap 250), serialize resets behind the scope lock, fence writes by lock token plus durable generation, and atomically replace the latest manifest only after current and legacy phases finish. When epochs advance before any usable latest snapshot exists, preserve and finish the one valid partial generation before scheduling its replacement, but only while its plugin-versioned builder token matches; a deploy resets incompatible partial chunks. A genuinely empty stale snapshot, or an explicit-scope snapshot whose rows are all obsolete or unviewable, is not usable evidence for discarding the only advancing generation. The worker disables persistence of derived category/count/gender/aspect/default-wordset/eligibility transients so a large cold catalog does not create hundreds of database writes, while request/object caches remain available. Manifest readiness verifies every chunk without assembling the catalog, interrupted publication keeps old chunk references recoverable, AJAX/manual continuations suppress unrelated cron work, and the signed no-JavaScript refresh link directly advances one bounded worker batch. The loading UI permits 120 bounded continuations by default. Keep the synchronous full rebuild helper limited to explicit maintenance and compatibility tests.
  - Each bounded catalog row batch is atomic: an incomplete category, visibility, effective-wordset, quiz-config, sign-mode, media, or default-wordset read leaves both the latest manifest and the partial cursor unchanged.
  - A popup launch card is authoritative for its wordset, quiz mode, display mode, prompt type, and option type. The localized flashcard category registry may be paged and may not yet contain that card's category, so `llOpenFlashcardForCategory()` must synthesize or update the launch category from the trigger attributes before widget initialization. Do not require the target category to be present in the initial registry. Canonical regressions: `tests/e2e/specs/quiz-popup-text-translation-options.spec.js` and `tests/e2e/specs/text-to-text-learning-intro.spec.js`.
- `[word_grid]` (`includes/shortcodes/word-grid-shortcode.php`).
  - `includes/shortcodes/word-grid-shortcode.php` loads `includes/lib/word-grid-bulk-operations.php`; bulk POS and grammar changes use its owner-scoped durable operations. Each request persists a `preparing` batch before creating its non-autoloaded rollback chunk, revalidates the saved target IDs against the current lesson scope, lease-fences state writes, verifies mutation readback, and only then advances the keyset cursor. Status/Continue/Undo survive reload; a generic recovery row keeps Undo reachable when a grammar feature is later disabled. Undo runs newest chunk first, verifies restoration before retiring a chunk, and skips only rows whose relevant state changed outside the recorded bulk/failed-write states. Operations expire after one day by default (filterable from one hour through seven days), and token cleanup deletes rollback chunks in bounded scheduled batches. Do not restore browser-owned rollback snapshots or unbounded one-request bulk mutations.
- `[ll_ranked_word_list]` (`includes/shortcodes/ranked-word-list-shortcode.php`, CSS: `css/ranked-word-list.css`).
  - Resolves one exact visible `word-category`, reads a list-scoped page query argument, and queries at most 100 published `words` ordered by the numeric `_ll_tools_word_rank` meta plus ID. Word/meta/term data is primed for the page and audio uses one parent-scoped bulk collection instead of a query per row. `ll_tools_import_ranked_word_rows()` accepts at most 200 already-parsed associative rows, resolves only explicit IDs or bounded exact-title matches, writes only the rank meta key, verifies writes, and reports unchanged rows idempotently; it deliberately exposes no public mutation endpoint or whole-site discovery scan.
- `[word_audio]` (`includes/shortcodes/word-audio-shortcode.php`, JS: `js/word-audio.js`).
  - The public control is a fixed 1.5rem inline square. Keep its wrapper-qualified element selector and explicit size/reset declarations in `css/language-learner-tools.css`; generic Astra, Elementor, and other theme `button` rules must not enlarge or recolor it. Canonical browser regression: `tests/e2e/specs/word-audio-theme-resilience.spec.js`.
- `[ll_mark]` plus legacy `[color1]`, `[color2]`, and `[color3]` (`includes/shortcodes/semantic-mark-shortcode.php`).
  - The canonical `tone` allowlist is `orange|blue|green`; unsupported values fall back to orange and are never reflected into classes or styles. All tags emit class-only inline markup, expand nested shortcodes, and restrict the nested result to safe phrasing elements so block markup cannot create invalid span nesting. Canonical marks use accessible darker colors plus distinct underline cues; compatibility aliases preserve TurkishTextbook's exact orange (`#ff6600`), blue (`#0066ff`), green (`#77b300`), and bold presentation through theme-resistant public CSS.
- `[ll_content_lesson_index]` (`includes/shortcodes/content-lesson-index-shortcode.php`, CSS: `css/content-lesson-index.css`).
  - Resolves exactly one visible wordset, optionally filters the migration's exact repeated legacy category-ID meta, groups one page by `menu_order` lesson level, and renders previous/next links through a list-scoped query argument. Signed-in cards distinguish completed, ready (all direct prerequisites complete, including lessons with none), and prerequisites-incomplete states from the already-primed page meta plus one compact user completion lookup; do not replace that bounded overlay with per-card prerequisite queries. Page size and numeric page caps are both 100.
  - Temporary `[display_prereq_tree]`, `[custom_header]`, `[custom_footer]`, `[regex_linker]`, and `[signup_link]` shims register only when no other plugin owns the tag. They resolve stable migrated source mappings, use bounded prerequisite/dependent helpers, sanitize cached linked HTML, and use current front-end auth URLs. Do not port the legacy debug shortcode, whole-site reverse scans, or global theme CSS dequeues.
- `[wordset_page]` / `[ll_wordset_page]` (`includes/shortcodes/wordset-page-shortcode.php`).
  - Dedicated wordset homes show content lessons by default. The wordset-level `ll_wordset_show_content_lessons` setting may suppress both featured and mixed content-lesson cards without changing lesson publication or bounded-index visibility; missing meta must preserve the historical visible default, and anonymous lazy-card fallback payloads must honor the same setting.
  - A shortcode page whose slug matches its wordset redirects to the dedicated canonical route. A differently named retirement page redirects only with the explicit `redirect_to_canonical="1"` attribute; preserve safe query arguments and never infer that redirect from an ordinary embedded wordset hub.
  - A wordset-owned category may carry one optional compact card reference (`ll_category_card_reference_url` plus an optional label). Read it only from the exact owned term, accept only single-slash site-relative or HTTP(S) URLs, omit empty fields from compact runtime payloads, and keep PHP and client-side card renderers equivalent so lazy loading/search/sorting cannot drop it.
- `[wordset_buttons]` / `[ll_wordset_buttons]` (`includes/shortcodes/wordset-buttons-shortcode.php`).
  - Count generations are bounded and keyed by user identity plus current structural/content epochs because private-category grants can differ even for identical visible wordset IDs. An incomplete signed-in scope may render only complete anonymous public HTML from the structurally scoped LKG or the exact current/prior-release anonymous cache; it must never publish partial counts or private markup to that fallback. Its authenticated, nonce-protected `ll_tools_wordset_buttons_refresh` AJAX loader advances one bounded batch at a time and replaces the loading/fallback shell only after the exact user scope is complete; there is deliberately no anonymous AJAX action. Continuation cron events carry the initiating user ID, restore that context only around the worker, and restore the prior user afterward. A genuinely cold scope renders a loading shell rather than an empty section, while an authoritative complete-empty scope remains empty. Direct role-change invalidation is tracked separately in the maintenance backlog.
- `[audio_recording_interface]` (`includes/shortcodes/audio-recording-shortcode.php`).
- `[audio_upload_form]` and `[image_upload_form]` (bulk upload helpers in `includes/admin/uploads/`).
- `[image_copyright_grid]` (`includes/shortcodes/image-copyright-grid-shortcode.php`).
- `[ll_language_switcher]` (`includes/shortcodes/language-switcher-shortcode.php`).
- `[ll_dictionary]` (`includes/shortcodes/dictionary-shortcode.php`).
- `[ll_corpus_text_grid]` / `[ll_text_document_grid]` (`includes/pages/content-lesson-pages.php`).
- `[ll_site_tools]` (`includes/shortcodes/site-tools-shortcode.php`).
- `[audio_credit_grid]` (`includes/shortcodes/audio-credit-grid-shortcode.php`).

## Routes
- `/quiz/<category>` auto pages (created/synced by `includes/pages/quiz-pages.php`).
  - Optional params: `?mode=practice|learning|listening|gender|self-check`.
- `/embed/<category>` embed page (handled by `includes/pages/embed-page.php`).
  - Optional params: `?wordset=<slug>` and `?mode=practice|learning|listening|gender|self-check`.
- `/<wordset>` wordset hub pages (handled by `includes/pages/wordset-pages.php`).
  - Views: main, `progress`, `hidden-categories`, `settings`, `games`, and `classes`.
  - The settings view can launch the Wordset Editor tool (`ll_wordset_tool=editor`, implemented in `includes/pages/wordset-editor.php`) for searchable word tables, media-status filters, bulk category/status/review actions, recording moves, saved views, and recent-action undo.
- `/<wordset>/<category>` vocab lesson pages (handled by `includes/pages/vocab-lesson-pages.php`).
- Vocab lesson category counts use compact relationship aggregates. When image eligibility includes wordset-isolated image copies, `ll_tools_effective_word_image_presence_sql()` materializes the target wordset's eligible source-image IDs once; do not restore a correlated owner/source postmeta scan per candidate word or replace the compact count with whole-wordset post hydration.
- Standard vocab lessons above the generic paging threshold (48 visible words by default) use `ll_tools_get_vocab_lesson_grid` as a serial bounded loader for learners and staff. It keyset-scans at most 128 candidate IDs per preparation request, materializes the exact filtered/manual/grouped/title order behind an exact-owner lock, then renders at most 24 word cards per signed cursor page. Public and per-user staff order states are separate; staff scans also retain drafts and published words hidden by missing presentation media, moving those warning cards to the end rather than dropping them from candidate-specific pages. Staff pages render lightweight edit triggers rather than embedding the rich hidden editor and full wordset category catalog in every card; `ll_tools_get_word_edit_modal_grid` hydrates one detached editor only after an edit click. The browser replaces the shimmer shell with the first real page, appends later pages one request at a time, retains already-rendered cards on failure, and enforces a request timeout with Retry. Keep this category-agnostic; prompt-card lessons retain their specialized path.
- Interactive category deletion from wordset and vocab-lesson pages always creates or continues the shared wordset-level durable delete job. The job persists before mutation, removes at most 25 linked lessons or words per request by default (hard cap 100), serializes the shared term-meta map with a token-fenced wordset lease plus a rollback-expiring bridge for the previous per-category lock namespace, and commits state in a transaction that locks and revalidates both lease rows. Continuations reconcile remaining rows after interrupted or failed writes, can finalize after the term is already gone, and keep Continue/Retry progress visible until completion. Keep `ll_tools_wordset_page_delete_category_for_wordset()` limited to explicit compatibility or maintenance callers; first-party UI must use `ll_tools_wordset_page_run_category_delete_batch()`.
- Global vocab-lesson settings and scheduled full syncs only queue the durable cleanup/sync reconciliation state; each cron continuation keyset-checks at most 10 existing pages or discovers at most 10 category candidates for one enabled wordset (hard cap 50), persists cursors/counts/failure state, and reschedules until completion. Keep the synchronous `ll_tools_sync_vocab_lesson_pages()` helper out of web/admin/cron entry points.
- Routing maintenance note: wordset and vocab-lesson pretty routes are currently
  registered per enabled wordset. A future scalability migration should add a
  small fixed route shape, preserve or narrowly redirect existing root pretty
  URLs, and keep `/embed/<category>?wordset=<slug>&mode=...` untouched for
  embedded quiz compatibility.
- `/lesson/<slug>` content lesson pages (handled by `includes/pages/content-lesson-pages.php`).
- `/wp-json/ll-tools/v1/...` REST automation routes (handled by `includes/api/automation-rest.php`).
  - Includes status, wordset creation/reports/missing-meta/bulk-update/word-option-rules/review-notes/entity translations, and import preview/start/process/discard/result routes.
  - Word-metadata plan job create/status/process/discard/result routes are registered by `automation-rest.php` and implemented through its loaded `includes/api/word-metadata-plan-rest.php` job helpers.

# Flashcard widget architecture
## PHP controller
- `includes/shortcodes/flashcard-widget.php` builds categories and localizes deferred quiz/bootstrap data into `llToolsFlashcardsData`; a preselected shortcode no longer hydrates its category rows during PHP render.
  - Keep the bootstrap globals single-owner: `llToolsFlashcardsData` is localized once on `ll-tools-flashcard-audio`, while `llToolsFlashcardsMessages` is localized once on `ll-flc-util`. Startup consumers must declare the owning handle as a dependency; do not repeat the same large assignment on main or mode handles because a later copy also overwrites responsive runtime mutations.
  - Data includes category config, wordset scope, user study preferences, and mode UI labels/icons.
  - Editor Hub (`includes/shortcodes/editor-hub-shortcode.php`) reuses much of the same word payload shape and validation helpers for in-browser vocab editing.
- `includes/lib/flashcard-payload-materializer.php` owns the durable full-category protocol:
  - Rows live in `wp_ll_flashcard_payload_rows` (respecting the active WordPress table prefix) with the primary identity `(scope_hash, generation, row_kind, row_id)` and the page index `(scope_hash, generation, sort_group, row_id)`. Schema version publication requires exact required-column, primary-key, and page-index readback after `dbDelta()`. Activation installs the schema; later upgrades may run only from tests, CLI, cron, or a capability-bearing admin request, never from an anonymous page/public-AJAX request.
  - A canonical scope binds source and effective-isolation category identity, sorted wordset IDs, viewer identity, rendered locale, prompt type, option type, `use_titles`, and sign-language mode. Public data uses viewer `0` and is shared only when the category and all requested wordsets are anonymously viewable; private data is isolated by user ID. All scopes permit one wordset by default (filterable to a hard limit of 20), while public scopes retain a second one-wordset default/hard-three guard, with bounded category/wordset membership verification.
  - The dependency signature binds the table/payload/builder schemas, plugin version, canonical scope, structural/content/category epochs, the specific-wrong-answer ownership generation/integrity marker, image-similarity threshold, and masked-proxy mode. A signature change creates a new fenced generation; readers never mix rows or cursors across generations.
  - One worker lease advances a bounded keyset step: primary words default to 100, image-primary work defaults to 20, and prompt cards default to 5. Exact-owner global/scope leases are renewed around byte-bounded reads and writes, state publication uses generation CAS, and transient source/visibility failures remain retryable. Deterministic access denial may terminate the scope.
  - Stored payloads redact speaker user IDs before the database write. Individual rows default to a 512 KiB ceiling (1 MiB hard maximum), SQL chunks default to 1 MiB, and page reads select metadata before fetching only the payload rows admitted by both the row and byte budgets.
  - Completed scopes expire through the bounded recurring cleanup after 30 days without access by default; incomplete scopes expire after 7 days. Cleanup scans at most 20 state options and deletes at most 500 rows per scope per run. It first unpublishes the scope as `retiring`, clears queued rebuilds, and deletes only one captured immutable generation per pass; scheduled workers never recreate missing/retiring state, while a real foreground request may explicitly start a fresh generation. The same global-lease pass keyset-scans at most 100 primary-key metadata rows and may delete one exact generation older than 15 minutes when its coordinator option is absent, covering a former writer that lost its lease immediately after an INSERT without an unbounded anti-join.
- `ll_get_flashcard_payload_page` is registered for authenticated and anonymous AJAX:
  - Requests resolve the canonical category/wordset/presentation scope, accept only an allowlisted rendered locale, and cap `page_size` at 200. Returned pages are ordered keyset slices with `rows`, `next_cursor`, `complete`, `generation`, `total_rows`, `page_rows`, and `page_bytes`.
  - The HMAC cursor binds the scope hash, immutable generation, and last `(sort_group, row_id)`. Tampered cursors fail with `invalid_cursor`; dependency or generation drift returns `restart_required`, and the clients may restart once without combining generations. Each bounded page read holds and renews the scope lease from readiness recheck through payload validation/access touch so cleanup cannot retire its generation mid-read.
  - Cold/incomplete materializations return `cache_warming` with HTTP 429 and `Retry-After`. Anonymous requests retain their separate page budget and one-client in-flight lease. Signed-in progress is overlaid only on the response, never persisted into the shared materialization, and speaker IDs are redacted again at the boundary.
  - `ll_get_words_by_category` remains the bounded explicit-candidate compatibility route. A no-candidate legacy call may return only a complete one-page materialization; larger categories receive `paged_payload_required` instead of falling back to a full synchronous build.
- `includes/flashcard-shell.php` renders the shared overlay shell used by the
  public flashcard shortcode, offline app shell, and quiz-page/vocab-lesson
  popup bootstrap so those surfaces keep the same IDs, mode switcher, result
  controls, and repeat-button startup behavior.

## JS module map (`js/flashcard-widget/`)
- `main.js` - orchestrates quiz lifecycle, mode switching, settings UI, and session guards.
- `state.js` - shared state container and constants.
- `selection.js` - category/word selection, prompt rendering, and star-weighted selection.
- `modes/practice.js`, `modes/learning.js`, `modes/listening.js`, `modes/gender.js`, `modes/self-check.js` - mode-specific flows.
- `audio.js` - playback + `selectBestAudio()` priority logic.
- `loader.js` - wordset-aware preloading and cache management; no-candidate
  category loads drain signed immutable materializer pages, preserve the locale
  that rendered the page, retain loading across warming retries, and restart
  once on a stale cursor without mixing generations.
- `options.js` - option count calculation and layout constraints.
- `cards.js` - card rendering and font sizing.
- `dom.js` - DOM helpers and progress UI.
- `effects.js` - confetti and visual feedback.
- `results.js` - end-of-quiz UI.
- `audio-visualizer.js` - animated loading/listening visualizer.
- `mode-config.js` - merges default mode UI labels/icons with `llToolsFlashcardsData.modeUi`.
- `category-selection.js`, `util.js` - supporting UI/utility helpers.

## Mode behavior (high level)
- Practice mode: standard multiple-choice quiz with adaptive option count.
  - Audio prompt preference: `question` first, then `isolation`, then `introduction` (set in `js/flashcard-widget/modes/practice.js`).
- Learning mode: guided introduction + mastery tracking.
  - Implementation: `js/flashcard-widget/modes/learning.js` + `selection.js`.
  - State highlights: `introducedWordIDs`, `wordIntroductionProgress`, `wordCorrectCounts`, `wrongAnswerQueue` (with `dueTurn`), `learningChoiceCount`, `learningCorrectStreak`.
  - Defaults: `MIN_CHOICE_COUNT` = 2, `MAX_CHOICE_COUNT` = 6, `MIN_CORRECT_COUNT` = 3.
  - Progress UI updated via `Dom.updateLearningProgress()`.
- Listening mode: audio-first playback with simplified UI and visualizer.
  - Implementation: `js/flashcard-widget/modes/listening.js` + `audio-visualizer.js`.
  - Prompt audio preference is isolation-first when available.
  - Uses study prefs (`llToolsStudyPrefs`) to honor star mode and fast transitions.
- Gender mode: adaptive grammar-focused rounds using wordset/category gender support flags.
- Self-check mode: confidence-based review flow that feeds user-progress signals/recommendations.

# Admin tools and workflows
## Core Tools menu pages (files)
- Audio Processor: `includes/admin/audio-processor-admin.php` + `js/audio-processor.js`.
  - Queue, duplicate, and reprocess tabs load 40 rows by default through a nonce/capability-guarded AJAX page, hard-clamped to 25-50 rows. Normal continuation uses a user/tab-bound HMAC cursor over `(post_date|post_modified, ID)` and returns the next `ll_ap_cursor`. Direct cursorless legacy links may use SQL `OFFSET` only through the filterable 5,000-row compatibility ceiling (50,000 hard maximum); deeper or invalid-cursor requests rebase to page one, while a valid signed cursor keeps keyset continuation beyond that ceiling. Keep selection page-local, preserve return tab/page after processing or splitting, and show loading/error/retry/empty states without hydrating the remaining queue.
- Audio Image Matcher: `includes/admin/audio-image-matcher.php`, `templates/audio-image-matcher-template.php`, `js/audio-image-matcher.js`.
  - Candidate images are paged (48 by default, 96 hard maximum), requests and mutations remain serialized, read failures expose loading/error/timeout/retry state, and assignment POSTs stay visibly saving until their authoritative response rather than timing out into an ambiguous duplicate write. Image choices are native buttons with keyboard/focus restoration; global used counts deduplicate canonical and legacy links and are refreshed for both sides of a rematch.
- Missing Audio report: `includes/admin/missing-audio-admin-page.php`.
- Recording Types admin: `includes/admin/recording-types-admin.php`.
- Bulk Translations: `includes/admin/bulk-translation-admin.php` (DeepL + dictionary fallback).
- Bulk Word Import: `includes/admin/bulk-word-import-admin.php` (Turkish casing support).
- Export + Import tools: `includes/admin/export-import.php` (separate admin pages for bundle export and bundle import; zip of categories + word_images + attachments).
- Fix Word Images (legacy): `includes/admin/word-images-fixer.php`.
- Languages admin: `includes/taxonomies/language-taxonomy.php`.
- Word Audio Parent metabox: `includes/admin/metabox-word-audio-parent.php`.
- Word Option Rules: `includes/admin/word-option-rules-admin.php` (option conflict/group editing).
- Duplicate Category Words: `includes/admin/duplicate-category-words-admin.php`.
- Image Aspect Normalizer: `includes/admin/image-aspect-normalizer-admin.php`.
- Image WebP Optimizer: `includes/admin/image-webp-optimizer-admin.php`.
- IPA Keyboard admin: `includes/admin/ipa-keyboard-admin.php`.
- Example Sentence Migration utility: `includes/admin/example-sentence-migration.php`.

## Audio workflow (end to end)
- Recording UI: `[audio_recording_interface]` uses MediaRecorder and category recording type targets.
  Recorder AJAX requests that depend on category configuration must carry the current wordset scope; strict preflight paths reject an omitted or inaccessible scope rather than substituting the default wordset.
- Bulk upload: `[audio_upload_form]` and `[image_upload_form]` allow admin uploads. Shared target-scope controls live in `includes/admin/uploads/upload-scope.php`; keep both forms on that renderer rather than duplicating wordset/category scope markup.
- Processing: Audio Processor runs in browser, uses `lamejs` from CDN for MP3 encoding.
- Storage: `word_audio` posts store `audio_file_path` and `recording_type` terms; parent word published only when audio exists.

# Template override system
- Resolver: `includes/template-loader.php`.
- Search order:
  1. Child theme: `wp-content/themes/<child>/ll-tools/<template>`
  2. Parent theme: `wp-content/themes/<parent>/ll-tools/<template>`
  3. Plugin fallback: `templates/<template>`

# External dependencies and assets
- Feature-scoped CDNs (not global front-end): jQuery UI CSS (code.jquery.com), canvas-confetti (cdn.jsdelivr), lamejs (cdn.jsdelivr).
- DeepL API integration: `includes/admin/api/deepl-api.php`.
- AssemblyAI API integration: `includes/admin/api/assemblyai-api.php`.
- getID3: used for audio validation in `includes/admin/uploads/audio-upload-form.php`.
- Media proxy may fetch remote image URLs via `wp_remote_get()` fallback.

# Contracts and invariants (do not break)
- Capabilities gate admin tools: use `view_ll_tools` or stricter.
- AJAX and POST handlers must verify nonces and capabilities.
- Slugs are public contracts: `words`, `word_images`, `word_audio`, `word-category`, `wordset`, `recording_type`.
- Auto quiz pages rely on `_ll_tools_word_category_id` meta and the generated `ll_quiz_page` CPT with `/quiz/<category>` rewrites; do not create ordinary child Pages for new quiz pages.
- Learning state is client-side only; do not persist it server-side.
- Word publish guard depends on `ll_tools_get_category_quiz_config()` and `ll_tools_quiz_requires_audio()`.
- Use `ll_enqueue_asset_by_timestamp()` and `LL_TOOLS_BASE_*` constants for paths/URLs.
- Template overrides must follow the resolver order in `includes/template-loader.php`.
- Wordset scope is strict: learn/practice/listening flows must never mix words or audio across wordsets; ignore stale AJAX responses from prior wordset/session contexts.
- Wordset isolation is canonical: when code crosses from wordsets to categories, resolve through the effective wordset/category helpers instead of assuming legacy global category ids still apply.
- Wordset-page activity launches and recommendations must enforce a hard minimum pool of 5 available words (after applying session/category filters); do not launch or suggest an activity below that threshold.
- Broad or progress-filtered logged-in wordset selections must launch through the bounded server plan and candidate-specific hydration. A successful plan preserves every matching ID across capped, category-aware transport chunks; practice composes them into one logical session, while an impossible sparse layout returns the typed fail-closed error instead of a partial plan. Direct words reserved only as specific wrong answers remain support rows for option payloads but must not enter plan target membership; a prompt card that canonically answers with the same word remains a valid target. Keep planning nonce- and wordset-access protected and exact for `new`/`studied`/`learned`/`starred`/`hard`; hydrate only the current chunk; hand the verified candidate rows directly to the runtime without refetching them; for practice, continue before intermediate results, preserve cumulative score, progress, replay queues, and logical category scope, and emit completion once after the final chunk; and fail closed without leaving the popup loader busy when planning or hydration is rate-limited.
- Vocab-lesson prompt-card mode detection must use the capped ID-only summary path; full prompt-card rows belong in the deferred grid request, not the initial lesson template render.
- Wordset-page chunking must preserve full coverage of the filtered word pool and distribute words across server-planned transport chunks without duplicates or dropped leftovers (use balanced chunk sizes instead of creating tiny tail chunks that strand words). Keep each category-owned queue contiguous for request efficiency, order category-diverse lighter batches before isolated heavy batches, and order smaller queues first inside each batch so one catch-all category cannot monopolize the opening round. Filtered bounded practice sessions retain their filter label and full logical total across hydration boundaries. If full coverage cannot also satisfy the minimum round size and hard category cap, reject the plan explicitly. Practice must request valid chunks serially just in time, append each verified candidate payload to the active runtime without reinitializing it, suppress intermediate results/actions/completion events, and retry a failed unchanged chunk without advancing; other modes keep explicit results-stage continuation. No mode may refetch a successful chunk or flatten category IDs into one all-category hydration request.
- Flashcard options in practice/learning must never include a conflicting pair (same `option_blocked_ids` pair, same image identity, or linked `similar_word_id`).
- Learning-mode bootstrap should introduce a non-conflicting initial pair when possible so the first round remains distinguishable.
- Keep flashcard row payload fields stable (`image`, `similar_word_id`, `option_groups`, `option_blocked_ids`, `option_image_hash`, `option_image_hash_threshold`, `option_similar_image_allowed_ids`); option safety depends on them in both `ll_get_words_by_category()` candidate responses and materialized pages.
- Learning mode options are built from all introduced categories, so conflict filtering must be evaluated against all currently chosen options (not just the target).
- If conflict filtering leaves fewer cards than the desired option count, keep conflicts blocked (do not force-add conflicting cards).
- All admin and public UI strings should remain i18n-detectable (Loco Translate compatible): wrap PHP/template strings in WordPress i18n helpers using `ll-tools-text-domain`, and pass JS UI copy through localized data/messages instead of hardcoded literals.
- Tier-2 public UI translations are tracked through `languages/tier2-public-ui-sources.php`, the generated `languages/tier2-public-ui-strings.json` manifest, and `scripts/check-public-i18n.php`; mixed-purpose source files must use named PHP symbols or strict anchor-bounded semantic regions so source movement cannot silently add manager/admin strings or remove learner strings.
- Wordset/category/lesson content labels that vary by site UI language use locale-keyed entity translation maps in `ll_tools_entity_translations`; bulk reads/writes go through `/wp-json/ll-tools/v1/wordsets/{wordset}/translations`.
- Public static caches must exclude logged-in users, wp-admin, admin-ajax, REST/API, POST requests, preview/customizer requests, and error/redirect responses; anonymous cache keys should normalize noisy args such as `ll_locale_nonce`, `ll_tools_auth`, and `ll_wordset_back`.
- Public static cache writes must keep the configured max-byte guard, and MISS responses should not receive public cache headers until storage succeeds.
- Anonymous public AJAX surfaces that can rebuild expensive payloads should be cache-aware and resource-guarded: preserve cheap cache hits, but throttle or cap cache misses and oversized batch requests.
- Expired-transient maintenance must remain database-only and cron-only: skip when an external object cache is active, use only audited LL-owned cache/rate-limit prefixes, keep the five-minute grace and hard 200-row/two-second caps, conditionally recheck the exact timeout during pair deletion, and emit aggregate counts/namespaces/bytes without transient keys or values. Timeout-only rows are eligible; active pairs, value-only rows, non-LL transients, and persistent options/jobs are not.
- Named performance profiles own one manifest/history/report tuple. `LL_PERF_SKIP_SEED=1` is read-only and must fail unless the stored fixture version and `canonical-json-v1` checksum match the selected manifest. The parent passes the small stored-fixture JSON to the verifier as an explicit argument because WSL-to-Windows-PHP stdin is not a reliable UTF-8 transport. The child E2E runner must preserve every parent-set locked `LL_E2E_PERF_*` value across env-file loading.
- Pasted bulk word and prompt-audio imports are synchronous small-batch tools: keep both the raw-byte and actionable-row ceilings in place, and use a durable job instead of raising those limits for large imports.
- The legacy `word_english_meaning` translation migration uses an ID keyset cursor and bounded repeat submissions; do not replace it with an all-post migration query.
- The wordset-isolation migration is a forward-only durable state machine in `includes/wordset-isolation.php`: migration version 5 deliberately replays sites already marked by the former version-4 one-request migration, then advances through words, standalone images, wordsets, option rules, lessons, users, and finalization with keyset cursors and a fenced lease. Checkpoints use exact persisted readback/CAS; category and image copies require complete owner/source metadata readback; users use compare-and-swap writes; source words with no wordset are counted and skipped without blocking progress. Category normalization is mode-specific: explicit `word-category` writes keep valid owned assignments independent; a `wordset` assignment expands existing source families only into actually added wordsets and leaves deliberately empty unchanged scopes alone; migration retains complete source-by-wordset expansion. Legacy or out-of-scope rows remap across the active wordsets. Before any user-meta write, preflight every referenced category mapping in selected categories, goals, category progress, prompt-card progress, recommendation queues, last activity, and recommendation deferrals. Global ignored/placement goal lists are category-family data, not preferred-wordset Cartesian scopes: owned IDs validate only in their owner scope, while legacy unowned IDs validate the exact already-materialized family expansion that the bounded goals sanitizer will persist. Deleted category IDs in historical progress may pass only when the production repair helper preserves the complete entry exactly; any normalized change or live-term mapping gap fails the user phase without advancing its cursor. Deferrals retain bounded session word IDs so category remaps can re-key their activity signatures; migration drops only older category-changing deferrals that lack those IDs and therefore cannot be re-keyed exactly. Refresh the lease immediately before publishing the target marker, and publish it only after the final replayable checkpoint succeeds. `admin_init` may only queue bounded cron work; operators resume/retry through the admin notice or `wp ll-tools wordset-isolation-migrate`. Background option-rule repair rejects serialized stores larger than 512 KB; only explicit CLI maintenance may opt into whole-store repair with `--allow-large-option-rules`. Never restore the old one-request full migration.
- Recommendation queues and last activities are bounded, regenerable scheduling hints. Their shared production/migration classifier drops the whole activity only when any referenced category term is definitely absent; lookup errors and all-live activities retain the complete fail-closed mapping check. Queue inspection stops at the sixteen-item schema ceiling: an oversized stored bucket is preserved for diagnosis, returns an empty runtime view for regeneration, and fails migration preflight. Runtime self-repair uses exact-value CAS with user-meta cache eviction and bounded rereads so a concurrent refresh wins; migration re-preflights the exact write baseline and treats any later classifier or complete-remap failure as a hard user-phase failure. This exception must not spread to durable progress, prompt progress, goals, or deferrals.
- Specific-wrong-answer reverse ownership is a durable materialization, not an on-demand full scan. Readers may use it only when payload generation, source epoch, and integrity marker agree. Mutations publish behind the dirty-token CAS fence; stale or incomplete state fails closed and schedules the bounded ID-cursor rebuild. A fresh site may publish an empty map only after repeating the bounded existence probe inside the writer fence.
- Missing or invalid normal-page recommendation queues hydrate category payloads from the materialized wordset category-ID scope in a 12-category window, hard-capped at 24, rotated from the last logical recommendation anchor. A usable existing queue skips that work; incomplete scope, goals, order, or category-build reads must not write a replacement queue.
- Every isolation-migration batch must defer category-generated-page maintenance, discard only the IDs it queued, and preserve any enclosing deferral scope exactly. Only after the completed checkpoint is durably readable may finalization CAS-persist a new migration-owned generated-page reconciliation generation. Its locked coordinator pre-arms retry transport, waits for pre-existing workers, tags complete cursor-zero bounded quiz/vocab passes, repairs stranded child events after locks clear, and retains intent until both exact passes complete. Completion never clears the shared untagged pre-armed event, which may safely no-op or transport a concurrent newer generation; `admin_init` may restore missing transport while intent remains. Never replay immediate whole-wordset vocab counts from the migration loop.
- Wordset main-page category search reads from the durable `wp_ll_wordset_category_search` materialization and stays scoped to allowed categories. Schema v3 keys rows by a durable build generation; expired-lease takeover atomically rotates that generation, readers expose only the completed published generation, and old generations are removed in bounded chunks, so a stalled stale write can never clobber visible replacement rows. Builders advance with an ID keyset in bounded batches, cap source text before PHP hydration, chunk relationship reads and byte/row-limited inserts, renew an exact-owner lease before writes, back off transient failures, stop deterministic failure loops, and publish completion only for the same dependency signature and generation. Queries chunk category/image/detail hydration, preserve locale-independent diacritic matching and deepest-category assignment, and return a retryable preparation state until the generation is complete; the frontend must retain loading/error state and an explicit Retry control rather than treating preparation failure as no matches. Automatic foreground retries stay short and bounded, stop when a result navigation or page hide makes them irrelevant, and pause while a flashcard launch owns the global loader; an unresolved query may resume after that popup closes. Deleting a wordset removes its rows, state, lease, and scheduled event. Staff-only pending-transcription search remains a separate lookup requiring at least three characters: it first selects capped published word IDs in the wordset, then capped published `word_audio` IDs, and only then performs the unindexed text comparison. It may identify matching word/category cards but must not expose transcription text or enter anonymous shared caches.
- Anonymous flashcard and dictionary cache misses use the atomic counters and expiring client leases in `includes/lib/public-ajax-resource-guards.php`; do not replace them with transient get/increment/set admission. Flashcards preserve the 1,000-ID session compatibility ceiling but bound raw candidate parsing, charge candidate scopes in 100-ID units, admit at most two uncached builders per client, and serialize public multi-category frontend hydration while retaining the popup loading state across bounded warming retries. Dictionary live search takes its same-query build lock before charging the client budget, briefly waits for the winning cache builder, admits at most two distinct cold builders per client, and returns a retryable warming payload while retaining the loading state. Before any public dictionary query argument is unslashed, normalized, cached, admitted, recursively traversed, or expanded into SQL, `q`, scope, page, canonical/legacy letter, POS, source, dialect, and entry input must pass hard-clamped raw-byte, value-byte, cardinality, and shape limits. Only scope/POS/source accept bounded multi-values; scalar arrays are rejected. AJAX rejects invalid arguments and ordinary GET/static-cache normalization drops them to safe defaults. Cache hits bypass the cold-build guards.
- Full-category flashcard reads use `ll_get_flashcard_payload_page` and `wp_ll_flashcard_payload_rows`; do not restore a no-candidate `ll_get_words_by_category` whole-category build. Preserve the canonical scope, schema readback, exact-owner leases, generation/signature fences, byte-bounded persistence, signed keyset cursor, locale switch/restore, at-rest speaker redaction, response-only progress overlay, and bounded stale-scope cleanup as one protocol.
- Cacheable anonymous dictionary URLs are deterministic by site-default locale. Browser/cookie-negotiated non-default dictionary traffic must bypass edge caching with no-store headers instead of varying `/sozluk/` by `Accept-Language` or `Cookie`.
- Public dictionary requests should canonicalize noisy browse state early, including dictionary front pages that are excluded from static HTML caching: `ll_dictionary_entry` wins over letter/browse state, `letter` collapses to `ll_dictionary_letter`, internal navigation/auth args such as `ll_wordset_back` and `ll_tools_auth` are stripped from public URLs, and private wordsets/entries must not leak through AJAX or direct-detail fallbacks.
- Public dictionary result cards must batch linked-word counts for the bounded visible entry IDs; keep full linked-word previews separate and capped rather than adding per-entry count queries to page hydration.
- Custom STT endpoints must stay wordset-scoped and validate both saved and request-time URLs against private/reserved hosts or resolved private IP ranges before proxying. Hosted audio reads are bounded before and during file access: the default limit is 10 MiB, the filterable hard ceiling is 25 MiB, and an oversized body returns `stt_audio_too_large` with HTTP 413. Remote response bodies use WordPress HTTP `limit_response_size`, default to 256 KiB, and hard-cap at 1 MiB; cap-reaching, content-length-oversized, truncated, or invalid-JSON responses fail closed. The bounded legacy plain-text response remains opt-in only through `ll_tools_remote_stt_allow_plain_text_response`.
- Automation REST write endpoints must keep server-side throttles/caps, serialized resource guards for expensive writes, and durable result payloads; callers should not be trusted to self-limit bulk mutations on a live site. The legacy raw-account-password Basic compatibility path bounds header/username/password bytes before Base64 decoding or hashing, then reserves a coarse canonical direct-peer allowance and a peer-plus-normalized-login allowance, so oversized input and rotating usernames cannot bypass admission. Successful authentication refunds both reservations; failures remain generic, and exhaustion emits HTTP 429 with `Retry-After`. Cookie and application-password authentication remain preferred and must not be downgraded into that compatibility path.
- REST word-row discovery must page IDs before row hydration. `missing-meta` uses bounded candidate offsets, broad bulk updates persist `scan_after_id` in resume state, and `/report` returns page-scoped counts while the CLI remains the explicit full-report surface. `/report-summary` calculates coverage with aggregate SQL rather than materializing the wordset. Review-note reads default to 100 and cap at 250 rows with deterministic title-plus-ID ordering and continuation metadata. Interlinear list reads default to 50 and cap at 100 rows across both supported post types with one global offset; list responses omit heavy payloads by default, while a specific-lesson read includes its payload unless explicitly disabled. Both list families accept legacy offsets only through the filterable 5,000-row compatibility ceiling (10,000 hard maximum), return HTTP 400 above it, and expose `max_offset` plus `offset_limit_reached` when a caller must narrow its filters.
- Site Sync snapshots are always bounded: omitted, zero, or negative `per_page` values use the 100-row default, positive values are capped at 250, and every response carries continuation metadata. Transcription pages select recording IDs directly through the wordset relationship, and metadata pages hydrate only the requested word page. The Site Sync admin may assemble a complete comparison only by iterating those bounded pages; do not restore an unpaged REST or database-hydration path.
- User-study bootstrap and HTTP responses defer word rows by default. The compatibility word-fetch endpoint requires explicit candidate IDs (maximum 200) across at most three categories; raw scalar/array inputs must be truncated before parsing. Analytics word hydration separately defaults to and hard-caps at 250 rows when words are requested; a missing or zero limit is not an unbounded opt-in. Progress reset discovery advances in 500-ID pages (hard maximum 1,000) and deletes stored progress in 300-ID chunks. Preserve these bounds when adding reset or analytics callers.
- Treat REST automation as the control plane and server-side jobs/WP-CLI as the execution plane for heavy bulk work. New operations that touch hundreds of records and perform expensive validation, media handling, taxonomy repair, cache rebuilding, or cross-post recomputation should expose dry-run/readback/status/result surfaces and process bounded chunks with durable cursors instead of relying on one long synchronous HTTP request.
- Import confirmation always enters the durable import job, including direct admin-post submissions; do not restore the legacy synchronous full-zip fallback.
- Recent Imports is a bounded summary surface: cap displayed categories and matching lesson links, and include wordset constraints in the lesson query rather than filtering an unbounded result in PHP.
- Offline export/sync payloads must preserve wordset scoping, quiz configuration, media proxy expectations, and prompt-card metadata needed by the shared flashcard runtime. Offline authentication sessions live in an InnoDB table as one indexed row per `(user_id, session_key)` in `wp_ll_tools_offline_sessions`, with hashed secrets, exact column/index/prefix validation, expiry/activity indexes, transactional eight-session eviction, and bounded cleanup. Authentication touch and revocation are fenced by the exact secret hash so a replacement token cannot be accepted or deleted; logout authenticates without extending expiry. Legacy serialized user-meta sessions are imported lazily and their exact raw snapshot is CAS-deleted only after exact row/hash readback succeeds; legacy exact-key revocation also uses bounded CAS retries and preserves concurrent or hash-replaced sessions. A concurrent old-code writer, failed import, or conflict must retain source data and fail closed. The eight-session allowance supports independent devices/browser profiles, not eight progress branches; progress events still merge through the existing sync protocol.
- Offline bundle preparation must reject a compressed input larger than 2 GiB before constructing `AdmZip` or replacing the prepared workspace; code-level overrides remain hard-capped at 4 GiB. Keep the existing entry-count, per-entry, total-uncompressed, traversal, and symlink validation before extraction.
- Deferred normal vocab lessons use a category-targeted cached count, render up to six bounded content-aware shell cards, and add lightweight image-sized shimmer cards up to a 60-card initial DOM ceiling. The cold count keeps specific wrong-answer candidate discovery scoped to the lesson category. If the exact expected category count is larger, one `+N` remainder card communicates the rest without creating unbounded nodes or hydrating additional words, recordings, or media. Large standard learner grids must continue through the serial order-materialization and signed page-cursor path; do not restore one full HTML response or an unbounded all-word/media probe. The text-answer media decision compares targeted all-word and image-backed aggregates so every page uses one category-wide presentation. Prompt-card lessons keep their separate bounded three-card shell.
- Staff inactive-category preview preparation advances word-image conversion in bounded ID-keyset batches (10 by default, 25 hard maximum per continuation) behind an exact-owner renewable lease. The cursor advances only after complete success or an idempotently terminal disappearance; retryable failures stay on the same image. JavaScript continues one serial batch while exposing polite progress; no-JavaScript requests persist the cursor and can be resumed. Do not restore the former all-image query/mutation loop. The published lesson-category map remains a complete semantic map: cold rebuilds are single-owner, a complete seven-day last-known-good payload returns immediately under contention, and a truly cold contended build fails closed instead of starting a duplicate scan. The winning cold builder still enumerates all published lessons; replace that proportional pass with durable materialization only if production measurements justify the added state machinery.
- The offline export admin page must lazy-load category options for the selected wordset through its guarded AJAX endpoint; do not rebuild an inline all-wordset category map during initial render.
- Offline STT accepts at most 15 seconds of 16 kHz mono inference audio. Keep browser blob/duration checks and the Android PCM byte, Java sample, and JNI sample ceilings aligned; the native boundary remains authoritative.
- Frontend teacher-class `admin-post.php` actions must account for limited-role redirect handling so teachers are not bounced to the site home after valid class actions.
- Teacher-class admin rendering must page classes, account options, and learner progress before hydration. Membership is still stored as serialized bidirectional ID arrays; replacing that contract requires a staged data migration, not an interactive full-class scan.

# UI color standards (canonical)
Use one shared status palette across user-facing plugin UI so progress states always mean the same thing.

## Canonical status colors
- Learned / success green (dark): `#15803d`
- In-progress / primary blue (dark): `#1d4d99`
- New / neutral gray (dark): `#64748b`

## Supporting light tint colors
- Learned tint: `#d7f8dd`
- In-progress tint: `#d8e8ff`
- Neutral tint: `#eceff3`

## Secondary action colors
- Caution / close / partial: `#D39A00`
- Wrong / hard / danger: `#C64545`

## Source of truth tokens
- `css/wordset-pages.css` in `.ll-wordset-page`:
  - `--ll-wp-pill-green-text` (`#15803d`)
  - `--ll-wp-pill-blue-text` (`#1d4d99`)
  - `--ll-wp-pill-slate-text` (`#64748b`)
  - Light tints: `--ll-wp-pill-green`, `--ll-wp-pill-blue`, `--ll-wp-pill-slate`

## Required usage rules moving forward
- Do not introduce near-duplicate "standard" shades for learned/in-progress/new states.
- Prefer the canonical tokens above; if CSS variables are not available in that scope, use the exact canonical hex values.
- Status mappings must stay consistent:
  - Learned/success/check/right/know -> green `#15803d`
  - In-progress/primary/focus/audio visualizer -> blue `#1d4d99`
  - New/neutral/inactive state chips -> dark gray `#64748b`
  - Close/partial -> yellow `#D39A00`
  - Wrong/hard/danger -> red `#C64545`
- Inline SVG mode icons that must keep fixed colors across themes should set explicit stroke/fill colors (and use inline `style` when needed) rather than relying only on `currentColor`.

## Review checklist for UI color changes
- Confirm no old conflicting shades were reintroduced.
- Confirm new user-facing status colors map to the canonical palette above.
- Confirm related surfaces (wordset page, flashcard modes, self-check) stay aligned.

# Common tasks (file pointers)
- Register/adjust CPTs or taxonomies: `includes/post-types/*.php`, `includes/taxonomies/*.php`.
- Update quiz mode logic: `js/flashcard-widget/modes/*.js`, `js/flashcard-widget/selection.js`.
- Modify quiz UI: `templates/flashcard-widget-template.php`, `css/flashcard/*.css`.
- Adjust auto quiz pages: `includes/pages/quiz-pages.php`, `templates/quiz-page-template.php`.
- Edit embed behavior: `includes/pages/embed-page.php`.
- Tune audio/image matching: `includes/lib/ll-matching.php`, `includes/lib/image-match-index.php`, `includes/admin/audio-image-matcher.php`, `includes/admin/uploads/audio-upload-form.php`.
- Adjust recording interface: `includes/shortcodes/audio-recording-shortcode.php`, `js/audio-recorder.js`, `css/recording-interface.css`.
- Change audio processing: `includes/admin/audio-processor-admin.php`, `js/audio-processor.js`, `css/audio-processor.css`.
- Modify user study state/recommendations: `includes/user-study.php`, `includes/user-progress.php`, `js/wordset-pages.js`.
- Update settings/options: `includes/admin/settings.php`.

# Search hints (ripgrep)
- `register_post_type( 'words'` / `register_post_type( 'word_audio'`.
- `register_taxonomy('word-category'` / `register_taxonomy('wordset'` / `register_taxonomy('recording_type'`.
- `_ll_tools_word_category_id` for auto quiz pages.
- `ll_tools_get_category_quiz_config` and `ll_can_category_generate_quiz` for quiz eligibility.
- `ll_tools_get_active_wordset_id` for default wordset logic.
- `ll_get_words_by_category` for quiz data payloads.
- `llToolsFlashcardsData` for front-end localization data.
- `audio_recording_interface` for recording UI.
- `ll_user_study_` for study preferences.
- `ll_tools_get_masked_image_url` for signed image proxy.
