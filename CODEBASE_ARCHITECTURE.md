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
  - includes/lib/word-option-rules.php
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
- Prefer ID-only queries, capped launch pools, pagination, cached/materialized aggregates, and explicit admin batch jobs with progress behavior for operations that intentionally need the whole wordset.
- Main wordset category rows must discard categories already proven ineligible before building content summaries, bulk-prime the exact candidate term, lesson, and wrong-answer-owner sets, and trust the exact membership established by flat `word-category` queries instead of re-querying each candidate word. Preserve the bounded initial-card/lazy-card split.
- The browser still needs the complete category registry for selection, sorting, search placeholders, and launch configuration, but that registry must be sparse: omit values supplied by the JavaScript normalizer or top-level wordset context. Lazy category shells are ordered `{type, id}` references into that registry; do not duplicate category rows in the shell list. Content-lesson shells retain their bounded title/excerpt/media metadata because unloaded-content search uses it.
- Metric-dependent saved main-category sorts (`progress-*` and `recent-*`) must not trigger full-wordset metric hydration while analytics is deferred. Keep the bounded initial cards and lazy offsets in canonical/default order, preserve the saved sort key for the browser, and reorder only after the summary-only per-category analytics aggregate arrives.
- Settings routes should enqueue only the runtime they use: plain settings tools and the hub skip the main wordset-page monolith and locale sorter; `study`, `editor`, and `recorder-queues` retain them; `advanced` retains its dedicated manager/media/autocomplete assets and locale sorter; confetti remains main/progress-only. The settings hub reads only the stored values displayed in its Advanced summary card; category-ordering catalogs, font discovery, and answer-option preview sampling belong exclusively to the opened Advanced tool.
- Recorder category overviews use the same bounded compact summary pipeline in both the manager and `[audio_recording_interface]`: server-render lightweight category shells, hydrate authenticated batches through `ll_tools_recorder_queue_summaries` (twenty categories maximum), remove categories whose completed summary has no queued work, and keep queued counts/previews synchronized with the selector. The manager remains a selected-recorder stream with six initial summaries; keeping that initial budget distinct from the larger hydration batches preserves quick first use without paying dozens of sequential WordPress bootstrap round trips. The stream generation is structural: it keys the ordered category identities plus recorder/wordset/filter scope, while each category summary has its own content signature. Ordinary word/audio/image/prompt changes invalidate only affected cards and must not restart an otherwise valid 20-category stream; true category identity/order/scope changes still reload it. The no-category shortcode bootstrap must derive its initial selector and queue from the cached structural summary catalog; do not reintroduce the legacy uncached relationship-wide category scans before loading that same catalog. Focused-category and hidden queue item views stay paged; do not replace any of these surfaces with full-queue hydration.
- Focused recorder pages apply limits after recording eligibility across canonical word/image candidates, legacy missing-audio rows, and prompt cards in that order. Raw scans must be query-budgeted and resumable, and later pages plus same-page continuations must carry the prior keyset/offset instead of rescanning the source prefix. Browser-visible cursors are short-lived HMAC tokens bound to the viewer, target recorder, wordset, category, filters, requested page, page size, and structural/recording-type epochs; never accept raw candidate IDs or offsets from request data. Both token segments must use canonical unpadded Base64URL so padding-bit aliases are rejected even when they decode to the same bytes. Do not bind cursors to ordinary audio or hide mutations: recorders consume and hide items between lazy batches, so the stable raw cursor must survive those expected eligibility changes. An invalid, expired, tampered, or context-mismatched supplied token must explicitly rebase to page one with `cursor_rebased` and `reset_queue`; never fall back to mutable numeric offsets. An empty bounded batch with `has_more` remains continuable (with repeat-token protection), and nonincremental same-page navigation must carry cumulative `page_items` so earlier legacy or prompt rows do not disappear. Never advertise `has_more` when a signed token cannot be produced: keep the bounded items, terminate automatic continuation, and expose `continuation_unavailable` for explicit recovery. Client continuation requests are category-generation scoped; a late response from a previously selected category must be discarded without mutating the new queue or pagination. Compact summaries must reject unrenderable image rows before filling preview slots and remain incomplete while a bounded refill could still find useful work.
- Recorder queue summary caches use each word-category's canonical cache version plus a small shared signature for recorder-hidden entries, recording-type taxonomy changes, and global desired-recording defaults. Word, image, prompt-card, audio, and recording-type relationship mutations must bump only their affected category versions; the virtual uncategorized summary conservatively retains the broader plugin epochs because it has no category identity. A matching completed summary stays reusable for its retention TTL (one day by default), without an age-only five-minute rebuild. The compact structural category catalog keeps its separate structure key.
- Expired database transients are reclaimed only by the hourly LL Tools maintenance job in `includes/lib/expired-transient-maintenance.php`: it skips external object-cache installs, selects at most 200 timeout rows from an exact LL-owned cache/rate-limit prefix allowlist after a five-minute grace period, and conditionally deletes each exact timeout/value pair only while the selected timeout remains expired. Do not broaden it to a global transient purge, persistent `ll_tools_*` options/jobs, request-path cleanup, or unbounded batches.

# Entry points and runtime flow
- `language-learner-tools.php`
  - Defines `LL_TOOLS_BASE_URL`, `LL_TOOLS_BASE_PATH`, `LL_TOOLS_MAIN_FILE`, `LL_TOOLS_MIN_WORDS_PER_QUIZ`.
  - Registers GitHub update checker (`main` only accepts packaged GitHub release assets; `dev` stays branch-based).
  - Activation adds `view_ll_tools`, seeds default wordset and recording page via transients.
  - Registers `/embed/<category>` rewrite + query var + template_include hook.
- `includes/bootstrap.php`
  - Loads all CPTs, taxonomies, roles, admin tools, pages, shortcodes, API wrappers, utilities, and vendor update checker.
  - Also loads shared quiz/data helpers like `includes/lib/word-option-rules.php`, `includes/lib/internal-review-notes.php`, `includes/lib/expired-transient-maintenance.php`, `includes/user-progress.php`, `includes/privacy.php`, `includes/login-window.php`, and `includes/teacher-classes.php`.
- `includes/assets.php`
  - `ll_enqueue_asset_by_timestamp()` enqueues local JS/CSS with `filemtime` versioning.
  - Public enqueue provides shared base LL Tools styles; feature-specific libraries (jQuery UI autocomplete, canvas-confetti) are enqueued on demand by the features that use them.
  - Non-admin style lives in `css/non-admin-style.css`.
- `includes/pages/quiz-pages.php`
  - Registers the generated `ll_quiz_page` CPT and creates one `/quiz/<category>` record per `word-category` (meta `_ll_tools_word_category_id`).
  - Migrates legacy generated WP Page children into the CPT, keeps legacy records discoverable during migration, and hides any remaining generated Page records from the normal Pages admin list.
  - Syncs on category/content changes; daily and on file mtime change; manual cleanup in admin.
  - Uses `templates/quiz-page-template.php` and `js/quiz-pages.js`.
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
- `includes/lib/media-proxy.php`
  - Signed image proxy (`lltools-img`, `lltools-size`, `lltools-sig`) to hide filenames.

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
- includes/user-roles/wordset-manager.php
- includes/user-roles/ll-tools-editor.php
- includes/user-roles/learner-role.php
- includes/user-roles/audio-recorder-role.php
- includes/user-roles/teacher-role.php
- includes/admin/uploads/audio-upload-form.php
- includes/admin/uploads/image-upload-form.php
- includes/user-progress.php
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
- includes/shortcodes/word-grid-shortcode.php
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
  user-progress.php           # Learner progress writes/lookups
  user-progress-report-data.php # Shared progress report queries
  wordset-isolation.php       # Wordset-owned category isolation/remapping
  wordset-templates.php       # Reusable wordset template bundles
  lib/
    php-compat.php            # Compatibility helpers for older PHP runtimes
    expired-transient-maintenance.php # Bounded hourly cleanup for expired LL-owned database transients
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
    image-hash.php            # Perceptual image hashing/similarity helpers
  api/
    automation-rest.php       # JSON-first automation endpoints with server-side guardrails
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
    editor-hub-shortcode.php
    site-tools-shortcode.php  # [ll_site_tools] sitewide settings + maintenance UI
    word-audio-shortcode.php
    wordset-page-shortcode.php
    wordset-buttons-shortcode.php
    audio-credit-grid-shortcode.php
    audio-recording-shortcode.php
    image-copyright-grid-shortcode.php
    language-switcher-shortcode.php
    dictionary-shortcode.php  # [ll_dictionary] + legacy dictionary shortcode aliases
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
  content-lesson-player.js
  content-lesson-admin.js
  word-audio.js
  manage-wordsets.js
  bulk-category-edit.js
  dictionary-shortcode.js
  editor-hub.js
  export-import-admin.js
  login-window.js
  frontend-utility-menu.js
  public-viewport-guard.js
css/
  language-learner-tools.css
  quiz-pages.css
  quiz-pages-style.css
  recording-interface.css
  audio-processor.css
  audio-image-matcher.css
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
  - Lesson kind defaults to `standard`; `corpus_text` suppresses empty audio/video chrome and renders text-document payloads from the interlinear payload meta.
  - Text-document payloads support public Text, Interlinear, and Sources views from `reading_units`, `source_lines`, `witnesses`, `display_rows`, and regular interlinear `tokens`; ordinary learning-content interlinears remain staff-gated.
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
- `[word_grid]` (`includes/shortcodes/word-grid-shortcode.php`).
  - Bulk POS and grammar changes use owner-scoped durable operations in `includes/lib/word-grid-bulk-operations.php`. Each request persists a `preparing` batch before creating its non-autoloaded rollback chunk, revalidates the saved target IDs against the current lesson scope, lease-fences state writes, verifies mutation readback, and only then advances the keyset cursor. Status/Continue/Undo survive reload; a generic recovery row keeps Undo reachable when a grammar feature is later disabled. Undo runs newest chunk first, verifies restoration before retiring a chunk, and skips only rows whose relevant state changed outside the recorded bulk/failed-write states. Operations expire after one day by default (filterable from one hour through seven days), and token cleanup deletes rollback chunks in bounded scheduled batches. Do not restore browser-owned rollback snapshots or unbounded one-request bulk mutations.
- `[word_audio]` (`includes/shortcodes/word-audio-shortcode.php`, JS: `js/word-audio.js`).
- `[wordset_page]` / `[ll_wordset_page]` (`includes/shortcodes/wordset-page-shortcode.php`).
- `[wordset_buttons]` / `[ll_wordset_buttons]` (`includes/shortcodes/wordset-buttons-shortcode.php`).
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

# Flashcard widget architecture
## PHP controller
- `includes/shortcodes/flashcard-widget.php` builds categories, initial words, and localizes JS data into `llToolsFlashcardsData`.
  - Keep the bootstrap globals single-owner: `llToolsFlashcardsData` is localized once on `ll-tools-flashcard-audio`, while `llToolsFlashcardsMessages` is localized once on `ll-flc-util`. Startup consumers must declare the owning handle as a dependency; do not repeat the same large assignment on main or mode handles because a later copy also overwrites responsive runtime mutations.
  - Data includes category config, wordset scope, user study preferences, and mode UI labels/icons.
  - Editor Hub (`includes/shortcodes/editor-hub-shortcode.php`) reuses much of the same word payload shape and validation helpers for in-browser vocab editing.
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
- `loader.js` - preloading and cache management (wordset aware).
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
- Audio Image Matcher: `includes/admin/audio-image-matcher.php`, `templates/audio-image-matcher-template.php`, `js/audio-image-matcher.js`.
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
- Bulk upload: `[audio_upload_form]` and `[image_upload_form]` allow admin uploads.
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
- Vocab-lesson prompt-card mode detection must use the capped ID-only summary path; full prompt-card rows belong in the deferred grid request, not the initial lesson template render.
- Wordset-page chunking must preserve full coverage of the filtered word pool and distribute words across chunks without dropping leftovers (use balanced chunk sizes instead of creating tiny tail chunks that strand words).
- Flashcard options in practice/learning must never include a conflicting pair (same `option_blocked_ids` pair, same image identity, or linked `similar_word_id`).
- Learning-mode bootstrap should introduce a non-conflicting initial pair when possible so the first round remains distinguishable.
- Keep `ll_get_words_by_category()` payload fields stable (`image`, `similar_word_id`, `option_groups`, `option_blocked_ids`); option safety depends on them.
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
- Every isolation-migration batch must defer category-generated-page maintenance, discard only the IDs it queued, and preserve any enclosing deferral scope exactly. Only after the completed checkpoint is durably readable may finalization CAS-persist a new migration-owned generated-page reconciliation generation. Its locked coordinator pre-arms retry transport, waits for pre-existing workers, tags complete cursor-zero bounded quiz/vocab passes, repairs stranded child events after locks clear, and retains intent until both exact passes complete. Completion never clears the shared untagged pre-armed event, which may safely no-op or transport a concurrent newer generation; `admin_init` may restore missing transport while intent remains. Never replay immediate whole-wordset vocab counts from the migration loop.
- Wordset main-page category search indexing should stay scoped to allowed categories. The public index prunes words that have no allowed category while preserving the deepest-category assignment rule. Staff-only pending-transcription search is a separate lookup requiring at least three characters: it first selects capped published word IDs in the wordset, then capped published `word_audio` IDs, and only then performs the unindexed text comparison. It may identify matching word/category cards but must not expose transcription text or enter anonymous shared caches. Any larger on-demand search migration must preserve diacritic-insensitive matching and hidden-selection cleanup.
- Cacheable anonymous dictionary URLs are deterministic by site-default locale. Browser/cookie-negotiated non-default dictionary traffic must bypass edge caching with no-store headers instead of varying `/sozluk/` by `Accept-Language` or `Cookie`.
- Public dictionary requests should canonicalize noisy browse state early, including dictionary front pages that are excluded from static HTML caching: `ll_dictionary_entry` wins over letter/browse state, `letter` collapses to `ll_dictionary_letter`, internal navigation/auth args such as `ll_wordset_back` and `ll_tools_auth` are stripped from public URLs, and private wordsets/entries must not leak through AJAX or direct-detail fallbacks.
- Public dictionary result cards must batch linked-word counts for the bounded visible entry IDs; keep full linked-word previews separate and capped rather than adding per-entry count queries to page hydration.
- Custom STT endpoints must stay wordset-scoped and validate both saved and request-time URLs against private/reserved hosts or resolved private IP ranges before proxying.
- Automation REST write endpoints must keep server-side throttles/caps, serialized resource guards for expensive writes, and durable result payloads; callers should not be trusted to self-limit bulk mutations on a live site.
- REST word-row discovery must page IDs before row hydration. `missing-meta` uses bounded candidate offsets, broad bulk updates persist `scan_after_id` in resume state, and `/report` returns page-scoped counts while the CLI remains the explicit full-report surface.
- Treat REST automation as the control plane and server-side jobs/WP-CLI as the execution plane for heavy bulk work. New operations that touch hundreds of records and perform expensive validation, media handling, taxonomy repair, cache rebuilding, or cross-post recomputation should expose dry-run/readback/status/result surfaces and process bounded chunks with durable cursors instead of relying on one long synchronous HTTP request.
- Import confirmation always enters the durable import job, including direct admin-post submissions; do not restore the legacy synchronous full-zip fallback.
- Recent Imports is a bounded summary surface: cap displayed categories and matching lesson links, and include wordset constraints in the lesson query rather than filtering an unbounded result in PHP.
- Offline export/sync payloads must preserve wordset scoping, quiz configuration, media proxy expectations, and prompt-card metadata needed by the shared flashcard runtime. Offline authentication sessions live in an InnoDB table as one indexed row per `(user_id, session_key)` in `wp_ll_tools_offline_sessions`, with hashed secrets, exact column/index/prefix validation, expiry/activity indexes, transactional eight-session eviction, and bounded cleanup. Authentication touch and revocation are fenced by the exact secret hash so a replacement token cannot be accepted or deleted; logout authenticates without extending expiry. Legacy serialized user-meta sessions are imported lazily and their exact raw snapshot is CAS-deleted only after exact row/hash readback succeeds; legacy exact-key revocation also uses bounded CAS retries and preserves concurrent or hash-replaced sessions. A concurrent old-code writer, failed import, or conflict must retain source data and fail closed. The eight-session allowance supports independent devices/browser profiles, not eight progress branches; progress events still merge through the existing sync protocol.
- Deferred normal vocab lessons use a category-targeted cached count, render up to six bounded content-aware shell cards, and add lightweight image-sized shimmer cards up to a 60-card initial DOM ceiling. The cold count keeps specific wrong-answer candidate discovery scoped to the lesson category. If the exact expected category count is larger, one `+N` remainder card communicates the rest without creating unbounded nodes or hydrating additional words, recordings, or media. Prompt-card lessons keep their separate bounded three-card shell.
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
